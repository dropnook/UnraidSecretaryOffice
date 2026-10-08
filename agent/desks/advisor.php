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
 *               on its stdin and are kept nowhere by the office (HARDENING.md).
 *               Ransomware protection: before a new repository in S3 he asks
 *               the bucket whether it keeps S3 Object Lock (one signed GET
 *               ?object-lock from here, advisorObjectLock()) and, when it does,
 *               offers Kopia's retention (COMPLIANCE, N days) — Kopia then
 *               extends the locks at its full maintenance
 *
 * The network (group 'network', the night watchman's router lines — briefs/brief-router-soc-stage1.md 6): a new
 * shape, a setting he explains and never changes — «Unraid's syslog server» (what rsyslog.cfg says, which pools a share
 * `syslog` of its own belongs on, the loop of ⟦Remote syslog server⟧, which senders have files: agent/lib/watchnet.php's
 * watchnetAdvisor()) —
 * and two guides: the router's side (UniFi: the gateway's Activity Logging to Unraid) and the neighbours (FireSight,
 * Loki + Alloy, CrowdSec, a real SIEM: dashboards and stores of the router's logs are theirs). Nothing installed, no
 * router credential asked for; the page tells the agent when the router guide was opened (network_seen: the Team
 * Lead's syslog_off hint).
 *
 * The office never logs into a web page or an HTTP API of an external: it
 * configures them through files and command lines, at install time.
 */

require_once __DIR__ . '/../lib/watchnet.php';

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
    // the network: a setting he explains and never changes, and two guides (no plugin, no container — nothing to install)
    'syslogserver' => ['setting' => 'syslog', 'optional' => true, 'group' => 'network'],
    'unifi'        => ['guide' => 'unifi', 'optional' => true, 'group' => 'network'],
    'neighbours'   => ['guide' => 'neighbours', 'optional' => true, 'group' => 'network'],
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
/** Provisioning folders Grafana reads at every start and logs an error for when they are missing: made empty with the office's */
const ADVISOR_GRAFANA_EMPTY = ['plugins', 'alerting'];
/** The office's dashboard in Grafana's provisioning (kept current, advisorDashboardKeep()) and its uid; how often he looks */
const ADVISOR_DASHBOARD_REL   = 'dashboards/uso/unraid-secretary-office.json';
const ADVISOR_DASHBOARD_UID   = 'unraid-secretary-office';
const ADVISOR_DASHBOARD_EVERY = 3600;
/** The plugin manager, and his one plugin job at a time (RAM: <job>.json, .out, .done) */
const ADVISOR_PLUGIN_BIN = '/usr/local/sbin/plugin';
const ADVISOR_JOB        = RUN_DIR . '/advisor-plugin';
const ADVISOR_JOB_SCRIPT = '"$1" install "$2" >"$3" 2>&1; echo $? >"$4"';
/** After he prepared Unraid's form: whether the container came, looked at this often for so long (his tick; then his scan once) */
const ADVISOR_RELOOK_EVERY = 20;
const ADVISOR_RELOOK_FOR   = 1800;
/** His record of what he installed (data/advisor/installs.json, root only) — the night watchman reads it: the office's own doing */
const ADVISOR_RECORD_MAX  = 20;
const ADVISOR_RECORD_DAYS = 30;

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
/**
 * S3 providers offered in the page (only remembered for the recovery sheet; Kopia takes endpoint and region).
 * versitygw (2026-10): the recommended S3 server on a second Unraid — its posix backend answers the Object Lock probe like
 * AWS (200 Enabled / 404 ObjectLockConfigurationNotFoundError; briefs/versitygw-findings.md); minio stays accepted for
 * repositories set up with it, the page doesn't offer it any more (its community edition was archived in 2026-04)
 */
const ADVISOR_S3_PROVIDERS = ['s3', 'aws', 'b2', 'r2', 'mega', 'wasabi', 'hetzner', 'idrive', 'versitygw', 'minio'];
/** Providers that don't offer S3 Object Lock — said plainly also when the bucket's answer is unclear */
const ADVISOR_NO_LOCK = ['mega'];
/** Object Lock: the days offered (default), the least (a week — and Kopia's full maintenance must run a day more often), the most */
const ADVISOR_LOCK_DAYS = 30;
const ADVISOR_LOCK_MIN  = 7;
const ADVISOR_LOCK_MAX  = 365;

desk('advisor', [
    'start'   => function () {
        advisorPreparedRestore();       // a form he prepared shortly before the agent restarted: still looked after
        return advisorScan();
    },
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
        // his router guide was opened on the page: the Team Lead may say the syslog server is still off (a time, nothing else)
        'network_seen'     => fn (array $r) => advisorNetworkSeen(),
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
        if (isset($how['setting']) || isset($how['guide'])) {
            // the network: Unraid's syslog server as it is set (read only), the guides (nothing to look at)
            $externals[$id] = isset($how['setting'])
                ? ['kind' => 'setting', 'there' => ($syslog = watchnetAdvisor())['on'], 'syslog' => $syslog] + $common
                : ['kind' => 'guide', 'there' => null, 'seen' => watchnetGuideSeen()] + $common;
            continue;
        }
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
              'metrics_there' => advisorMetricsThere(),
              'dashboard' => ADVISOR_DASHBOARD_URL, 'prometheus_yml' => ADVISOR_PROMETHEUS_YML, 'externals' => $externals,
              'server' => hostname(), 'job' => advisorJob()];
    writeAtomic(deskFile('advisor'), jsonEncode($state));
    return $state;
}

/** «UniFi: send the gateway's logs» was opened on his page: noted (data/advisor/network.json, root only) for the Team Lead */
function advisorNetworkSeen(?string $file = null, ?int $now = null): array
{
    $file ??= watchnetGuideFile();
    $now ??= time();
    if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0700, true)) {
        throw new Problem('ad_write', ['path' => dirname($file)]);
    }
    $was = watchnetGuideSeen($file);
    if ($was === null || $now - $was > 3600) {
        writeAtomic($file, jsonEncode(['seen' => $now]), 0600, 0, 0);
    }
    return ['ok' => true];
}

