<?php
declare(strict_types=1);

/*
 * The Consultant — he isn't part of the office: he knows the externals the
 * office relies on but doesn't make itself, tells whether they are there,
 * what they are good for and how to install them by hand — and, second offer,
 * installs them himself.
 *
 *   fcp     Fix Common Problems, a plugin (checks the server for common
 *           mistakes)
 *   filesviewer  Files Viewer, a plugin (browse and manage files in Unraid —
 *           the office has no file browser of its own on purpose)
 *   kopia   a Kopia container (Mr. Backupsy hands it the offsite copies;
 *           how it must be set up he checks himself)
 *   streamviewer  Stream Viewer, a plugin (who watches what on Emby,
 *           Jellyfin or Plex) — only suggested where one of them runs
 *   unbalanced  a plugin that moves files between array disks — optional:
 *           never suggested (it can get in the way of backups, EmbyCache
 *           and the gather), but there for whoever needs it; never installed
 *           by him
 *
 * Monitoring (group 'monitoring', optional — the office works without it),
 * in the order it is set up:
 *   nodeexporter  measures the server and serves the numbers on port 9100 —
 *           and reads text files from a folder (textfile collector): that is
 *           where the office's own numbers go, ADVISOR_METRICS_DIR (RAM, the
 *           office's add-on place, written by the agent). Recommended: the
 *           container (official image, template "Node-Exporter"), which sees
 *           the host read-only under /host; ich777's plugin counts too
 *   prometheus  fetches and keeps the numbers (port 9090)
 *   grafana     shows them (port 3000); the office's own dashboard to import
 *               is monitoring/grafana-dashboard.json (ADVISOR_DASHBOARD_URL)
 *   loki        later: log lines, for the night watchman's log book
 *
 * Reading (refresh): whether a plugin is installed, whether a container
 * exists and runs (by image, not by name), where its web page is, whether
 * Kopia is connected to a repository (only the non-secret facts of its
 * repository.config), whether Grafana gets the office's data source and
 * dashboard. Cheap and cached in the state.
 *
 * Kept current (no confirmation: it is the office's own file): the office's dashboard in Grafana's
 * provisioning, ADVISOR_DASHBOARD_REL — only that file, only while it is a plain file whose uid is the
 * office's, replaced when this version's dashboard differs (advisorDashboardKeep(), at his scan and
 * hourly while Grafana runs). Grafana reloads provisioned files by itself and doesn't save provisioned
 * dashboards, so nothing of the user's is lost; the data source and provider files stay as they are.
 *
 * Installing — never behind the user's back, always preview + confirm:
 *   plugins     Unraid's own `plugin install <url>` as an atd job (the URLs
 *               pinned here: the makers' repositories, as Community
 *               Applications has them), its output shown; never for one that
 *               is there
 *   containers  a prepared template (public/desks/advisor/templates/<id>.xml,
 *               from the Community Applications template) in RAM, and Unraid's
 *               own "Add Container" form opened with it — the user sees every
 *               field and clicks Apply; Unraid writes my-<Name>.xml and makes
 *               the container as if it came from Apps. Never over an existing
 *               container or template. Files the container needs at its first
 *               start (prometheus.yml, Grafana's provisioning) are written
 *               beforehand, only where there are none.
 *   Kopia's repository  the user's keys and password reach the agent only
 *               through RAM (officeInboxDir(), src/api.php), go to Kopia only
 *               on its stdin and are kept nowhere by the office (HARDENING.md)
 *
 * The office never logs into a web page or an HTTP API of an external: it
 * configures them through files and command lines, at install time.
 */

const ADVISOR_EXTERNALS = [
    // 'plg': the plugin's address (the maker's repository, as Community Applications has it) — he installs only these
    'fcp'          => ['plugin' => 'fix.common.problems', 'author' => 'Lime Technology',
                       'plg' => 'https://raw.githubusercontent.com/unraid/fix.common.problems/master/plugins/fix.common.problems.plg'],
    'filesviewer'  => ['plugin' => 'filesviewer', 'author' => 'Lazaros Chalkidis',
                       'plg' => 'https://raw.githubusercontent.com/Lazaros-Chalkidis/unraid-filesviewer/main/filesviewer.plg'],
    // 'template': the container's name in his template (the one Community Applications gives it)
    'kopia'        => ['container' => 'kopia', 'template' => 'kopia'],         // image or name contains it
    'streamviewer' => ['plugin' => 'streamviewer', 'media' => true, 'author' => 'Lazaros Chalkidis',
                       'plg' => 'https://github.com/Lazaros-Chalkidis/unraid-streamviewer/raw/main/streamviewer.plg'],
    'unbalanced'   => ['plugin' => 'unbalanced', 'optional' => true],
    // 'image': the image's own name (no registry, owner, tag) or the container's name matches
    'nodeexporter' => ['plugin' => 'prometheus_node_exporter', 'image' => '/^node[-_]exporter$/i', 'optional' => true, 'group' => 'monitoring',
                       'template' => 'Node-Exporter'],
    'prometheus'   => ['image' => '/^prometheus$/i', 'optional' => true, 'group' => 'monitoring', 'template' => 'prometheus'],
    'grafana'      => ['image' => '/^grafana(-oss|-enterprise)?$/i', 'optional' => true, 'group' => 'monitoring', 'template' => 'Grafana'],
    'loki'         => ['image' => '/^loki$/i', 'optional' => true, 'group' => 'monitoring', 'later' => true],
];
/** Where the office's own numbers go for the node exporter's textfile collector (RAM; *.prom files, lib/metrics.php writes them) */
const ADVISOR_METRICS_DIR = METRICS_HOST_DIR;
/** The office's dashboard for Grafana, to import (monitoring/grafana-dashboard.json on main) */
const ADVISOR_DASHBOARD_URL = 'https://raw.githubusercontent.com/' . OFFICE_REPO . '/main/monitoring/grafana-dashboard.json';
/** …and the same file in this installation, for Grafana's provisioning (absent when the package doesn't ship it) */
define('ADVISOR_DASHBOARD_FILE', OFFICE_DIR . '/monitoring/grafana-dashboard.json');
/** ich777's node exporter plugin takes its start options from here (start_parameters=…) */
const ADVISOR_NODE_PLUGIN_CFG = '/boot/config/plugins/prometheus_node_exporter/settings.cfg';
const ADVISOR_MEDIA = ['emby' => 'Emby', 'jellyfin' => 'Jellyfin', 'plex' => 'Plex'];

/** His templates (from Community Applications', see each file's head) and where the prepared ones wait for Unraid's form (RAM) */
define('ADVISOR_TEMPLATE_DIR', OFFICE_WEB . '/desks/advisor/templates');
const ADVISOR_PREPARED_DIR   = RUN_DIR . '/templates';
const ADVISOR_USER_TEMPLATES = '/boot/config/plugins/dockerMan/templates-user';
/** Unraid's "Add Container" form; xmlTemplate=default:<file> is how Community Applications opens it (CreateDocker.php takes any file) */
const ADVISOR_ADD_CONTAINER  = '/Docker/AddContainer?xmlTemplate=default:';
/** Kopia: the office's snapshots read-only at /uso (its sources are named after it), restores into /uso-restore (Mr. Restori) */
define('ADVISOR_SNAPSHOTS', '/mnt/addons/' . BACKUP_OFFICE_SHARE . '/snapshots');
define('ADVISOR_RESTORE', '/mnt/user/' . BACKUP_OFFICE_SHARE . '/restore/kopia');
const ADVISOR_KOPIA_TARGET   = '/uso';
const ADVISOR_RESTORE_TARGET = '/uso-restore';
/** The mark on what he prepared (a label in the template; detection goes by image all the same) */
const ADVISOR_LABEL = 'uso.installed-by';
/** Grafana's provisioning inside its mapped appdata folder, the data source's uid the dashboard uses */
const ADVISOR_GRAFANA_DATA = '/var/lib/grafana';
const ADVISOR_GRAFANA_PROV = ADVISOR_GRAFANA_DATA . '/provisioning';
const ADVISOR_DS_UID       = 'uso-prometheus';
/** The office's dashboard in Grafana's provisioning (kept current, advisorDashboardKeep()) and its uid; how often he looks */
const ADVISOR_DASHBOARD_REL   = 'dashboards/uso/unraid-secretary-office.json';
const ADVISOR_DASHBOARD_UID   = 'unraid-secretary-office';
const ADVISOR_DASHBOARD_EVERY = 3600;
/** The plugin manager, and his one plugin job at a time (RAM: <job>.json, .out, .done) */
const ADVISOR_PLUGIN_BIN = '/usr/local/sbin/plugin';
const ADVISOR_JOB        = RUN_DIR . '/advisor-plugin';
const ADVISOR_JOB_SCRIPT = '"$1" install "$2" >"$3" 2>&1; echo $? >"$4"';

