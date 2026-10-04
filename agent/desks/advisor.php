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
];
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
        if (isset($how['plugin'])) {
            $p = $plugins[$how['plugin']] ?? null;
            $externals[$id] = ['kind' => 'plugin', 'there' => $p !== null, 'version' => $p['version'] ?? null,
                'optional' => !empty($how['optional']), 'icon' => $p !== null ? advisorPluginIcon($how['plugin']) : null];
            continue;
        }
        $found = null;
        foreach ($containers as $c) {
            if (stripos($c['image'] . ' ' . $c['name'], $how['container']) !== false) {
                $found = $c;
                break;
            }
        }
        $externals[$id] = ['kind' => 'container', 'there' => $found !== null, 'name' => $found['name'] ?? null,
            'image' => $found['image'] ?? null, 'running' => $found['running'] ?? false,
            'icon' => $found !== null ? advisorContainerIcon($found['name']) : null];
    }
    $state = ['time' => time(), 'gui' => houseGuiUrl(), 'media' => $media, 'externals' => $externals];
    writeAtomic(deskFile('advisor'), jsonEncode($state));
    return $state;
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
