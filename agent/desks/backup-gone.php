<?php
declare(strict_types=1);

/*
 * A package of an app or VM that no longer exists (2026-10-17): the engine never deletes a package (backup/README.md),
 * so `apps/<app>` or `vms/<vm>` in the backup place stays for good once its app or VM is gone — and Mr. Restori's drill
 * reported «image missing» for it on every run. Mr. Backupsy names such a package (a hint for the Team Lead, a line on
 * his page) and offers «Into the storeroom»: the package goes into Ms. Dustdevil's storeroom exactly as his let-go's
 * clearing moves it (backupLetGoMovePackage(): her run folder, the rename, kind `package`, put back by clPackageHome()) —
 * never deleted. The drill reads packages from the folder only (rsPackages()), so it no longer lists it.
 *
 *   - Gone: an app none of whose containers is there (stopped is there) and none of whose images either (an image alone
 *     may be an app about to be made again); a VM without a definition in libvirt. Docker or libvirt not answering:
 *     nothing of that kind is gone (unknown is never gone).
 *   - Nor in the engine's current plan (2026-10-28): an app setup-plan.json still names (an [app] section not gone, a
 *     proposed app|<name>| key) or a VM it lists is never gone — the engine means to back it up.
 *   - Moved (owner 2026-10-17): a stale package whose app is now backed up under another name - a Compose stack removed
 *     and made again as another stack, a reinstall with renamed containers. Another app's package written by the last
 *     run holds all its containers (by name: «same containers») or all its own folders (manifest `dirs` inside a share,
 *     never a whole share alone: «same folder»). Its entry names that app (`moved_to`) and the signal; offered the same
 *     way, never moved by itself.
 *   - Only a package the engine wrote (`engine_written`: its manifest.json in exactly the engine's shape), never anything
 *     else in the share. Never on a sleeping disk: a backup place that sleeps isn't looked at, nothing is listed.
 *   - Not while a run, the setup, a restore, a drill or a let-go's clearing is going on; the move holds the engine's lock
 *     (backupLetGoUnderLock()). A journal `letgo-<time>.json` (his let-go's kind, `mode: aside`) names the storeroom, so
 *     Ms. Dustdevil finds it also in a dataset of its own (backupLetGoTrashRoots()).
 */

/**
 * docker, virsh — the tests put stand-ins in $GLOBALS['goneHost'] (each a fn returning ?list<string>). Docker through
 * houseDocker(): his state is built in the agent's round — a silent Docker is not asked (unknown, never gone; B15)
 */
function backupGoneHost(): array
{
    $h = $GLOBALS['goneHost'] ?? [];
    $lines = function (array $cmd): ?array {
        [$exit, $out] = $cmd[0] === 'docker' ? houseDocker(array_slice($cmd, 1)) : run($cmd, 20);
        return $exit === 0 ? array_values(array_filter(array_map('trim', explode("\n", (string) $out)), fn ($l) => $l !== '')) : null;
    };
    $docker = fn () => bin('docker') !== null && file_exists('/var/run/docker.sock');
    return [
        'containers' => $h['containers'] ?? fn (): ?array => $docker() ? $lines(['docker', 'ps', '-a', '--format', '{{.Names}}']) : null,
        // every image's id and each of its names (repository:tag), one per line
        'images'     => $h['images'] ?? function () use ($docker, $lines): ?array {
            $l = $docker() ? $lines(['docker', 'images', '--no-trunc', '--format', '{{.ID}}\t{{.Repository}}:{{.Tag}}']) : null;
            return $l === null ? null : array_merge([], ...array_map(fn ($x) => explode("\t", $x), $l));
        },
        'vms'        => $h['vms'] ?? fn (): ?array => bin('virsh') !== null ? $lines(['virsh', 'list', '--all', '--name']) : null,
    ];
}