/** Prometheus' minimal configuration: itself and the Node Exporter, every 60 s ({ip} = the server) */
const ADVISOR_PROMETHEUS_YML = <<<'YML'
global:
  scrape_interval: 60s

scrape_configs:
  - job_name: prometheus
    static_configs:
      - targets: ['localhost:9090']
  - job_name: node
    static_configs:
      - targets: ['{ip}:9100']
YML;

/**
 * Kopia gets the secrets on stdin only — never on a command line (ps), never
 * in a file. Fixed text; the non-secret arguments follow as "$@".
 */
const ADVISOR_KOPIA_SH = 'IFS= read -r KOPIA_PASSWORD || exit 64; IFS= read -r AWS_ACCESS_KEY_ID || exit 64; '
    . 'IFS= read -r AWS_SECRET_ACCESS_KEY || exit 64; export KOPIA_PASSWORD AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY; '
    . 'exec kopia repository "$@"';
/** S3 providers offered in the page (only remembered for the recovery sheet; Kopia takes endpoint and region) */
const ADVISOR_S3_PROVIDERS = ['s3', 'aws', 'b2', 'r2', 'mega', 'wasabi', 'hetzner', 'idrive', 'minio'];

desk('advisor', [
    'start'   => fn () => advisorScan(),
    'fit'     => fn () => fit(true, 'yes'),
    'tick'    => fn () => advisorTick(),
    'actions' => [
        'refresh'          => fn (array $r) => ['ok' => true, 'state' => advisorScan()],
        // read only: what an install would do, a plugin job's output
        'install_preview'  => fn (array $r) => ['ok' => true, 'plan' => advisorInstallPublic(advisorInstallPlan(advisorId($r), advisorEnv(), $r))],
        'provision_preview' => fn (array $r) => ['ok' => true, 'plan' => advisorProvisionPublic(advisorProvisionPlan(advisorEnv()))],
        'job'              => fn (array $r) => ['ok' => true, 'job' => advisorJob()],
        // writing (after a preview and a confirmation on the page)
        'install_prepare'  => fn (array $r) => advisorInstallPrepare(advisorId($r), $r, advisorEnv()) + ['state' => advisorScan()],
        'provision'        => fn (array $r) => advisorProvision(advisorEnv()) + ['state' => advisorScan()],
        'plugin_install'   => fn (array $r) => advisorPluginInstall(advisorId($r)),
        'kopia_repo'       => fn (array $r) => advisorKopiaRepo($r),
    ],
    // Kopia: Mr. Backupsy's checks say what he needs from it
    'checks'  => fn () => array_merge(
        [finding('fcp', 'recommended', housePlugin(ADVISOR_EXTERNALS['fcp']['plugin']), [], 'apps'),
         finding('filesviewer', 'recommended', housePlugin(ADVISOR_EXTERNALS['filesviewer']['plugin']), [], 'apps')],
        ($media = advisorMedia()) ? [finding('streamviewer', 'recommended', housePlugin('streamviewer'), ['media' => $media], 'apps')] : [],
    ),
]);

/** The media server that runs here (Emby, Jellyfin, Plex — by container image or name), or null */
function advisorMedia(): ?string
{
    foreach (houseContainers() as $c) {
        foreach (ADVISOR_MEDIA as $match => $label) {
            if (stripos($c['image'] . ' ' . $c['name'], $match) !== false) {
                return $label;
            }
        }
    }
    return null;
}

function advisorScan(): array
{
    $plugins = housePlugins();
    $containers = houseContainers();
    $media = advisorMedia();
    $env = advisorEnv($containers);
    $externals = [];
    foreach (ADVISOR_EXTERNALS as $id => $how) {
        if (!empty($how['media']) && $media === null) {
            continue;                       // no media server, nothing to watch
        }
        $common = ['optional' => !empty($how['optional'])] + array_filter(['group' => $how['group'] ?? null, 'later' => !empty($how['later'])]);
        $p = isset($how['plugin']) ? ($plugins[$how['plugin']] ?? null) : null;
        if ($p !== null || !isset($how['container']) && !isset($how['image'])) {
            $externals[$id] = ['kind' => 'plugin', 'there' => $p !== null, 'version' => $p['version'] ?? null,
                'icon' => $p !== null ? advisorPluginIcon($how['plugin']) : null] + $common
                + (isset($how['plg']) && $p === null ? ['offer' => true, 'author' => $how['author'], 'plg' => $how['plg']] : []);
            continue;
        }
        $found = advisorFindContainer($how, $containers);
        $x = ['kind' => 'container', 'there' => $found !== null, 'name' => $found['name'] ?? null,
            'image' => $found['image'] ?? null, 'running' => $found['running'] ?? false,
            'icon' => $found !== null ? advisorContainerIcon($found['name']) : null] + $common;
        if ($found !== null) {
            $inspect = houseInspect($found['name']);
            $x['webui'] = $inspect ? advisorWebUi($inspect, $env['ip']) : null;
            $x['by_consultant'] = ($inspect['Config']['Labels'][ADVISOR_LABEL] ?? '') === 'consultant';
            if ($id === 'nodeexporter') {
                $x['textfile'] = advisorNodeTextfile($x, $inspect);
            } elseif ($id === 'kopia' && $inspect) {
                $x['kopia'] = advisorKopiaInfo($found, $inspect);
            } elseif ($id === 'grafana' && $inspect) {
                $g = advisorGrafanaInfo($found, $inspect);
                $x['grafana'] = advisorGrafanaPublic($g);
                advisorDashboardCurrent($g);
            }
        } elseif (isset($how['template']) && empty($how['later'])) {
            $refuse = advisorInstallRefusal($id, $how['template'], $env, false);
            $x['offer'] = ['template' => $how['template'], 'refuse' => $refuse];
        }
        $externals[$id] = $x;
    }
    if (isset($externals['nodeexporter']) && $externals['nodeexporter']['kind'] === 'plugin') {
        $externals['nodeexporter']['textfile'] = advisorNodeTextfile($externals['nodeexporter'], null);
    }
    $state = ['time' => time(), 'gui' => houseGuiUrl(), 'ip' => $env['ip'], 'media' => $media, 'metrics_dir' => ADVISOR_METRICS_DIR,
              'dashboard' => ADVISOR_DASHBOARD_URL, 'prometheus_yml' => ADVISOR_PROMETHEUS_YML, 'externals' => $externals,
              'server' => hostname(), 'job' => advisorJob()];
    writeAtomic(deskFile('advisor'), jsonEncode($state));
    return $state;
}

/**
 * Every 60 s: secrets nobody took within two minutes go (the web side removes its own; this is for a
 * request cut off half-way). Every ADVISOR_DASHBOARD_EVERY: the office's dashboard in Grafana kept current.
 */
function advisorTick(): void
{
    static $last = 0, $dash = 0;
    if (time() - $last < 60) {
        return;
    }
    $last = time();
    advisorInboxSweep(officeInboxDir(), 120);
    if (time() - $dash >= ADVISOR_DASHBOARD_EVERY) {
        $dash = time();
        $found = advisorFindContainer(ADVISOR_EXTERNALS['grafana'], houseContainers());
        $inspect = $found !== null && $found['running'] ? houseInspect($found['name']) : null;
        if ($inspect !== null) {
            advisorDashboardCurrent(advisorGrafanaInfo($found, $inspect));
        }
    }
}

/**
 * The office's dashboard in this Grafana's provisioning, kept current — only while Grafana runs (then
 * its folder is awake). A file unchanged since the last look (and this version's dashboard the same)
 * isn't read again. What happened is logged once.
 */
function advisorDashboardCurrent(array $g): void
{
    static $seen = [], $told = [];
    if (empty($g['running']) || $g['host'] === null || !is_file(ADVISOR_DASHBOARD_FILE)) {
        return;
    }
    $path = advisorUnraidPath($g['host'] . '/' . ADVISOR_DASHBOARD_REL);
    clearstatcache(true, $path);
    $st = @lstat($path);
    $src = @stat(ADVISOR_DASHBOARD_FILE);
    $key = $st ? implode(':', [$st['ino'], $st['size'], $st['mtime'], $src['size'] ?? 0, $src['mtime'] ?? 0]) : null;
    if ($key === null || ($seen[$path] ?? null) === $key) {
        return;                 // none there (only the consultant's provisioning puts it there), or as last time
    }
    try {
        $r = advisorDashboardKeep($path, advisorDashboardJson(ADVISOR_DASHBOARD_FILE));
    } catch (Throwable $e) {
        $r = 'failed';
    }
    clearstatcache(true, $path);
    $st = @lstat($path);
    $seen[$path] = $st && $r !== 'failed' ? implode(':', [$st['ino'], $st['size'], $st['mtime'], $src['size'] ?? 0, $src['mtime'] ?? 0]) : null;   // failed: again next time
    if ($r === 'updated') {
        logLine("Consultant: the office's dashboard in Grafana brought up to date ($path)");
    } elseif (in_array($r, ['foreign', 'failed'], true) && !isset($told["$path:$r"])) {
        $told["$path:$r"] = true;
        logLine("Consultant: the office's dashboard in Grafana left as it is ($path: " . ($r === 'foreign' ? 'not the office\'s file any more' : 'could not be replaced') . ')');
    }
}