/**
 * Every 60 s: secrets nobody took within two minutes go (the web side removes its own; this is for a
 * request cut off half-way). Every ADVISOR_DASHBOARD_EVERY: the office's dashboard in Grafana kept current.
 */
function advisorTick(): void
{
    static $last = 0, $dash = 0, $relook = 0;
    if (isset($GLOBALS['advisorPrepared']) && time() - $relook >= ADVISOR_RELOOK_EVERY) {
        $relook = time();
        $due = advisorRelookDue($GLOBALS['advisorPrepared'], houseContainers(), time());
        if ($due !== 'wait') {
            unset($GLOBALS['advisorPrepared']);
        }
        if ($due === 'scan') {
            advisorScan();          // the container is there: his page says so without waiting for its next look
        }
    }
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
 * After he prepared Unraid's form for $w['id'] (at $w['at']): scan — a container of that kind is there now
 * (by image or name, like his scan finds it); stop — not within ADVISOR_RELOOK_FOR; wait otherwise.
 */
function advisorRelookDue(array $w, array $containers, int $now): string
{
    if (!isset(ADVISOR_EXTERNALS[$w['id'] ?? ''])) {
        return 'stop';
    }
    if (advisorFindContainer(ADVISOR_EXTERNALS[$w['id']], $containers) !== null) {
        return 'scan';
    }
    return $now - (int) ($w['at'] ?? 0) > ADVISOR_RELOOK_FOR ? 'stop' : 'wait';
}

/**
 * After an agent restart (a deploy, a plugin update): the newest form he prepared within ADVISOR_RELOOK_FOR, from
 * his root-only record — so his tick still looks whether the container came (advisorRelookDue()).
 */
function advisorPreparedRestore(?int $now = null): void
{
    $now ??= time();
    $file = advisorRecordFile();
    clearstatcache();
    $st = @lstat($file);
    if (!$st || ($st['mode'] & 0170000) !== 0100000 || $st['uid'] !== 0 || ($st['mode'] & 0077) || $st['nlink'] !== 1 || $st['size'] > 65536) {
        return;
    }
    foreach (array_reverse((array) ((readJson($file) ?? [])['installs'] ?? [])) as $r) {
        if (is_array($r) && ($r['kind'] ?? null) === 'container' && is_string($r['id'] ?? null) && isset(ADVISOR_EXTERNALS[$r['id']])
            && is_int($r['t'] ?? null) && $r['t'] <= $now + 60 && $now - $r['t'] <= ADVISOR_RELOOK_FOR) {
            $GLOBALS['advisorPrepared'] = ['id' => $r['id'], 'at' => $r['t']];
            return;
        }
    }
}

/** His record: where it lies (tests set $GLOBALS['advisorRecordFile']) */
function advisorRecordFile(): string
{
    return $GLOBALS['advisorRecordFile'] ?? DATA_DIR . '/advisor/installs.json';
}

/**
 * Notes what he installs — a plugin (`plugin install <url>`: kind plugin, its name and address) or a
 * container whose form he prepared (kind container, its name and image) — with the time, so the night
 * watchman knows it as the office's own doing (watchmanOfficeLook()). Root only: the folder 0700 and the
 * file 0600 of root's (writeAtomic(): a new file, renamed into place); a folder others own or may write
 * is made root's again, a file others could have written is not read (the record begins anew). The
 * newest ADVISOR_RECORD_MAX within ADVISOR_RECORD_DAYS. False when it can't be written (logged).
 */
function advisorRecord(array $entry, ?int $now = null): bool
{
    $file = advisorRecordFile();
    $dir = dirname($file);
    $now ??= time();
    clearstatcache();
    $st = @lstat($dir);
    if (!$st && @mkdir($dir, 0700)) {
        clearstatcache();
        $st = @lstat($dir);
    }
    if ($st && ($st['mode'] & 0170000) === 0040000 && ($st['uid'] !== 0 || $st['gid'] !== 0 || ($st['mode'] & 0077))) {
        @lchown($dir, 0);
        @lchgrp($dir, 0);
        @chmod($dir, 0700);
        clearstatcache();
        $st = @lstat($dir);
    }
    if (!$st || ($st['mode'] & 0170000) !== 0040000 || $st['uid'] !== 0 || ($st['mode'] & 0077)) {
        logLine("Consultant: could not write his record $file");
        return false;
    }
    $f = @lstat($file);
    $old = $f && ($f['mode'] & 0170000) === 0100000 && $f['uid'] === 0 && !($f['mode'] & 0077) && $f['nlink'] === 1 && $f['size'] <= 65536
        ? (array) ((readJson($file) ?? [])['installs'] ?? []) : [];
    $keep = array_values(array_filter($old, fn ($r) => is_array($r) && is_int($r['t'] ?? null) && $r['t'] > $now - ADVISOR_RECORD_DAYS * 86400 && $r['t'] <= $now + 60));
    $keep[] = ['t' => $now] + $entry;
    try {
        writeAtomic($file, jsonEncode(['installs' => array_slice($keep, -ADVISOR_RECORD_MAX)]), 0600, 0, 0);
    } catch (Throwable) {
        logLine("Consultant: could not write his record $file");
        return false;
    }
    return true;
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
    $path = officeUnraidPath($g['host'] . '/' . ADVISOR_DASHBOARD_REL);
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
 * Is the office's folder for its numbers really there on the host — a real folder, no link? Only then
 * does «reads the office's folder» hold for a node exporter pointed at it (lib/metrics.php makes it,
 * and /mnt/addons where nothing made it). $dir for the tests.
 */
function advisorMetricsThere(string $dir = ADVISOR_METRICS_DIR): bool
{
    clearstatcache(true, $dir);
    $st = @lstat($dir);
    return $st !== false && ($st['mode'] & 0170000) === 0040000;
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

/**
 * The office's dashboard in Grafana, as his last look saw it (his state file — no docker call, so other
 * desks may ask; the night watchman links his data flow's history there): only while Grafana was there
 * and running, with a web address, and the office's provisioning in place where Grafana reads it (the
 * dashboard has the uid ADVISOR_DASHBOARD_UID). The address is the WebUI's (advisorWebUi()), checked again.
 */
function advisorGrafanaDashboard(?string $file = null): ?string
{
    $g = (readJson($file ?? deskFile('advisor')) ?? [])['externals']['grafana'] ?? null;
    if (!is_array($g) || empty($g['there']) || empty($g['running']) || !is_array($g['grafana'] ?? null)
        || ($g['grafana']['done'] ?? null) !== true || ($g['grafana']['points'] ?? null) !== true) {
        return null;
    }
    if (!preg_match('#^(https?://[A-Za-z0-9.:\[\]-]+)(/[^\s"<>?\#]*)?(?:[?\#].*)?$#D', (string) ($g['webui'] ?? ''), $m)) {
        return null;
    }
    return $m[1] . rtrim($m[2] ?? '', '/') . '/d/' . ADVISOR_DASHBOARD_UID;
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
        advisorDirs($env['appdata'], array_merge(['grafana', 'grafana/provisioning', 'grafana/provisioning/datasources',
                                      'grafana/provisioning/dashboards', 'grafana/provisioning/dashboards/uso'],
                                      array_map(fn ($d) => "grafana/provisioning/$d", ADVISOR_GRAFANA_EMPTY)), $env['uid'], $env['gid']);
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
    advisorRecord(['kind' => 'container', 'id' => $id, 'name' => $plan['name'], 'image' => $plan['template']['repository']]);
    $GLOBALS['advisorPrepared'] = ['id' => $id, 'at' => time()];      // his tick looks whether it came (advisorRelookDue())
    return ['ok' => true, 'url' => ADVISOR_ADD_CONTAINER . $file, 'name' => $plan['name'], 'written' => $written, 'kept' => $kept];
}

/**
 * Creates the folders $subs below $base (which must exist) where missing —
 * 0755, nobody:users like Unraid makes a container's paths — and refuses
 * when one of them (or $base) is a link: nothing of his is written through
 * one.
 */
function advisorDirs(string $base, array $subs, int $uid, int $gid): void
{
    $base = officeUnraidPath($base);
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
    $path = officeUnraidPath($path);
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
    advisorDirs($base, array_merge([basename($plan['host']), basename($plan['host']) . '/datasources', basename($plan['host']) . '/dashboards',
                        basename($plan['host']) . '/dashboards/uso'], array_map(fn ($d) => basename($plan['host']) . "/$d", ADVISOR_GRAFANA_EMPTY)),
                $env['uid'], $env['gid']);
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
    advisorRecord(['kind' => 'plugin', 'id' => $id, 'name' => $how['plugin'], 'url' => $how['plg']]);     // before the job: the night watchman knows it as the office's
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
 * (its server, started before) shows the repository. $inbox, $target and
 * $http are there for the tests.
 *
 * step 'probe' (S3, before the preview): only asks the bucket whether it keeps
 * Object Lock (advisorObjectLock()) — nothing is created. With lock_days (a new
 * repository in S3): the bucket is asked again, Kopia creates it with
 * --retention-mode=COMPLIANCE --retention-period=<n>d and is told to extend the
 * locks at its full maintenance. Create or connect, the facts say whether the
 * repository keeps Object Lock (advisorKopiaLock()) — for the recovery sheet.
 */
function advisorKopiaRepo(array $r, ?string $inbox = null, ?array $target = null, ?callable $http = null): array
{
    $secret = advisorSecretTake($r['secret_ref'] ?? null, $inbox ?? officeInboxDir());
    $spec = $s3 = null;
    try {
        $k = $target ?? advisorKopiaTarget();      // the tests bring a stand-in
        if (($r['step'] ?? null) === 'probe') {
            // before the preview: does the bucket keep Object Lock? (only the keys travel, nothing is created)
            $s3 = advisorKopiaS3($r, $secret);
            $lock = advisorObjectLock($s3, $http);
            logLine("Consultant: asked the bucket about Object Lock - {$lock['state']}" . (isset($lock['why']) ? " ({$lock['why']})" : ''));
            return ['ok' => true, 'lock' => $lock + ['range' => [ADVISOR_LOCK_DAYS, ADVISOR_LOCK_MIN, ADVISOR_LOCK_MAX]]];
        }
        $spec = advisorKopiaSpec($r, $secret, $k);
        if ($spec['lock'] !== null) {
            // asked again, a fresh look: a retention on a bucket without Object Lock would fail — or, worse, be ignored
            $again = advisorObjectLock($spec['s3'], $http);
            if ($again['state'] !== 'enabled') {
                throw new Problem('ad_lock_off');
            }
        }
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
        $extend = null;
        if ($spec['lock'] !== null) {
            // what Kopia still needs keeps its lock: extended at every full maintenance (daily; the period is a week at least)
            [$x] = run([$docker, 'exec', $k['name'], 'kopia', 'maintenance', 'set', '--extend-object-locks=true'], 60);
            $extend = $x === 0;
            logLine("Consultant: Kopia extends the object locks at its full maintenance - exit $x");
        }
        $lock = advisorKopiaLock($docker, $k['name'], $extend);
        [$version] = array_pad(preg_split('/\s+/', trim(run([$docker, 'exec', $k['name'], 'kopia', '--version'], 30)[1])) ?: [], 1, '');
        [$restarted] = run([$docker, 'restart', '-t', '30', $k['name']], 90);
        $repo = $k['info']['config'] !== null ? advisorKopiaRepoFacts($k['info']['config'] . '/repository.config') : ['connected' => true];
        $facts = [
            'server' => hostname(), 'time' => time(), 'container' => $k['name'], 'image' => $k['image'], 'version' => $version,
            'mode' => $spec['mode'], 'storage' => $spec['storage'], 'provider' => $spec['provider'],
            'endpoint' => $spec['endpoint'], 'region' => $spec['region'], 'bucket' => $spec['bucket'], 'prefix' => $spec['prefix'],
            'path' => $spec['path'], 'path_host' => $spec['path_host'], 'config' => $k['info']['config'],
            'sources' => $k['info']['sources'], 'restore' => $k['info']['restore'], 'client' => $repo['client'] ?? $spec['client'],
            'webui' => $k['webui'], 'restarted' => $restarted === 0, 'lock' => $lock,
        ];
        return ['ok' => true, 'facts' => $facts, 'state' => $target === null ? advisorScan() : null];
    } finally {
        unset($secret, $spec, $s3);    // as far as PHP lets go of them
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
    $field = fn (string $name, string $pattern, bool $optional = false): string => advisorKopiaField($r, $name, $pattern, $optional);
    $mode = $field('mode', '/^(create|connect)$/D');
    $storage = $field('storage', '/^(s3|filesystem)$/D');
    $password = (string) ($secret['password'] ?? '');
    if (preg_match('/[\x00-\x1f\x7f]/', $password) || strlen($password) > 1024 || strlen($password) < ($mode === 'create' ? 12 : 1)) {
        throw new Problem('ad_kopia_field', ['field' => 'password']);
    }
    $out = ['mode' => $mode, 'storage' => $storage, 'provider' => null, 'endpoint' => null, 'region' => null, 'bucket' => null,
            'prefix' => null, 'path' => null, 'path_host' => null, 'client' => null, 'lock' => null, 's3' => null];
    $access = $secretKey = '';
    if ($storage === 's3') {
        $s3 = advisorKopiaS3($r, $secret);
        $out = array_merge($out, array_intersect_key($s3, array_flip(['provider', 'endpoint', 'region', 'bucket', 'prefix'])));
        $out['s3'] = $s3;               // with the keys: for asking about Object Lock again (never in the facts)
        $access = $s3['access'];
        $secretKey = $s3['secret'];
        $args = [$mode, 's3', '--bucket=' . $out['bucket'], '--endpoint=' . $out['endpoint']];
        if ($out['region'] !== null) {
            $args[] = '--region=' . $out['region'];
        }
        if ($out['prefix'] !== null) {
            $args[] = '--prefix=' . $out['prefix'];
        }
        // ransomware protection: Object Lock in compliance mode, for so many days (only a new repository; the bucket must keep Object Lock)
        $days = $r['lock_days'] ?? null;
        if ($days !== null && $days !== '' && $days !== false && $days !== 0) {
            if ($mode !== 'create' || !(is_int($days) || is_string($days)) || !preg_match('/^\d{1,4}$/D', (string) $days)
                || (int) $days < ADVISOR_LOCK_MIN || (int) $days > ADVISOR_LOCK_MAX) {
                throw new Problem('ad_kopia_field', ['field' => 'lock_days']);
            }
            $out['lock'] = ['mode' => 'COMPLIANCE', 'days' => (int) $days];
            $args[] = '--retention-mode=COMPLIANCE';
            $args[] = '--retention-period=' . (int) $days . 'd';
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
    if ($storage !== 's3' && !in_array($r['lock_days'] ?? null, [null, '', false, 0], true)) {
        throw new Problem('ad_kopia_field', ['field' => 'lock_days']);      // Object Lock is S3's
    }
    $args[] = '--persist-credentials';        // the nightly run connects without anybody typing the password
    if ($mode === 'connect') {
        $client = $field('client', '/^[A-Za-z0-9._-]{1,64}@[A-Za-z0-9._-]{1,64}$/D', true);
        $out['client'] = $client ?: null;
    }
    return $out + ['args' => $args, 'stdin' => "$password\n$access\n$secretKey\n"];
}

/** One field of the request, trimmed, checked against $pattern (Problem 'ad_kopia_field' naming it) */
function advisorKopiaField(array $r, string $name, string $pattern, bool $optional = false): string
{
    $v = $r[$name] ?? '';
    $v = is_string($v) ? trim($v) : '';
    if ($v === '' && $optional) {
        return '';
    }
    if (!preg_match($pattern, $v)) {
        throw new Problem('ad_kopia_field', ['field' => $name]);
    }
    return $v;
}

/**
 * The S3 storage of a request, checked: provider, endpoint, region, bucket, prefix — and the two keys
 * from the RAM file ('access', 'secret'; never kept, never in an answer).
 *
 * @return array{provider: string, endpoint: string, region: ?string, bucket: string, prefix: ?string, access: string, secret: string}
 */
function advisorKopiaS3(array $r, array $secret): array
{
    $field = fn (string $name, string $pattern, bool $optional = false): string => advisorKopiaField($r, $name, $pattern, $optional);
    $out = ['provider' => in_array($r['provider'] ?? '', ADVISOR_S3_PROVIDERS, true) ? $r['provider'] : 's3'];
    $out['endpoint'] = $field('endpoint', '/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,252})(?::\d{1,5})?$/D');
    $out['region'] = $field('region', '/^[A-Za-z0-9-]{1,40}$/D', true) ?: null;
    $out['bucket'] = $field('bucket', '/^[A-Za-z0-9][A-Za-z0-9._-]{1,62}$/D');
    $prefix = $field('prefix', '#^[A-Za-z0-9._/-]{1,200}$#D', true);
    if ($prefix !== '' && (str_starts_with($prefix, '/') || in_array('..', explode('/', $prefix), true))) {
        throw new Problem('ad_kopia_field', ['field' => 'prefix']);
    }
    $out['prefix'] = $prefix ?: null;
    $out['access'] = (string) ($secret['access_key'] ?? '');
    $out['secret'] = (string) ($secret['secret_key'] ?? '');
    if (!preg_match('/^[\x21-\x7e]{3,256}$/D', $out['access'])) {
        throw new Problem('ad_kopia_field', ['field' => 'access_key']);
    }
    if (!preg_match('/^[\x21-\x7e]{8,512}$/D', $out['secret'])) {
        throw new Problem('ad_kopia_field', ['field' => 'secret_key']);
    }
    return $out;
}

// ===================================================================== ransomware protection: S3 Object Lock

/*
 * Ransomware gangs get root first, find Kopia's keys (its config folder) and delete the backups, then
 * encrypt. S3 Object Lock in COMPLIANCE mode makes every object the storage keeps undeletable and
 * unchangeable until its date — also with the keys, also for the account's owner. Kopia sets it on every
 * object it uploads (`--retention-mode COMPLIANCE --retention-period <n>d` at create; it can't be added to
 * a repository later by the office) and, with `maintenance set --extend-object-locks=true`, extends it at
 * every full maintenance for what it still needs. Deleting old data then waits for the lock (it costs
 * storage), backups go on as before.
 *
 * Whether the bucket can: S3's GetObjectLockConfiguration, asked here — no AWS CLI in Kopia's image,
 * and Kopia's own error would come only at the create. One GET ?object-lock, signed here (AWS Signature
 * V4, advisorS3Sign(): the secret key only goes into HMACs in this process), sent by PHP's curl straight
 * to the endpoint the user typed (HTTPS only, certificate checked, no proxy, no redirect) — no command
 * line, no file. Only the access key ID travels, in the Authorization header, as in Kopia's own requests.
 */

/**
 * Does the bucket keep Object Lock? enabled (with the bucket's own default rule, if it has one: mode,
 * days) / off (the bucket was made without it) / unsupported (the provider doesn't know it — also said for
 * providers known not to offer it when their answer is unclear) / unknown (couldn't ask: why — keys,
 * denied, no_bucket, unreachable, other). $http: a stand-in for the tests.
 *
 * @param array{provider?: string, endpoint: string, region: ?string, bucket: string, access: string, secret: string} $s3
 * @return array{state: string, why?: string, code?: string|int, mode?: ?string, days?: ?int}
 */
function advisorObjectLock(array $s3, ?callable $http = null): array
{
    $http ??= 'advisorHttps';
    $endpoint = strtolower($s3['endpoint']);
    $aws = preg_match('/(^|\.)amazonaws\.com(:\d+)?$/D', $endpoint) === 1;
    $region = $s3['region'] ?? advisorS3Region($endpoint) ?? 'us-east-1';
    $answer = ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'not asked'];
    for ($try = 0; $try < 2; $try++) {
        // like Kopia's S3 library: virtual-hosted on Amazon (a bucket without dots), the path elsewhere
        $virtual = $aws && !str_contains($s3['bucket'], '.');
        $host = $virtual ? "{$s3['bucket']}.$endpoint" : $endpoint;
        $path = $virtual ? '/' : '/' . $s3['bucket'];
        $date = gmdate('Ymd\THis\Z');
        $headers = ['host' => $host, 'x-amz-content-sha256' => hash('sha256', ''), 'x-amz-date' => $date];
        $auth = advisorS3Sign('GET', $path, ['object-lock' => ''], $headers, $s3['access'], $s3['secret'], $region, $date);
        $answer = $http("https://$host$path?object-lock", ['Authorization' => $auth, 'x-amz-content-sha256' => $headers['x-amz-content-sha256'], 'x-amz-date' => $date]);
        unset($auth);
        $hint = advisorS3RegionHint($answer);
        if ((int) $answer['status'] === 200 || $hint === null || $hint === $region) {
            break;
        }
        $region = $hint;                // signed for another region: once more, as the bucket says
    }
    return advisorObjectLockState($answer, (string) ($s3['provider'] ?? 's3'));
}

/** What the bucket's answer means (advisorObjectLock()) */
function advisorObjectLockState(array $answer, string $provider): array
{
    $status = (int) ($answer['status'] ?? 0);
    $body = substr((string) ($answer['body'] ?? ''), 0, 65536);
    $code = preg_match('#<Code>\s*([A-Za-z0-9.]{1,64})\s*</Code>#', $body, $m) ? $m[1] : null;
    if ($status === 200 && preg_match('#<ObjectLockConfiguration\b#', $body)) {
        if (!preg_match('#<ObjectLockEnabled>\s*Enabled\s*</ObjectLockEnabled>#', $body)) {
            return ['state' => 'off'];
        }
        $days = null;
        if (preg_match('#<Days>\s*(\d{1,5})\s*</Days>#', $body, $d)) {
            $days = (int) $d[1];
        } elseif (preg_match('#<Years>\s*(\d{1,3})\s*</Years>#', $body, $y)) {
            $days = (int) $y[1] * 365;
        }
        return ['state' => 'enabled', 'mode' => preg_match('#<Mode>\s*(GOVERNANCE|COMPLIANCE)\s*</Mode>#', $body, $x) ? $x[1] : null, 'days' => $days];
    }
    if ($status === 404 && $code !== null && preg_match('/ObjectLock\w*NotFound|NoSuchObjectLockConfiguration/i', $code)) {
        return ['state' => 'off'];
    }
    [$state, $why] = match (true) {
        $status === 404 && $code === 'NoSuchBucket'                                        => ['unknown', 'no_bucket'],
        $status === 200                                                                    => ['unsupported', null],     // it answered something else: it doesn't know ?object-lock
        $status === 501 || $status === 405
            || in_array($code, ['NotImplemented', 'NotSupported', 'UnsupportedOperation', 'MethodNotAllowed'], true)
            || ($status === 400 && in_array($code, ['InvalidArgument', 'InvalidRequest'], true)) => ['unsupported', null],
        $status === 403 && in_array($code, ['InvalidAccessKeyId', 'SignatureDoesNotMatch', 'InvalidToken'], true) => ['unknown', 'keys'],
        $status === 403                                                                    => ['unknown', 'denied'],
        $status === 0                                                                      => ['unknown', 'unreachable'],
        default                                                                            => ['unknown', 'other'],
    };
    if ($state === 'unknown' && in_array($why, ['denied', 'other'], true) && in_array($provider, ADVISOR_NO_LOCK, true)) {
        [$state, $why] = ['unsupported', null];
    }
    return ['state' => $state] + ($why !== null ? ['why' => $why] : []) + ($state === 'unknown' && $why === 'other' ? ['code' => $code ?? $status] : []);
}

/**
 * AWS Signature Version 4 of an S3 request without a body: the Authorization header's value. $query:
 * name => value; $headers: lower-case name => value (host, x-amz-content-sha256, x-amz-date at least —
 * all of them signed). $date: Ymd\THis\Z (UTC).
 */
function advisorS3Sign(string $method, string $path, array $query, array $headers, string $access, string $secret, string $region, string $date): string
{
    ksort($query, SORT_STRING);
    $q = implode('&', array_map(fn ($k, $v) => rawurlencode((string) $k) . '=' . rawurlencode((string) $v), array_keys($query), $query));
    ksort($headers, SORT_STRING);
    $canonical = implode('', array_map(fn ($k, $v) => strtolower((string) $k) . ':' . trim((string) $v) . "\n", array_keys($headers), $headers));
    $signed = implode(';', array_map('strtolower', array_keys($headers)));
    $request = "$method\n$path\n$q\n$canonical\n$signed\n" . ($headers['x-amz-content-sha256'] ?? hash('sha256', ''));
    $day = substr($date, 0, 8);
    $scope = "$day/$region/s3/aws4_request";
    $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region, hash_hmac('sha256', $day, "AWS4$secret", true), true), true), true);
    return "AWS4-HMAC-SHA256 Credential=$access/$scope, SignedHeaders=$signed, Signature="
        . hash_hmac('sha256', "AWS4-HMAC-SHA256\n$date\n$scope\n" . hash('sha256', $request), $key);
}

/** The region an endpoint names (Amazon, Backblaze, Wasabi, MEGA, Hetzner), or null */
function advisorS3Region(string $endpoint): ?string
{
    $host = (string) preg_replace('/:\d+$/', '', strtolower($endpoint));
    return match (true) {
        (bool) preg_match('/(?:^|\.)s3[.-](?:dualstack\.)?([a-z]{2}(?:-gov)?-[a-z]+-\d+)\.amazonaws\.com$/D', $host, $m) => $m[1],
        (bool) preg_match('/^s3\.([a-z0-9-]+)\.backblazeb2\.com$/D', $host, $m)                                 => $m[1],
        (bool) preg_match('/^s3\.([a-z0-9-]+)\.wasabisys\.com$/D', $host, $m)                                   => $m[1],
        $host === 's3.wasabisys.com' || $host === 's3.amazonaws.com'                                            => 'us-east-1',
        (bool) preg_match('/^s3\.([a-z0-9-]+)\.s4\.mega\.io$/D', $host, $m)                                     => $m[1],
        (bool) preg_match('/^([a-z0-9]+)\.your-objectstorage\.com$/D', $host, $m)                               => $m[1],
        default => null,
    };
}

/** The region an S3 error names (header x-amz-bucket-region, or <Region> in its XML), or null */
function advisorS3RegionHint(array $answer): ?string
{
    $r = (string) ($answer['headers']['x-amz-bucket-region'] ?? '');
    if ($r === '' && preg_match('#<Region>\s*([a-z0-9-]{1,40})\s*</Region>#', (string) ($answer['body'] ?? ''), $m)) {
        $r = $m[1];
    }
    return preg_match('/^[a-z0-9-]{1,40}$/D', $r) ? $r : null;
}

/**
 * One HTTPS GET with PHP's curl: certificate checked, no proxy (whatever the environment says), no
 * redirect, at most 64 KB of answer, $timeout seconds.
 *
 * @return array{status: int, headers: array<string, string>, body: string, error: ?string}
 */
function advisorHttps(string $url, array $headers, int $timeout = 15): array
{
    if (!function_exists('curl_init') || !str_starts_with($url, 'https://')) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'no curl'];
    }
    $got = [];
    $body = '';
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => array_map(fn ($k, $v) => "$k: $v", array_keys($headers), $headers),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROXY          => '',
        CURLOPT_NOPROXY        => '*',
        CURLOPT_USERAGENT      => 'unraid-secretary-office',
        CURLOPT_HEADERFUNCTION => function ($c, string $line) use (&$got): int {
            if (preg_match('/^([A-Za-z0-9-]{1,64}):\s*(.{0,200}?)\s*$/', $line, $m)) {
                $got[strtolower($m[1])] = $m[2];
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => function ($c, string $chunk) use (&$body): int {
            if (strlen($body) >= 65536) {
                return 0;               // enough: stop reading
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    curl_setopt($c, defined('CURLOPT_PROTOCOLS_STR') ? CURLOPT_PROTOCOLS_STR : CURLOPT_PROTOCOLS, defined('CURLOPT_PROTOCOLS_STR') ? 'https' : CURLPROTO_HTTPS);
    $ok = curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $error = $ok === false && $status === 0 ? (curl_error($c) ?: 'no answer') : null;
    unset($c);
    return ['status' => $status, 'headers' => $got, 'body' => $body, 'error' => $error];
}

/**
 * Whether Kopia's repository keeps Object Lock — its format (`repository status --json`: blobRetention,
 * the period in nanoseconds) — and whether Kopia extends the locks (`maintenance info --json`, unless
 * $extend says it, after setting it). ['mode' => null]: no Object Lock; null: Kopia didn't say.
 *
 * @return array{mode: ?string, days?: int, extend?: ?bool}|null
 */
function advisorKopiaLock(string $docker, string $name, ?bool $extend): ?array
{
    [$exit, $out] = run([$docker, 'exec', $name, 'kopia', 'repository', 'status', '--json'], 30);
    $j = $exit === 0 ? json_decode(substr($out, (int) strpos($out, '{')), true) : null;
    if (!is_array($j)) {
        return null;
    }
    $mode = (string) ($j['blobRetention']['retentionMode'] ?? '');
    $period = $j['blobRetention']['retentionPeriod'] ?? 0;
    if (!in_array($mode, ['COMPLIANCE', 'GOVERNANCE'], true) || !is_int($period) || $period <= 0) {
        return ['mode' => null];
    }
    if ($extend === null) {
        [$x, $info] = run([$docker, 'exec', $name, 'kopia', 'maintenance', 'info', '--json'], 20);
        $i = $x === 0 ? json_decode(substr($info, (int) strpos($info, '{')), true) : null;
        $extend = is_array($i) && is_bool($i['extendObjectLocks'] ?? null) ? $i['extendObjectLocks'] : null;
    }
    return ['mode' => $mode, 'days' => intdiv($period, 86400 * 1000000000), 'extend' => $extend];
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