/** An image's name as Docker lists it: without docker.io/library/, with :latest when it has no tag */
function backupGoneImageRef(string $ref): string
{
    $ref = preg_replace('#^(?:docker\.io/|index\.docker\.io/)(?:library/)?#', '', trim($ref));
    if (str_contains($ref, '@')) {
        return $ref;
    }
    return preg_match('#:[^/]*$#', $ref) ? $ref : "$ref:latest";
}

/**
 * The packages whose app or VM is gone — pure. $pk: backupPackages(); $containers / $images / $vms: what Docker and
 * libvirt list now (null: not answering - nothing of that kind is gone). An app is gone when its manifest names
 * containers, none of them is there and none of their images (by id or name); a VM when libvirt has no VM of its name.
 * Moved (the manifests alone, Docker not asked): a stale package whose app another app's current package now backs up.
 * $planned: the apps and VMs the engine's current plan names (backupGonePlanned(); null: no plan) — never gone.
 *
 * @param ?array{apps: list<string>, vms: list<string>} $planned
 * @return list<array{kind: string, folder: string, name: string, path: string, time: ?int, moved_to?: string, signal?: string}>
 */
function backupGonePackages(array $pk, ?array $containers, ?array $images, ?array $vms, ?array $planned = null): array
{
    if (!empty($pk['asleep']) || empty($pk['found'])) {
        return [];
    }
    $out = [];
    $row = fn (string $kind, array $p) => ['kind' => $kind, 'folder' => (string) $p['folder'], 'name' => (string) $p['name'], 'path' => (string) $p['path'],
                                            'time' => ($p['time'] ?? 0) ?: null];
    if ($containers !== null && $images !== null) {
        $have = array_flip($containers);
        $imgs = array_flip(array_map(fn ($i) => str_starts_with($i, 'sha256:') ? $i : backupGoneImageRef($i), $images));
        foreach ((array) ($pk['apps'] ?? []) as $a) {
            $cts = array_values(array_filter((array) ($a['containers'] ?? []), fn ($c) => is_array($c) && ($c['name'] ?? '') !== ''));
            if (empty($a['engine_written']) || !$cts) {
                continue;                       // not the engine's, or nothing to judge by: never gone
            }
            $there = array_filter($cts, fn ($c) => isset($have[$c['name']])
                || (($c['image_id'] ?? '') !== '' && isset($imgs[$c['image_id']])) || (($c['image'] ?? '') !== '' && isset($imgs[backupGoneImageRef($c['image'])])));
            if (!$there) {
                $out[] = $row('app', $a);
            }
        }
    }
    // moved: a stale package of the engine's whose containers or own folders another app's current package (itself not
    // gone) holds now - said instead of gone (a reinstall with renamed containers leaves the old ones gone)
    $goneApps = array_flip(array_map(fn ($g) => $g['folder'], $out));
    $current = array_values(array_filter((array) ($pk['apps'] ?? []), fn ($b) => !empty($b['engine_written']) && empty($b['stale'])
        && !isset($goneApps[(string) $b['folder']])));
    foreach ((array) ($pk['apps'] ?? []) as $a) {
        if (empty($a['engine_written']) || empty($a['stale'])) {
            continue;
        }
        $names = array_values(array_unique(array_filter(array_map(fn ($c) => is_array($c) ? (string) ($c['name'] ?? '') : '', (array) ($a['containers'] ?? [])))));
        $own = array_values(array_unique(array_filter((array) ($a['dirs'] ?? []), fn ($d) => is_string($d) && str_contains(trim($d, '/'), '/'))));
        foreach ($current as $b) {
            if ($b['folder'] === $a['folder']) {
                continue;
            }
            $signal = $names && !array_diff($names, array_column((array) ($b['containers'] ?? []), 'name')) ? 'containers'
                : ($own && !array_diff($own, (array) ($b['dirs'] ?? [])) ? 'folders' : null);
            if ($signal !== null) {
                $out = array_values(array_filter($out, fn ($g) => !($g['kind'] === 'app' && $g['folder'] === $a['folder'])));
                $out[] = $row('app', $a) + ['moved_to' => (string) $b['name'], 'signal' => $signal];
                break;
            }
        }
    }
    if ($vms !== null) {
        $have = array_flip($vms);
        foreach ((array) ($pk['vms'] ?? []) as $v) {
            if (!empty($v['engine_written']) && ($v['name'] ?? '') !== '' && !isset($have[$v['name']])) {
                $out[] = $row('vm', $v);
            }
        }
    }
    if ($planned !== null) {
        $in = ['app' => array_flip($planned['apps'] ?? []), 'vm' => array_flip($planned['vms'] ?? [])];
        $out = array_values(array_filter($out, fn ($g) => !isset($in[$g['kind']][$g['name']]) && !isset($in[$g['kind']][$g['folder']])));
    }
    return $out;
}