/**
 * Replaces the office's provisioned dashboard ($path) with $want when they differ — only while it is a
 * plain file (no link) in a real folder whose JSON carries the office's uid; atomically (a new file
 * beside it, then rename), keeping its owner, group and mode. Never creates one.
 *
 * @return string  updated | same | absent | foreign | none (no dashboard to give) | failed
 */
function advisorDashboardKeep(string $path, ?string $want): string
{
    if ($want === null) {
        return 'none';
    }
    clearstatcache(true, $path);
    $st = @lstat($path);
    if (!$st) {
        return 'absent';
    }
    $dir = @lstat(dirname($path));
    if (($st['mode'] & 0170000) !== 0100000 || !$dir || ($dir['mode'] & 0170000) !== 0040000 || $st['size'] > 8 << 20) {
        return 'foreign';
    }
    $have = @file_get_contents($path, false, null, 0, 8 << 20);
    if ($have === false) {
        return 'failed';
    }
    $j = json_decode($have);
    if (!$j instanceof stdClass || ($j->uid ?? null) !== ADVISOR_DASHBOARD_UID) {
        return 'foreign';
    }
    if ($have === $want) {
        return 'same';
    }
    $tmp = writeNewFile(dirname($path) . '/.' . basename($path), $want, $st['mode'] & 0777);
    if ($tmp === null) {
        return 'failed';
    }
    @lchown($tmp, (int) $st['uid']);
    @lchgrp($tmp, (int) $st['gid']);
    @chmod($tmp, $st['mode'] & 0777);
    clearstatcache(true, $path);
    $now = @lstat($path);
    if (!$now || $now['ino'] !== $st['ino'] || ($now['mode'] & 0170000) !== 0100000 || !@rename($tmp, $path)) {
        @unlink($tmp);           // changed meanwhile: left alone, looked at again next time
        return 'failed';
    }
    return 'updated';
}

/**
 * The container of an external, a running one first: 'container' — image or
 * name contains it; 'image' — a pattern for the image's own name or the
 * container's name (so grafana/loki is Loki, not Grafana)
 *
 * @param array<string, array{name:string, image:string, running:bool}> $containers
 */
function advisorFindContainer(array $how, array $containers): ?array
{
    $found = null;
    foreach ($containers as $c) {
        $hit = isset($how['image'])
            ? preg_match($how['image'], advisorImageName($c['image'])) === 1 || preg_match($how['image'], $c['name']) === 1
            : stripos($c['image'] . ' ' . $c['name'], $how['container']) !== false;
        if ($hit && ($found === null || $c['running'] && !$found['running'])) {
            $found = $c;
        }
    }
    return $found;
}

/** An image's own name: quay.io/prometheus/node-exporter:latest-distroless → node-exporter */
function advisorImageName(string $image): string
{
    $image = preg_replace('/@sha256:[0-9a-f]+$/i', '', $image);
    $last = substr($image, (int) strrpos('/' . $image, '/'));
    return strtolower(preg_replace('/:[^:]*$/', '', $last));
}

/**
 * Whether the node exporter reads the office's folder (its textfile
 * collector): true / false, null when that can't be told. The container: its
 * arguments (Post Arguments) through its mounts; ich777's plugin: its start
 * options. Read only.
 */
function advisorNodeTextfile(array $x, ?array $inspect): ?bool
{
    if (!$x['there']) {
        return null;
    }
    if ($x['kind'] === 'plugin') {
        $cfg = (string) @file_get_contents(ADVISOR_NODE_PLUGIN_CFG);
        $line = preg_match('/^start_parameters=(.*)$/m', $cfg, $m) ? trim($m[1], " \t\r\"'") : '';
        return in_array(ADVISOR_METRICS_DIR, advisorTextfileDirs(preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY), null), true);
    }
    if ($inspect === null) {
        return null;
    }
    $mounts = array_map(fn ($m) => [(string) ($m['Source'] ?? ''), (string) ($m['Destination'] ?? '')], $inspect['Mounts'] ?? []);
    return in_array(ADVISOR_METRICS_DIR, advisorTextfileDirs(array_map('strval', $inspect['Args'] ?? []), $mounts), true);
}

/**
 * The host folders a node exporter's textfile collector reads, from its
 * arguments (--collector.textfile.directory, may be given more than once).
 * $mounts: a container's [host, container] paths — a folder is found through
 * the deepest mount that holds it (/host/mnt/… with / → /host is /mnt/…), one
 * outside every mount is left out; null: it runs on the host itself.
 *
 * @param list<string> $args
 * @param list<array{0:string,1:string}>|null $mounts
 * @return list<string>
 */
function advisorTextfileDirs(array $args, ?array $mounts): array
{
    $dirs = [];
    foreach ($args as $n => $a) {
        if (preg_match('/^--collector\.textfile\.directory=(.+)$/', $a, $m)) {
            $dirs[] = $m[1];
        } elseif ($a === '--collector.textfile.directory' && isset($args[$n + 1])) {
            $dirs[] = $args[$n + 1];
        }
    }
    $out = [];
    foreach ($dirs as $dir) {
        $dir = advisorNormPath(trim($dir, "\"'"));
        if ($mounts === null) {
            $out[] = $dir;
            continue;
        }
        $host = advisorHostPath($mounts, $dir);
        if ($host !== null) {
            $out[] = $host;
        }
    }
    return array_values(array_unique($out));
}

/** /a//b/./c/ → /a/b/c */
function advisorNormPath(string $p): string
{
    return '/' . implode('/', array_filter(explode('/', $p), fn ($s) => $s !== '' && $s !== '.'));
}

/**
 * Where a path inside a container lies on the host, through the deepest
 * mount that holds it; null outside every mount.
 *
 * @param list<array{0:string,1:string}> $mounts  [host, container]
 */
function advisorHostPath(array $mounts, string $inside): ?string
{
    $dir = advisorNormPath($inside);
    $best = null;
    foreach ($mounts as [$host, $target]) {
        $target = advisorNormPath($target);
        $under = $target === '/' || $dir === $target || str_starts_with($dir, $target . '/');
        if ($host !== '' && $under && ($best === null || strlen($target) > strlen($best[1]))) {
            $best = [$host, $target];
        }
    }
    return $best === null ? null : advisorNormPath($best[0] . '/' . substr($dir, $best[1] === '/' ? 0 : strlen($best[1])));
}

const ADVISOR_DOCROOT = '/usr/local/emhttp';

/** An installed plugin's icon as Unraid's Plugins page finds it: the .plg's icon=, else plugins/<name>/(images/)<name>.png */
function advisorPluginIcon(string $name): ?string
{
    $plg = (string) @file_get_contents("/boot/config/plugins/$name.plg");
    $tries = [];
    if (preg_match('/<PLUGIN\b[^>]*\sicon="([^"&]+\.(?:png|svg|jpg))"/is', $plg, $m)) {
        $tries[] = "plugins/$name/" . ltrim($m[1], '/');
    }
    $short = preg_split('/[._ -]/', $name)[0];
    array_push($tries, "plugins/$name/images/$name.png", "plugins/$name/$name.png", "plugins/$name/images/$short.png", "plugins/$name/$short.png");
    foreach ($tries as $path) {
        if (!str_contains($path, '..') && is_file(ADVISOR_DOCROOT . "/$path")) {
            return "/$path";
        }
    }
    return null;
}

/** A container's icon from Unraid's Docker page (it keeps a copy of the template's icon) */
function advisorContainerIcon(string $name): ?string
{
    $path = 'state/plugins/dynamix.docker.manager/images/' . $name . '-icon.png';
    return preg_match('/^[\w.-]+$/D', $name) && is_file(ADVISOR_DOCROOT . "/$path") ? "/$path" : null;
}

// ===================================================================== a container's facts

/** @return list<array{0:string,1:string,2:bool}> a container's binds: [host, container, writable] */
function advisorMounts(array $inspect): array
{
    $out = [];
    foreach ((array) ($inspect['Mounts'] ?? []) as $m) {
        if (is_array($m) && ($m['Type'] ?? 'bind') === 'bind') {
            $out[] = [rtrim((string) ($m['Source'] ?? ''), '/') ?: '/', rtrim((string) ($m['Destination'] ?? ''), '/') ?: '/', !empty($m['RW'])];
        }
    }
    return $out;
}

