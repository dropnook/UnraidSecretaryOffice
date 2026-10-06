<?php
declare(strict_types=1);

/*
 * The Consultant — he isn't part of the office: he knows the externals the
 * office relies on but doesn't make itself, tells whether they are there,
 * what they are good for and how to install them by hand.
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
 *           and the gather), but there for whoever needs it
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
 * Read only: whether a plugin is installed, whether a container exists and
 * runs, and where Unraid keeps its own icon (the maker's, so it is
 * recognised; the page shows an emoji while it isn't there). Nothing is
 * installed or changed from here.
 */

const ADVISOR_EXTERNALS = [
    'fcp'          => ['plugin' => 'fix.common.problems'],
    'filesviewer'  => ['plugin' => 'filesviewer'],
    'kopia'        => ['container' => 'kopia'],         // image or name contains it
    'streamviewer' => ['plugin' => 'streamviewer', 'media' => true],
    'unbalanced'   => ['plugin' => 'unbalanced', 'optional' => true],
    // 'image': the image's own name (no registry, owner, tag) or the container's name matches
    'nodeexporter' => ['plugin' => 'prometheus_node_exporter', 'image' => '/^node[-_]exporter$/i', 'optional' => true, 'group' => 'monitoring'],
    'prometheus'   => ['image' => '/^prometheus$/i', 'optional' => true, 'group' => 'monitoring'],
    'grafana'      => ['image' => '/^grafana(-oss|-enterprise)?$/i', 'optional' => true, 'group' => 'monitoring'],
    'loki'         => ['image' => '/^loki$/i', 'optional' => true, 'group' => 'monitoring', 'later' => true],
];
/** Where the office's own numbers go for the node exporter's textfile collector (RAM; *.prom files, lib/metrics.php writes them) */
const ADVISOR_METRICS_DIR = METRICS_HOST_DIR;
/** The office's dashboard for Grafana, to import (monitoring/grafana-dashboard.json on main) */
const ADVISOR_DASHBOARD_URL = 'https://raw.githubusercontent.com/' . OFFICE_REPO . '/main/monitoring/grafana-dashboard.json';
/** ich777's node exporter plugin takes its start options from here (start_parameters=…) */
const ADVISOR_NODE_PLUGIN_CFG = '/boot/config/plugins/prometheus_node_exporter/settings.cfg';
const ADVISOR_MEDIA = ['emby' => 'Emby', 'jellyfin' => 'Jellyfin', 'plex' => 'Plex'];

desk('advisor', [
    'start'   => fn () => advisorScan(),
    'fit'     => fn () => fit(true, 'yes'),
    'actions' => [
        'refresh' => fn (array $r) => ['ok' => true, 'state' => advisorScan()],
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
    $externals = [];
    foreach (ADVISOR_EXTERNALS as $id => $how) {
        if (!empty($how['media']) && $media === null) {
            continue;                       // no media server, nothing to watch
        }
        $common = ['optional' => !empty($how['optional'])] + array_filter(['group' => $how['group'] ?? null, 'later' => !empty($how['later'])]);
        $p = isset($how['plugin']) ? ($plugins[$how['plugin']] ?? null) : null;
        if ($p !== null || !isset($how['container']) && !isset($how['image'])) {
            $externals[$id] = ['kind' => 'plugin', 'there' => $p !== null, 'version' => $p['version'] ?? null,
                'icon' => $p !== null ? advisorPluginIcon($how['plugin']) : null] + $common;
            continue;
        }
        $found = advisorFindContainer($how, $containers);
        $externals[$id] = ['kind' => 'container', 'there' => $found !== null, 'name' => $found['name'] ?? null,
            'image' => $found['image'] ?? null, 'running' => $found['running'] ?? false,
            'icon' => $found !== null ? advisorContainerIcon($found['name']) : null] + $common;
    }
    if (isset($externals['nodeexporter'])) {
        $externals['nodeexporter']['textfile'] = advisorNodeTextfile($externals['nodeexporter']);
    }
    $state = ['time' => time(), 'gui' => houseGuiUrl(), 'media' => $media, 'metrics_dir' => ADVISOR_METRICS_DIR,
              'dashboard' => ADVISOR_DASHBOARD_URL, 'externals' => $externals];
    writeAtomic(deskFile('advisor'), jsonEncode($state));
    return $state;
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
function advisorNodeTextfile(array $x): ?bool
{
    if (!$x['there']) {
        return null;
    }
    if ($x['kind'] === 'plugin') {
        $cfg = (string) @file_get_contents(ADVISOR_NODE_PLUGIN_CFG);
        $line = preg_match('/^start_parameters=(.*)$/m', $cfg, $m) ? trim($m[1], " \t\r\"'") : '';
        return in_array(ADVISOR_METRICS_DIR, advisorTextfileDirs(preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY), null), true);
    }
    $i = houseInspect((string) $x['name']);
    if ($i === null) {
        return null;
    }
    $mounts = array_map(fn ($m) => [(string) ($m['Source'] ?? ''), (string) ($m['Destination'] ?? '')], $i['Mounts'] ?? []);
    return in_array(ADVISOR_METRICS_DIR, advisorTextfileDirs(array_map('strval', $i['Args'] ?? []), $mounts), true);
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
    $norm = fn (string $p) => '/' . implode('/', array_filter(explode('/', $p), fn ($s) => $s !== '' && $s !== '.'));
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
        $dir = $norm(trim($dir, "\"'"));
        if ($mounts === null) {
            $out[] = $dir;
            continue;
        }
        $best = null;
        foreach ($mounts as [$host, $inside]) {
            $inside = $norm($inside);
            $under = $inside === '/' || $dir === $inside || str_starts_with($dir, $inside . '/');
            if ($host !== '' && $under && ($best === null || strlen($inside) > strlen($best[1]))) {
                $best = [$host, $inside];
            }
        }
        if ($best !== null) {
            $out[] = $norm($best[0] . '/' . substr($dir, strlen($best[1])));
        }
    }
    return array_values(array_unique($out));
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
    return preg_match('/^[\w.-]+$/', $name) && is_file(ADVISOR_DOCROOT . "/$path") ? "/$path" : null;
}