/**
 * The apps and VMs the engine's current plan names (state/setup-plan.json — only entries in exactly its shape): an [app]
 * section it doesn't call gone, an app of a proposed app|<name>|<key>, a VM it lists. No plan: null.
 *
 * @return ?array{apps: list<string>, vms: list<string>}
 */
function backupGonePlanned(?string $file = null): ?array
{
    $plan = readJson($file ?? BACKUP_DATA_DIR . '/state/setup-plan.json');
    if (!is_array($plan)) {
        return null;
    }
    $apps = $vms = [];
    foreach (is_array($plan['apps'] ?? null) ? $plan['apps'] : [] as $a) {
        if (is_array($a) && is_string($a['name'] ?? null) && $a['name'] !== '' && ($a['gone'] ?? false) !== true) {
            $apps[] = $a['name'];
        }
    }
    foreach (is_array($plan['P'] ?? null) ? array_keys($plan['P']) : [] as $k) {
        if (preg_match('/^app\|(.+)\|[a-z_]+$/D', (string) $k, $m)) {
            $apps[] = $m[1];
        }
    }
    foreach (is_array($plan['vms'] ?? null) ? $plan['vms'] : [] as $v) {
        if (is_array($v) && is_string($v['name'] ?? null) && $v['name'] !== '') {
            $vms[] = $v['name'];
        }
    }
    return ['apps' => array_values(array_unique($apps)), 'vms' => array_values(array_unique($vms))];
}

/** The gone packages of the backup place as it is now (backupPackages(), cheap: Docker's lists only when an app may be gone) */
function backupGoneList(array $pk): array
{
    if (!empty($pk['asleep']) || empty($pk['found']) || (!($pk['apps'] ?? []) && !($pk['vms'] ?? []))) {
        return [];
    }
    $host = backupGoneHost();
    $cts = ($pk['apps'] ?? []) ? ($host['containers'])() : null;
    // images only when some app has none of its containers left (the common case asks Docker once, for the names)
    $maybe = $cts !== null && array_filter((array) $pk['apps'], fn ($a) => !array_intersect(array_column((array) ($a['containers'] ?? []), 'name'), $cts));
    $imgs = $maybe ? ($host['images'])() : ($cts !== null ? [] : null);
    $vms = ($pk['vms'] ?? []) ? ($host['vms'])() : null;
    return backupGonePackages($pk, $cts, $imgs, $vms, array_key_exists('gonePlanned', $GLOBALS) ? $GLOBALS['gonePlanned'] : backupGonePlanned());
}

/**
 * «Into the storeroom» for one gone package: {kind: app|vm, folder}. Looked up again on a fresh look (the place, Docker,
 * libvirt) — refused when it isn't gone (any more), the place sleeps, or a run, setup, restore, drill or clearing is
 * going on; moved under the engine's lock, each awake part of it (the share may lie on several disks) into the storeroom
 * on its own filesystem. $mnt for the tests.
 */