/** @return array<string,string> a container's environment */
function advisorEnvOf(array $inspect): array
{
    $env = [];
    foreach ((array) ($inspect['Config']['Env'] ?? []) as $e) {
        [$k, $v] = explode('=', (string) $e, 2) + [1 => ''];
        $env[$k] = $v;
    }
    return $env;
}

/**
 * Where a container's web page is, the way Unraid's Docker page builds it from
 * the template's WebUI (label net.unraid.docker.webui): [IP] the server's
 * address where the port is published or the network is the host's, else the
 * container's own (br0 & co.); [PORT:n] the published port.
 */
function advisorWebUi(array $inspect, ?string $ip): ?string
{
    $tpl = (string) ($inspect['Config']['Labels']['net.unraid.docker.webui'] ?? '');
    if ($tpl === '') {
        return null;
    }
    $mode = (string) ($inspect['HostConfig']['NetworkMode'] ?? '');
    $bindings = (array) ($inspect['HostConfig']['PortBindings'] ?? []);
    $own = null;
    foreach ((array) ($inspect['NetworkSettings']['Networks'] ?? []) as $n) {
        if (is_array($n) && !empty($n['IPAddress'])) {
            $own = (string) $n['IPAddress'];
            break;
        }
    }
    $published = false;
    $url = (string) preg_replace_callback('/\[PORT:(\d+)\]/', function ($m) use ($bindings, &$published) {
        $host = (string) ($bindings[$m[1] . '/tcp'][0]['HostPort'] ?? '');
        $published = $published || $host !== '';
        return $host !== '' ? $host : $m[1];
    }, $tpl);
    $addr = $mode === 'host' || $published || $own === null ? $ip : $own;
    if ($addr === null) {
        return null;
    }
    $url = str_replace('[IP]', $addr, $url);
    return preg_match('#^https?://[A-Za-z0-9.:\[\]-]+(?:[/?][^\s"<>]*)?$#D', $url) ? $url : null;
}

/**
 * Kopia: where it sees the snapshots and restores, its config folder, its
 * writable folders (for a repository in a folder), and whether it is
 * connected to a repository — from its repository.config, only the facts
 * that are no secret (storage type, bucket, endpoint, region, prefix, folder,
 * user@host). Read only while the container runs: then its config folder is
 * awake (Kopia writes its log there).
 */
function advisorKopiaInfo(array $found, array $inspect): array
{
    $mounts = advisorMounts($inspect);
    $config = null;
    $sources = $restore = null;
    $folders = [];
    foreach ($mounts as [$host, $target, $rw]) {
        if ($target === '/config') {
            $config = $host;
        } elseif ($host === ADVISOR_SNAPSHOTS) {
            $sources = ['host' => $host, 'target' => $target, 'rw' => $rw];
        } elseif ($rw && str_starts_with($host, '/mnt/') && (stripos($target, 'restore') !== false || stripos(basename($host), 'restore') !== false)) {
            $restore ??= ['host' => $host, 'target' => $target];      // as Mr. Restori finds it
        } elseif ($rw && str_starts_with($host, '/mnt/') && $target !== '/cache') {
            $folders[] = ['host' => $host, 'target' => $target];
        }
    }
    $env = advisorEnvOf($inspect);
    return [
        'config'  => $config,
        'sources' => $sources,
        'restore' => $restore,
        'folders' => $folders,
        'root'    => ($env['PUID'] ?? '0') === '0',
        'repo'    => $found['running'] && $config !== null ? advisorKopiaRepoFacts("$config/repository.config") : null,
    ];
}

/** The non-secret facts of a Kopia repository.config, ['connected' => false] without one */
function advisorKopiaRepoFacts(string $file): array
{
    clearstatcache(true, $file);
    if (!is_file($file)) {
        return ['connected' => false];
    }
    $j = json_decode((string) @file_get_contents($file, false, null, 0, 1 << 16), true);
    $s = is_array($j['storage']['config'] ?? null) ? $j['storage']['config'] : [];
    $str = fn ($v) => is_string($v) && $v !== '' && strlen($v) <= 512 && !preg_match('/[\x00-\x1f\x7f]/', $v) ? $v : null;
    $user = $str($j['username'] ?? null);
    $host = $str($j['hostname'] ?? null);
    return ['connected' => true, 'type' => $str($j['storage']['type'] ?? null)] + array_filter([
        'bucket' => $str($s['bucket'] ?? null), 'endpoint' => $str($s['endpoint'] ?? null), 'region' => $str($s['region'] ?? null),
        'prefix' => $str($s['prefix'] ?? null), 'path' => $str($s['path'] ?? null),
        'client' => $user !== null && $host !== null ? "$user@$host" : null,
    ], fn ($v) => $v !== null);
}

/**
 * Grafana: where it reads its provisioning (GF_PATHS_PROVISIONING, the image's
 * own /etc/grafana/provisioning unless set), whether that is a folder on the
 * server, and where the office's files would go: there, or — while it points
 * elsewhere — into <its data folder>/provisioning, read once
 * GF_PATHS_PROVISIONING points to /var/lib/grafana/provisioning.
 */
function advisorGrafanaInfo(array $found, array $inspect): array
{
    $mounts = array_map(fn ($m) => [$m[0], $m[1]], advisorMounts($inspect));
    $env = advisorEnvOf($inspect);
    $prov = advisorNormPath($env['GF_PATHS_PROVISIONING'] ?? '/etc/grafana/provisioning');
    $provHost = advisorHostPath($mounts, $prov);
    $dataHost = advisorHostPath($mounts, ADVISOR_GRAFANA_DATA);
    $points = $provHost !== null;
    $inside = $points ? $prov : ADVISOR_GRAFANA_PROV;                 // the folder in Grafana's eyes
    $host = $points ? $provHost : ($dataHost !== null ? $dataHost . '/provisioning' : null);
    $rels = $host !== null && $found['running'] ? advisorGrafanaRels(is_file(ADVISOR_DASHBOARD_FILE)) : [];
    $there = [];
    foreach ($rels as $rel) {
        clearstatcache(true, "$host/$rel");
        $there[$rel] = file_exists("$host/$rel") || is_link("$host/$rel");
    }
    return ['name' => $found['name'], 'running' => $found['running'], 'provisioning' => $prov, 'points' => $points,
            'inside' => $inside, 'host' => $host, 'want' => ADVISOR_GRAFANA_PROV, 'there' => $there];
}

/** What the page shows of advisorGrafanaInfo() */
function advisorGrafanaPublic(array $g): array
{
    return ['provisioning' => $g['provisioning'], 'points' => $g['points'], 'host' => $g['host'], 'want' => $g['want'],
            'done' => $g['there'] ? !in_array(false, $g['there'], true) : null];
}

// ===================================================================== installing a container

/** The external a request means — one he can install */
function advisorId(array $r): string
{
    $id = $r['id'] ?? null;
    if (!is_string($id) || !isset(ADVISOR_EXTERNALS[$id])) {
        throw new Problem('ad_unknown', ['id' => is_string($id) ? substr($id, 0, 40) : '?']);
    }
    return $id;
}

/** The server's address (from Unraid's network settings) */
function advisorServerIp(): ?string
{
    $gui = houseGuiUrl();
    return $gui !== null && preg_match('#^https?://(\d+\.\d+\.\d+\.\d+)#', $gui, $m) ? $m[1] : null;
}

/**
 * Where things are, in one place so the tests can point elsewhere: Unraid's
 * appdata (Settings → Docker), the server's address, his templates, Unraid's
 * user templates, the RAM folder for prepared templates, the containers.
 */
function advisorEnv(?array $containers = null): array
{
    $cfg = readCfg('/boot/config/docker.cfg');
    $appdata = rtrim((string) ($cfg['DOCKER_APP_CONFIG_PATH'] ?? ''), '/') ?: '/mnt/user/appdata';
    return [
        'appdata'        => $appdata,
        'ip'             => advisorServerIp(),
        'templates'      => ADVISOR_TEMPLATE_DIR,
        'user_templates' => ADVISOR_USER_TEMPLATES,
        'prepared'       => ADVISOR_PREPARED_DIR,
        'containers'     => $containers ?? houseContainers(),
        'dashboard'      => ADVISOR_DASHBOARD_FILE,
        'snapshots'      => ADVISOR_SNAPSHOTS,
        'restore'        => ADVISOR_RESTORE,
        'metrics'        => ADVISOR_METRICS_DIR,
        'uid'            => FILE_UID,
        'gid'            => FILE_GID,
    ];
}

