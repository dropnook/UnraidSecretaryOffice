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
 *
 * Read only: whether a plugin is installed, whether a container exists and
 * runs. Nothing is installed or changed from here.
 */

const ADVISOR_EXTERNALS = [
    'fcp'          => ['plugin' => 'fix.common.problems'],
    'filesviewer'  => ['plugin' => 'filesviewer'],
    'kopia'        => ['container' => 'kopia'],         // image or name contains it
    'streamviewer' => ['plugin' => 'streamviewer', 'media' => true],
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
            $externals[$id] = ['kind' => 'plugin', 'there' => $p !== null, 'version' => $p['version'] ?? null];
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
            'image' => $found['image'] ?? null, 'running' => $found['running'] ?? false];
    }
    $state = ['time' => time(), 'gui' => houseGuiUrl(), 'media' => $media, 'externals' => $externals];
    writeAtomic(deskFile('advisor'), jsonEncode($state));
    return $state;
}