function backupPackageAside(array $r, string $mnt = '/mnt'): array
{
    $kind = textField($r, 'kind');
    $folder = textField($r, 'folder');
    if (!in_array($kind, ['app', 'vm'], true) || !preg_match('/^[^\/\x00-\x1f]{1,255}$/D', $folder) || $folder === '.' || $folder === '..') {
        throw new Problem('bad_request');
    }
    if ($busy = backupLetGoBusy() ?? (backupLetGoRunning() ? 'backup_running' : null)) {
        throw new Problem($busy);
    }
    if (function_exists('drillRunning') && drillRunning()) {
        throw new Problem('package_aside_drill');
    }
    $settings = backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');
    $place = $GLOBALS['goneplace'] ?? backupDumpsPath((string) backupSetting($settings, 'general', 'dumps_share', ''));
    if ($place === null) {
        throw new Problem('package_aside_gone', ['name' => $folder]);
    }
    if (!isset($GLOBALS['goneplace']) && backupPlaceAsleep($settings)) {
        throw new Problem('package_aside_asleep');
    }
    $pk = $GLOBALS['gonePackages'] ?? backupPackagesRead($place);
    $gone = array_values(array_filter(backupGoneList($pk), fn ($g) => $g['kind'] === $kind && $g['folder'] === $folder));
    if (!$gone) {
        throw new Problem('package_aside_gone', ['name' => $folder]);
    }
    $group = $kind . 's';
    $result = backupLetGoUnderLock(function () use ($place, $group, $folder, $gone, $mnt) {
        $file = backupLetGoJournalNew() ?? throw new Problem('backup_letgo_journal', ['path' => backupLetGoDir()]);
        $j = ['mode' => 'aside'] + backupLetGoJournalBase(null);
        $j['written_by'] = 'Unraid Secretary Office (Mr. Backupsy, a gone app\'s or VM\'s package)';
        $j['place'] = $place;
        $j['kept'] = [];
        backupLetGoJournalWrite($file, $j);
        $fail = function (string $what, string $desk, Problem|array $e) use (&$j, $file): void {
            $err = $e instanceof Problem ? $e->toArray() : $e;
            $j['failed'][] = ['what' => $what, 'desk' => $desk] + $err;
            backupLetGoJournalWrite($file, $j);
            logLine("Mr. Backup: could not move $what into the storeroom: " . $err['key'] . ' ' . json_encode($err['params'] ?? [], JSON_UNESCAPED_SLASHES));
        };
        $runs = [];
        $move = backupLetGoHost()['move'];
        // each awake part of the place (a sleeping one never looked at: backupPlaceAsleep() refused before)
        foreach (backupLetGoParts($place, $GLOBALS['goneRoots'] ?? clRoots(), sleepingDisks(), $mnt) as $part) {
            $p = ['group' => $group, 'name' => $folder, 'path' => "{$part['place']}/$group/$folder"];
            if (!is_link($p['path']) && is_dir($p['path']) && !is_link(dirname($p['path']))) {
                backupLetGoMovePackage($p, $part, $runs, $j, $file, $fail, $move);
            }
        }
        foreach ($runs as $run) {
            if (!$run['items']) {
                clRunTidy($run['path'], $run['root']);
            }
        }
        $j['result'] = 'done';
        $j['finished'] = time();
        backupLetGoJournalWrite($file, $j);
        logLine(sprintf('Mr. Backup: %s/%s (%s gone) into the storeroom: %d moved, %d failed (%s)', $group, $folder, $gone[0]['name'],
            count($j['moved']), count($j['failed']), basename($file)));
        return $j;
    });
    if (!$result['moved']) {
        // said in his own words: Ms. Dustdevil's keys are hers (errors.cleanup_*)
        $f = $result['failed'][0] ?? null;
        $name = $gone[0]['name'];
        throw match (true) {
            $f === null => new Problem('package_aside_gone', ['name' => $name]),
            $f['key'] === 'cleanup_own_fs' => new Problem('package_aside_own_fs', ['name' => $name]),
            default => new Problem('package_aside_failed', ['name' => $name, 'detail' => (string) ($f['params']['detail'] ?? $f['params']['path'] ?? $f['key'])]),
        };
    }
    return ['ok' => true, 'moved' => count($result['moved']), 'failed' => count($result['failed']), 'name' => $gone[0]['name'],
            'state' => isset($GLOBALS['gonePackages']) ? null : backupScan()];
}