/**
 * His template for an external, filled in: {{APPDATA}} … with the server's
 * values (XML-escaped), his comment at the top left out. Throws when the file
 * is missing or doesn't come out as the container it should be.
 *
 * @return array{xml:string, name:string, repository:string, network:string, extra:string, post:string, webui:string,
 *               config:list<array{name:string, target:string, mode:string, type:string, value:string, mask:bool}>}
 */
function advisorTemplate(string $id, array $env, array $opts = []): array
{
    $want = ADVISOR_EXTERNALS[$id]['template'] ?? null;
    $raw = $want !== null ? @file_get_contents($env['templates'] . "/$id.xml") : false;
    if (!is_string($raw)) {
        throw new Problem('ad_no_template', ['id' => $id]);
    }
    $values = ['APPDATA' => $env['appdata'], 'IP' => (string) ($env['ip'] ?? ''), 'SNAPSHOTS' => $env['snapshots'],
               'RESTORE' => $env['restore'], 'METRICS' => $env['metrics'], 'ANON' => !empty($opts['anon']) ? 'true' : 'false'];
    $xml = (string) preg_replace('/<!--.*?-->\s*/s', '', $raw);
    $xml = (string) preg_replace_callback('/\{\{([A-Z]+)\}\}/',
        fn ($m) => array_key_exists($m[1], $values) ? htmlspecialchars($values[$m[1]], ENT_XML1 | ENT_QUOTES, 'UTF-8') : $m[0], $xml);
    $doc = !preg_match('/\{\{[A-Z]+\}\}/', $xml) ? @simplexml_load_string($xml) : false;
    if (!$doc || (string) $doc->Name !== $want) {
        throw new Problem('ad_no_template', ['id' => $id]);
    }
    $config = [];
    foreach ($doc->Config as $c) {
        $config[] = ['name' => (string) $c['Name'], 'target' => (string) $c['Target'], 'mode' => (string) $c['Mode'],
                     'type' => (string) $c['Type'], 'value' => (string) $c, 'mask' => (string) $c['Mask'] === 'true'];
    }
    return ['xml' => $xml, 'name' => $want, 'repository' => (string) $doc->Repository, 'network' => (string) $doc->Network,
            'extra' => (string) $doc->ExtraParams, 'post' => (string) $doc->PostArgs, 'webui' => (string) $doc->WebUI, 'config' => $config];
}

/**
 * Why Unraid's form must not be opened for this external, or null: it is
 * there (by image), a container of that name exists (Unraid would replace it
 * on Apply), a template of that name exists (Unraid would write over it), no
 * appdata, no address where the container needs one. $look = false: without
 * looking at appdata itself (the scan: no disk is touched).
 *
 * @return array{key:string, params:array}|null
 */
function advisorInstallRefusal(string $id, string $name, array $env, bool $look = true): ?array
{
    $found = advisorFindContainer(ADVISOR_EXTERNALS[$id], $env['containers']);
    if ($found !== null) {
        return ['key' => 'ad_there', 'params' => ['name' => $found['name']]];
    }
    foreach ($env['containers'] as $c) {
        if (strcasecmp($c['name'], $name) === 0) {
            return ['key' => 'ad_name_taken', 'params' => ['name' => $c['name']]];
        }
    }
    foreach (glob($env['user_templates'] . '/my-*.xml') ?: [] as $file) {
        if (strcasecmp(basename($file), "my-$name.xml") === 0) {
            return ['key' => 'ad_template_taken', 'params' => ['file' => basename($file), 'name' => $name]];
        }
    }
    if (!preg_match('#^/[A-Za-z0-9._/ -]+$#D', $env['appdata']) || str_contains($env['appdata'], '..') || ($look && !is_dir($env['appdata']))) {
        return ['key' => 'ad_appdata', 'params' => ['path' => $env['appdata']]];
    }
    if (in_array($id, ['prometheus', 'grafana'], true) && empty($env['ip'])) {
        return ['key' => 'ad_no_ip', 'params' => []];
    }
    return null;
}

/**
 * What installing a container external does: the template (filled in) and
 * the files it needs at its first start — each with whether it is there
 * already (then it stays as it is).
 *
 * @return array{id:string, name:string, template:array, files:list<array{path:string, kind:string, content:string, there:bool}>, refuse:?array, dashboard:bool}
 */
function advisorInstallPlan(string $id, array $env, array $opts = []): array
{
    if (!isset(ADVISOR_EXTERNALS[$id]['template']) || !empty(ADVISOR_EXTERNALS[$id]['later'])) {
        throw new Problem('ad_unknown', ['id' => $id]);
    }
    $t = advisorTemplate($id, $env, $opts);
    $refuse = advisorInstallRefusal($id, $t['name'], $env);
    $files = [];
    $dashboard = true;
    if ($id === 'prometheus') {
        $files[] = ['path' => $env['appdata'] . '/prometheus/etc/prometheus.yml', 'kind' => 'yml',
                    'content' => str_replace('{ip}', (string) $env['ip'], ADVISOR_PROMETHEUS_YML) . "\n"];
    } elseif ($id === 'grafana') {
        $all = advisorGrafanaFiles((string) $env['ip'], true, ADVISOR_GRAFANA_PROV, $env['dashboard']);
        $dashboard = isset($all['dashboards/uso/unraid-secretary-office.json']);
        foreach ($all as $rel => [$kind, $content]) {
            $files[] = ['path' => $env['appdata'] . "/grafana/provisioning/$rel", 'kind' => $kind, 'content' => $content];
        }
    }
    foreach ($files as &$f) {
        clearstatcache(true, $f['path']);
        $f['there'] = file_exists($f['path']) || is_link($f['path']);
    }
    unset($f);
    return ['id' => $id, 'name' => $t['name'], 'template' => $t, 'files' => $files, 'refuse' => $refuse, 'dashboard' => $dashboard];
}

/** What the page shows of a plan: the template's fields (masked ones without a value), the files without their content */
function advisorInstallPublic(array $plan): array
{
    $t = $plan['template'];
    return [
        'id' => $plan['id'], 'name' => $plan['name'], 'refuse' => $plan['refuse'], 'dashboard' => $plan['dashboard'],
        'repository' => $t['repository'], 'network' => $t['network'], 'extra' => $t['extra'], 'post' => $t['post'],
        'config' => array_map(fn ($c) => ['name' => $c['name'], 'target' => $c['target'], 'mode' => $c['mode'], 'type' => $c['type'],
                                          'value' => $c['mask'] ? '' : $c['value'], 'mask' => $c['mask']], $t['config']),
        'files' => array_map(fn ($f) => ['path' => $f['path'], 'kind' => $f['kind'], 'there' => $f['there']], $plan['files']),
    ];
}

/**
 * Prepares Unraid's "Add Container" form: writes what the container needs
 * at its first start (only what isn't there), puts the filled-in template
 * into RAM and answers the form's address. Creates no container: the user
 * does, with Apply in Unraid's form.
 */
function advisorInstallPrepare(string $id, array $r, array $env): array
{
    $plan = advisorInstallPlan($id, $env, $r);
    if ($plan['refuse'] !== null) {
        throw new Problem($plan['refuse']['key'], $plan['refuse']['params']);
    }
    $written = $kept = [];
    if ($id === 'prometheus') {
        // the container runs as 99:100 (its --user) and writes into data/
        advisorDirs($env['appdata'], ['prometheus', 'prometheus/etc', 'prometheus/data'], $env['uid'], $env['gid']);
    } elseif ($id === 'grafana') {
        advisorDirs($env['appdata'], ['grafana', 'grafana/provisioning', 'grafana/provisioning/datasources',
                                      'grafana/provisioning/dashboards', 'grafana/provisioning/dashboards/uso'], $env['uid'], $env['gid']);
    }
    foreach ($plan['files'] as $f) {
        if (advisorWriteIfAbsent($f['path'], $f['content'], $env['uid'], $env['gid'])) {
            $written[] = $f['path'];
        } else {
            $kept[] = $f['path'];
        }
    }
    if (!is_dir($env['prepared']) && !@mkdir($env['prepared'], 0700, true)) {
        throw new Problem('ad_write', ['path' => $env['prepared']]);
    }
    $file = $env['prepared'] . '/' . $plan['name'] . '.xml';
    writeAtomic($file, $plan['template']['xml'], 0644, 0, 0);
    if (!preg_match('#^/[A-Za-z0-9/_.-]+\.xml$#D', $file)) {       // the form cuts its path at ; | & ? = (unscript)
        throw new Problem('ad_write', ['path' => $file]);
    }
    logLine("Consultant: prepared Unraid's form for {$plan['name']}" . ($written ? ' (wrote ' . implode(', ', $written) . ')' : ''));
    return ['ok' => true, 'url' => ADVISOR_ADD_CONTAINER . $file, 'name' => $plan['name'], 'written' => $written, 'kept' => $kept];
}

/**
 * Unraid's exclusive shares (a share on one pool only): /mnt/user/<share> is then a symlink to
 * /mnt/<pool>/<share> (nostromo's appdata). That one link — Unraid's own, exactly this shape, the
 * same share name, a real folder behind it — is followed: the path comes back on the pool. Any
 * other link stays refused by the callers (nothing of his is written through one).
 */
function advisorUnraidPath(string $path): string
{
    if (!preg_match('#^/mnt/user/([^/]+)(/.*)?$#D', $path, $m)) {
        return $path;
    }
    $share = "/mnt/user/{$m[1]}";
    clearstatcache(true, $share);
    if (!is_link($share)) {
        return $path;
    }
    $to = (string) @readlink($share);
    if (!preg_match('#^(?:\.\./|/mnt/)([^/]+)/([^/]+)/?$#D', $to, $t) || $t[2] !== $m[1] || in_array($t[1], ['user', 'user0', 'addons', 'remotes', 'disks', 'rootshare'], true)) {
        return $path;
    }
    $real = "/mnt/{$t[1]}/{$m[1]}";
    $st = @lstat($real);
    return ($st && ($st['mode'] & 0170000) === 0040000) ? $real . ($m[2] ?? '') : $path;
}

/**
 * Creates the folders $subs below $base (which must exist) where missing —
 * 0755, nobody:users like Unraid makes a container's paths — and refuses
 * when one of them (or $base) is a link: nothing of his is written through
 * one.
 */
function advisorDirs(string $base, array $subs, int $uid, int $gid): void
{
    $base = advisorUnraidPath($base);
    clearstatcache();
    $st = @lstat($base);
    if (!$st || ($st['mode'] & 0170000) !== 0040000) {
        throw new Problem('ad_appdata', ['path' => $base]);
    }
    foreach ($subs as $sub) {
        $dir = "$base/$sub";
        $st = @lstat($dir);
        if ($st && ($st['mode'] & 0170000) !== 0040000) {
            throw new Problem('ad_link', ['path' => $dir]);
        }
        if (!$st) {
            if (!@mkdir($dir, 0755)) {
                throw new Problem('ad_write', ['path' => $dir]);
            }
            @lchown($dir, $uid);
            @lchgrp($dir, $gid);
        }
    }
}

/**
 * Writes $content to $path only when nothing is there (never over a file of
 * the user's): a new file of our own (writeNewFile), renamed into place.
 * False when something was there already.
 */
function advisorWriteIfAbsent(string $path, string $content, int $uid, int $gid): bool
{
    $path = advisorUnraidPath($path);
    clearstatcache(true, $path);
    if (file_exists($path) || is_link($path)) {
        return false;
    }
    $st = @lstat(dirname($path));
    if (!$st || ($st['mode'] & 0170000) !== 0040000) {
        throw new Problem('ad_link', ['path' => dirname($path)]);
    }
    $tmp = writeNewFile(dirname($path) . '/.' . basename($path), $content, 0644);
    if ($tmp === null) {
        throw new Problem('ad_write', ['path' => $path]);
    }
    @lchown($tmp, $uid);
    @lchgrp($tmp, $gid);
    clearstatcache(true, $path);
    if (file_exists($path) || is_link($path) || !@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** The office's provisioning files, relative to Grafana's provisioning folder */
function advisorGrafanaRels(bool $dashboard): array
{
    return array_merge(['datasources/uso-prometheus.yaml', 'dashboards/uso.yaml'], $dashboard ? ['dashboards/uso/unraid-secretary-office.json'] : []);
}

/**
 * Grafana's provisioning files for the office (relative to its provisioning
 * folder): the Prometheus data source (uid ADVISOR_DS_UID — the default only
 * in a Grafana he installs, an existing one may have its own), the dashboard
 * provider pointing to $inside/dashboards/uso, and the office's dashboard with
 * its data source filled in (when this installation has the file).
 *
 * @return array<string, array{0:string, 1:string}>  path => [kind, content]
 */
function advisorGrafanaFiles(string $ip, bool $default, string $inside, ?string $dashboardFile): array
{
    $files = [
        'datasources/uso-prometheus.yaml' => ['datasource',
            "# Unraid Secretary Office (its consultant): the Prometheus the office's dashboard reads.\n"
            . "apiVersion: 1\n"
            . "datasources:\n"
            . "  - name: Prometheus (Secretary Office)\n"
            . '    uid: ' . ADVISOR_DS_UID . "\n"
            . "    type: prometheus\n"
            . "    access: proxy\n"
            . "    url: http://$ip:9090\n"
            . '    isDefault: ' . ($default ? 'true' : 'false') . "\n"
            . "    editable: true\n"],
        'dashboards/uso.yaml' => ['provider',
            "# Unraid Secretary Office (its consultant): the office's dashboard, read from the folder below.\n"
            . "apiVersion: 1\n"
            . "providers:\n"
            . "  - name: Unraid Secretary Office\n"
            . "    orgId: 1\n"
            . "    folder: Unraid Secretary Office\n"
            . "    type: file\n"
            . "    disableDeletion: false\n"
            . "    allowUiUpdates: true\n"
            . "    options:\n"
            . "      path: $inside/dashboards/uso\n"],
    ];
    $json = $dashboardFile !== null ? advisorDashboardJson($dashboardFile) : null;
    if ($json !== null) {
        $files['dashboards/uso/unraid-secretary-office.json'] = ['dashboard', $json];
    }
    return $files;
}

/**
 * The office's dashboard (an export with an input for its data source) as Grafana provisions it: the
 * data source filled in. Decoded as objects, not arrays: Grafana's value mappings are objects keyed
 * "0", "1" … — as PHP arrays they would come back as lists and the mappings would be lost.
 */
function advisorDashboardJson(string $file): ?string
{
    $j = json_decode((string) @file_get_contents($file));
    if (!$j instanceof stdClass || !isset($j->panels)) {
        return null;
    }
    unset($j->__inputs, $j->__elements, $j->__requires);
    $j->id = null;
    $fill = function (mixed $v) use (&$fill): mixed {
        if ($v === '${DS_PROMETHEUS}') {
            return ADVISOR_DS_UID;
        }
        if ($v instanceof stdClass) {
            foreach (get_object_vars($v) as $k => $x) {
                $v->$k = $fill($x);
            }
        } elseif (is_array($v)) {
            $v = array_map($fill, $v);
        }
        return $v;
    };
    return json_encode($fill($j), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

/**
 * An existing Grafana: where the office's provisioning files would go and
 * which are there.
 */
function advisorProvisionPlan(array $env): array
{
    $found = advisorFindContainer(ADVISOR_EXTERNALS['grafana'], $env['containers']);
    $inspect = $found !== null ? houseInspect($found['name']) : null;
    if ($found === null || $inspect === null) {
        throw new Problem('ad_grafana_missing');
    }
    $g = advisorGrafanaInfo(array_merge($found, ['running' => true]), $inspect);     // asked for: look even while it is stopped
    if ($g['host'] === null) {
        throw new Problem('ad_grafana_unmapped', ['name' => $found['name']]);
    }
    $files = [];
    foreach (advisorGrafanaFiles((string) $env['ip'], false, $g['inside'], $env['dashboard']) as $rel => [$kind, $content]) {
        $path = $g['host'] . "/$rel";
        clearstatcache(true, $path);
        $files[] = ['path' => $path, 'rel' => $rel, 'kind' => $kind, 'content' => $content, 'there' => file_exists($path) || is_link($path)];
    }
    return ['name' => $found['name'], 'running' => $found['running'], 'points' => $g['points'], 'provisioning' => $g['provisioning'],
            'want' => $g['want'], 'host' => $g['host'], 'files' => $files, 'ip' => $env['ip'],
            'dashboard' => in_array('dashboard', array_column($files, 'kind'), true)];
}

function advisorProvisionPublic(array $plan): array
{
    $plan['files'] = array_map(fn ($f) => ['path' => $f['path'], 'kind' => $f['kind'], 'there' => $f['there']], $plan['files']);
    return $plan;
}

/** Writes the office's provisioning files into an existing Grafana's folder — only those that aren't there */
function advisorProvision(array $env): array
{
    $plan = advisorProvisionPlan($env);
    if (empty($env['ip'])) {
        throw new Problem('ad_no_ip');
    }
    // the provisioning folder below its parent (the data folder or the mapped one), never through a link
    $base = dirname($plan['host']);
    advisorDirs($base, [basename($plan['host']), basename($plan['host']) . '/datasources', basename($plan['host']) . '/dashboards',
                        basename($plan['host']) . '/dashboards/uso'], $env['uid'], $env['gid']);
    $written = $kept = [];
    foreach ($plan['files'] as $f) {
        if (advisorWriteIfAbsent($f['path'], $f['content'], $env['uid'], $env['gid'])) {
            $written[] = $f['path'];
        } else {
            $kept[] = $f['path'];
        }
    }
    logLine("Consultant: Grafana's provisioning in {$plan['host']}" . ($written ? ' - wrote ' . count($written) . ' file(s)' : ' - all there'));
    return ['ok' => true, 'written' => $written, 'kept' => $kept, 'points' => $plan['points']];
}

// ===================================================================== installing a plugin

/**
 * Installs a plugin the way Unraid's Plugins page does — `plugin install
 * <url>` — as an atd job (it outlives the agent), its output in RAM for the
 * page. Only his pinned addresses, never one that is installed, one job at a
 * time, not while the plugin manager is busy.
 */
function advisorPluginInstall(string $id): array
{
    $how = ADVISOR_EXTERNALS[$id];
    if (!isset($how['plg']) || (!empty($how['media']) && advisorMedia() === null)) {
        throw new Problem('ad_unknown', ['id' => $id]);
    }
    if (housePlugin($how['plugin']) || is_link("/var/log/plugins/{$how['plugin']}.plg") || is_file("/var/log/plugins/{$how['plugin']}.plg")) {
        throw new Problem('ad_installed', ['id' => $id]);
    }
    $job = advisorJob();
    if (($job['state'] ?? '') === 'running' || advisorPluginManagerBusy()) {
        throw new Problem('ad_plugin_busy');
    }
    foreach (['.out', '.done'] as $ext) {
        @unlink(ADVISOR_JOB . $ext);
    }
    writeAtomic(ADVISOR_JOB . '.json', jsonEncode(['id' => $id, 'plugin' => $how['plugin'], 'url' => $how['plg'], 'started' => time()]), 0600, 0, 0);
    hostLaunch('advisor-plugin', ['/bin/sh', '-c', ADVISOR_JOB_SCRIPT, 'sh', ADVISOR_PLUGIN_BIN, $how['plg'], ADVISOR_JOB . '.out', ADVISOR_JOB . '.done']);
    logLine("Consultant: installing the plugin {$how['plugin']} from {$how['plg']} (via at)");
    return ['ok' => true, 'job' => advisorJob()];
}

/**
 * His plugin job: running (no exit code yet), done (0 and Unraid lists the
 * plugin), unregistered (0, but Unraid doesn't list it), failed, or unknown
 * (no word after 15 minutes) — with the plugin manager's output.
 */
function advisorJob(string $base = ADVISOR_JOB): ?array
{
    $meta = readJson("$base.json");
    if (!is_array($meta) || !isset(ADVISOR_EXTERNALS[$meta['id'] ?? ''])) {
        return null;
    }
    $plugin = (string) ADVISOR_EXTERNALS[$meta['id']]['plugin'];
    clearstatcache();
    $done = @file_get_contents("$base.done");
    $exit = is_string($done) && preg_match('/^\d{1,3}\s*$/D', $done) ? (int) $done : null;
    $out = '';
    $size = (int) @filesize("$base.out");
    if ($size > 0 && ($f = @fopen("$base.out", 'r'))) {
        fseek($f, max(0, $size - 32768));
        $out = (string) stream_get_contents($f);
        fclose($f);
    }
    $out = (string) preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/[^\P{C}\n\t]/u'], '', mb_convert_encoding($out, 'UTF-8', 'UTF-8'));
    $registered = file_exists("/var/log/plugins/$plugin.plg") && is_file(HOUSE_PLUGINS . "/$plugin.plg");
    $started = (int) ($meta['started'] ?? 0);
    $state = match (true) {
        $exit === null => time() - $started > 900 ? 'unknown' : 'running',
        $exit !== 0    => 'failed',
        $registered    => 'done',
        default        => 'unregistered',
    };
    return ['id' => $meta['id'], 'plugin' => $plugin, 'url' => (string) ($meta['url'] ?? ''), 'started' => $started,
            'state' => $state, 'exit' => $exit, 'output' => $out, 'registered' => $registered];
}

/** Is Unraid's plugin manager at work (an install, update or removal from the Plugins page or the boot)? */
function advisorPluginManagerBusy(): bool
{
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
        $cmd = explode("\0", (string) @file_get_contents($file));
        if (in_array(ADVISOR_PLUGIN_BIN, array_slice($cmd, 0, 2), true) || (basename($cmd[1] ?? '') === 'plugin' && in_array($cmd[2] ?? '', ['install', 'update', 'remove'], true))) {
            return true;
        }
    }
    return false;
}

// ===================================================================== Kopia's repository

/**
 * Creates or connects Kopia's repository with the user's keys and password.
 * They arrive through RAM only (officeInboxDir(), written by the web side,
 * src/api.php) — the file is gone as soon as it is read — and go to Kopia
 * only on its stdin (ADVISOR_KOPIA_SH); what Kopia answers is cleaned of
 * them; the office keeps none of them (Kopia keeps its connection in its own
 * config folder, as when it is set up by hand). Only for a Kopia container
 * that runs and isn't connected yet, never while a backup or restore holds
 * the engine's lock. Afterwards the container restarts once, so the KopiaUI
 * (its server, started before) shows the repository. $inbox and $target are
 * there for the tests.
 */
function advisorKopiaRepo(array $r, ?string $inbox = null, ?array $target = null): array
{
    $secret = advisorSecretTake($r['secret_ref'] ?? null, $inbox ?? officeInboxDir());
    $spec = null;
    try {
        $k = $target ?? advisorKopiaTarget();      // the tests bring a stand-in
        $spec = advisorKopiaSpec($r, $secret, $k);
        $docker = advisorDocker();
        [$exit, $out, $err] = advisorRunStdin([$docker, 'exec', '-i', $k['name'], 'sh', '-c', ADVISOR_KOPIA_SH, 'sh', ...$spec['args']],
            $spec['stdin'], 300);
        $said = advisorScrub(trim($out . "\n" . $err), $secret);
        logLine("Consultant: Kopia repository {$spec['mode']} ({$spec['storage']}) in {$k['name']} - exit $exit");
        if ($exit !== 0) {
            throw new Problem('ad_kopia_failed', ['output' => advisorTail($said, 1500)]);
        }
        if ($spec['client'] !== null) {
            [$u, $h] = explode('@', $spec['client'], 2);
            run([$docker, 'exec', $k['name'], 'kopia', 'repository', 'set-client', "--username=$u", "--hostname=$h"], 60);
        }
        [$version] = array_pad(preg_split('/\s+/', trim(run([$docker, 'exec', $k['name'], 'kopia', '--version'], 30)[1])) ?: [], 1, '');
        [$restarted] = run([$docker, 'restart', '-t', '30', $k['name']], 120);
        $repo = $k['info']['config'] !== null ? advisorKopiaRepoFacts($k['info']['config'] . '/repository.config') : ['connected' => true];
        $facts = [
            'server' => hostname(), 'time' => time(), 'container' => $k['name'], 'image' => $k['image'], 'version' => $version,
            'mode' => $spec['mode'], 'storage' => $spec['storage'], 'provider' => $spec['provider'],
            'endpoint' => $spec['endpoint'], 'region' => $spec['region'], 'bucket' => $spec['bucket'], 'prefix' => $spec['prefix'],
            'path' => $spec['path'], 'path_host' => $spec['path_host'], 'config' => $k['info']['config'],
            'sources' => $k['info']['sources'], 'restore' => $k['info']['restore'], 'client' => $repo['client'] ?? $spec['client'],
            'webui' => $k['webui'], 'restarted' => $restarted === 0,
        ];
        return ['ok' => true, 'facts' => $facts, 'state' => $target === null ? advisorScan() : null];
    } finally {
        unset($secret, $spec);         // as far as PHP lets go of them
    }
}

/** The Kopia container to set up: there, running, not connected yet, the engine's lock free */
function advisorKopiaTarget(): array
{
    $found = advisorFindContainer(ADVISOR_EXTERNALS['kopia'], houseContainers());
    if ($found === null) {
        throw new Problem('ad_kopia_missing');
    }
    if (!$found['running']) {
        throw new Problem('ad_kopia_stopped', ['name' => $found['name']]);
    }
    $inspect = houseInspect($found['name']);
    if ($inspect === null) {
        throw new Problem('ad_kopia_missing');
    }
    $info = advisorKopiaInfo($found, $inspect);
    if (($info['repo']['connected'] ?? true) !== false) {        // connected, or can't tell: hands off
        throw new Problem('ad_kopia_connected', ['name' => $found['name']]);
    }
    if (backupLockHolder() !== null) {
        throw new Problem('ad_backup_busy');
    }
    return ['name' => $found['name'], 'image' => $found['image'], 'info' => $info, 'webui' => advisorWebUi($inspect, advisorServerIp())];
}

/**
 * The request checked field by field: what Kopia is asked to do (arguments
 * without secrets), what goes to its stdin (the secrets, one per line), and
 * the non-secret facts. Problem 'ad_kopia_field' names the first field that
 * isn't right.
 *
 * @param array{info:array} $k  the container (advisorKopiaTarget())
 */
function advisorKopiaSpec(array $r, array $secret, array $k): array
{
    $field = function (string $name, string $pattern, bool $optional = false) use ($r): string {
        $v = $r[$name] ?? '';
        $v = is_string($v) ? trim($v) : '';
        if ($v === '' && $optional) {
            return '';
        }
        if (!preg_match($pattern, $v)) {
            throw new Problem('ad_kopia_field', ['field' => $name]);
        }
        return $v;
    };
    $mode = $field('mode', '/^(create|connect)$/D');
    $storage = $field('storage', '/^(s3|filesystem)$/D');
    $password = (string) ($secret['password'] ?? '');
    if (preg_match('/[\x00-\x1f\x7f]/', $password) || strlen($password) > 1024 || strlen($password) < ($mode === 'create' ? 12 : 1)) {
        throw new Problem('ad_kopia_field', ['field' => 'password']);
    }
    $out = ['mode' => $mode, 'storage' => $storage, 'provider' => null, 'endpoint' => null, 'region' => null, 'bucket' => null,
            'prefix' => null, 'path' => null, 'path_host' => null, 'client' => null];
    $access = $secretKey = '';
    if ($storage === 's3') {
        $out['provider'] = in_array($r['provider'] ?? '', ADVISOR_S3_PROVIDERS, true) ? $r['provider'] : 's3';
        $out['endpoint'] = $field('endpoint', '/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,252})(?::\d{1,5})?$/D');
        $out['region'] = $field('region', '/^[A-Za-z0-9-]{1,40}$/D', true) ?: null;
        $out['bucket'] = $field('bucket', '/^[A-Za-z0-9][A-Za-z0-9._-]{1,62}$/D');
        $prefix = $field('prefix', '#^[A-Za-z0-9._/-]{1,200}$#D', true);
        if ($prefix !== '' && (str_starts_with($prefix, '/') || in_array('..', explode('/', $prefix), true))) {
            throw new Problem('ad_kopia_field', ['field' => 'prefix']);
        }
        $out['prefix'] = $prefix ?: null;
        $access = (string) ($secret['access_key'] ?? '');
        $secretKey = (string) ($secret['secret_key'] ?? '');
        if (!preg_match('/^[\x21-\x7e]{3,256}$/D', $access)) {
            throw new Problem('ad_kopia_field', ['field' => 'access_key']);
        }
        if (!preg_match('/^[\x21-\x7e]{8,512}$/D', $secretKey)) {
            throw new Problem('ad_kopia_field', ['field' => 'secret_key']);
        }
        $args = [$mode, 's3', '--bucket=' . $out['bucket'], '--endpoint=' . $out['endpoint']];
        if ($out['region'] !== null) {
            $args[] = '--region=' . $out['region'];
        }
        if ($out['prefix'] !== null) {
            $args[] = '--prefix=' . $out['prefix'];
        }
    } else {
        $path = advisorNormPath($field('path', '#^/[A-Za-z0-9._/-]{1,200}$#D'));
        if (in_array('..', explode('/', $path), true)) {
            throw new Problem('ad_kopia_field', ['field' => 'path']);
        }
        $host = advisorHostPath(array_map(fn ($f) => [$f['host'], $f['target']], $k['info']['folders']), $path);
        if ($host === null) {
            throw new Problem('ad_kopia_field', ['field' => 'path']);
        }
        $out['path'] = $path;
        $out['path_host'] = $host;
        $args = [$mode, 'filesystem', '--path=' . $path];
    }
    $args[] = '--persist-credentials';        // the nightly run connects without anybody typing the password
    if ($mode === 'connect') {
        $client = $field('client', '/^[A-Za-z0-9._-]{1,64}@[A-Za-z0-9._-]{1,64}$/D', true);
        $out['client'] = $client ?: null;
    }
    return $out + ['args' => $args, 'stdin' => "$password\n$access\n$secretKey\n"];
}

/**
 * Takes the secrets the web side left for one request in the RAM inbox: a
 * plain 0600 file of root's (no link, one name) in a root-only folder,
 * removed at once — whatever it held, whether it was usable or not.
 *
 * @return array<string, string>
 */
function advisorSecretTake(mixed $ref, string $dir): array
{
    if (!is_string($ref) || !preg_match('/^[0-9a-f]{32}$/D', $ref)) {
        throw new Problem('ad_secret_missing');
    }
    $file = "$dir/$ref.secret";
    clearstatcache();
    $d = @lstat($dir);
    $st = @lstat($file);
    $ok = $d && ($d['mode'] & 0170000) === 0040000 && $d['uid'] === 0 && ($d['mode'] & 0077) === 0
        && $st && ($st['mode'] & 0170000) === 0100000 && $st['uid'] === 0 && ($st['mode'] & 0077) === 0 && $st['nlink'] === 1
        && $st['size'] <= 16384;
    $raw = $ok ? @file_get_contents($file, false, null, 0, 16384) : false;
    if ($st) {
        @unlink($file);
    }
    $data = is_string($raw) ? json_decode($raw, true) : null;
    unset($raw);
    if (!is_array($data)) {
        throw new Problem('ad_secret_missing');
    }
    return array_map(fn ($v) => is_string($v) ? $v : '', array_filter($data, 'is_string', ARRAY_FILTER_USE_KEY));
}

/** Removes inbox files older than $age seconds (a request cut off half-way); nothing else */
function advisorInboxSweep(string $dir, int $age): int
{
    $n = 0;
    foreach (glob("$dir/*.secret") ?: [] as $file) {
        $st = @lstat($file);
        if ($st && ($st['mode'] & 0170000) === 0100000 && $st['mtime'] < time() - $age && preg_match('/^[0-9a-f]{32}\.secret$/D', basename($file))) {
            $n += @unlink($file) ? 1 : 0;
        }
    }
    return $n;
}

/** The docker command (OFFICE_ADVISOR_DOCKER: a stand-in for the tests) */
function advisorDocker(): string
{
    return getenv('OFFICE_ADVISOR_DOCKER') ?: 'docker';
}

/**
 * run() with something on stdin (no shell): for Kopia's secrets, which must
 * not appear on a command line.
 *
 * @return array{0:int, 1:string, 2:string}
 */
function advisorRunStdin(array $command, string $input, int $timeout = 120): array
{
    $env = ['PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'C', 'HOME' => '/root'];
    $pipes = [];
    $p = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', $env);
    if (!is_resource($p)) {
        return [127, '', 'could not start ' . ($command[0] ?? '?')];
    }
    @fwrite($pipes[0], $input);
    fclose($pipes[0]);
    unset($input);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $buf = [1 => '', 2 => ''];
    $deadline = microtime(true) + $timeout;
    $killed = false;
    while (!feof($pipes[1]) || !feof($pipes[2])) {
        $read = array_values(array_filter([$pipes[1], $pipes[2]], fn ($s) => !feof($s)));
        $w = $e = null;
        @stream_select($read, $w, $e, 0, 200000);
        foreach ([1, 2] as $fd) {
            $chunk = feof($pipes[$fd]) ? false : fread($pipes[$fd], 65536);
            if (is_string($chunk) && strlen($buf[$fd]) < 1 << 20) {
                $buf[$fd] .= $chunk;
            }
        }
        if (microtime(true) > $deadline) {
            proc_terminate($p, 9);
            $killed = true;
            break;
        }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    return [$killed ? 124 : $code, $buf[1], $buf[2] . ($killed ? "\naborted after {$timeout} s" : '')];
}

/** Text with every secret value (three characters or more) replaced, control characters but line breaks out */
function advisorScrub(string $text, array $secret): string
{
    $text = (string) preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $text);     // first: a secret split by one would slip through
    foreach ($secret as $v) {
        if (is_string($v) && strlen($v) >= 3) {
            $text = str_replace($v, '•••', $text);
        }
    }
    return $text;
}

/** The last $max bytes of a text, from a line start */
function advisorTail(string $text, int $max): string
{
    if (strlen($text) <= $max) {
        return $text;
    }
    $cut = substr($text, -$max);
    $nl = strpos($cut, "\n");
    return '…' . ($nl !== false ? substr($cut, $nl) : $cut);
}
