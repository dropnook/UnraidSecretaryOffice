<?php
declare(strict_types=1);

/*
 * Mr. Restori's ways back for what no app or VM owns, and for what lies deep inside a Kopia snapshot (1.72; the restore
 * matrix of 2026-10, gaps 1, 3 and 5) — functions only, loaded by restore.php:
 *
 *   shares    every share (not only one an app binds whole): its local snapshots, its Kopia source, its units at a
 *             partner. Step 4's plan (rsPlanFilesFor) with the share itself as its owner — the whole share (what is
 *             there now put aside first, «Put back» puts it back) or entries at its top. A partner's unit is pulled
 *             with the existing pull (rsPlanPartner) and is then a moment of the share (rspRestored).
 *   Kopia     one file or folder of a snapshot instead of the whole source: browsed one directory per request
 *             (`kopia show <root object>/<path>`, a page's request in the agent's round like the snapshot list —
 *             never in the tick), restored into the Kopia container's restore folder and from there copied beside the
 *             original or swapped in with what is there put aside (rsPlanKopia with a path). Never `maintenance`,
 *             never a policy: only `snapshot list`, `show` and `snapshot restore`.
 *   packages  an app's or VM's package in a Kopia snapshot (its own source, or the backup place's share) is an earlier
 *             version too: fetched into the restore folder (rsPlanKopia, way «stage»), then rsVersionList() offers it
 *             like a local one (rsKopiaPackages), and the existing restore runs from there.
 */

const RS_KOPIA_LS_MAX = 500;            // entries of one directory a page is shown
const RS_KOPIA_VERSIONS_MAX = 40;       // Kopia snapshots offered as earlier versions of one package (per source)
const RS_SHARE_NAME = '/^[\w .-]{1,64}$/uD';

// ===================================================================== shares

/**
 * Every share that can come back, for his «Shares» tile: emhttp's shares, the ones the engine knows and the ones a
 * partner keeps — never the office's own share or the backup place (their packages come back as earlier versions of
 * each app and VM). Per share what step 4 looks at (rsFolder of /mnt/user/<share>: its parts, their snapshots, its
 * protection), whether it is there, its Kopia source, its units at partners and who uses it.
 */
function rsSharesBack(array $state, array &$ctx, array $settings, array $engine, array $kopiaLast): array
{
    $names = [];
    foreach (array_keys((array) ($ctx['shares_ini'] ?? [])) as $n) {
        $names[(string) $n] = true;
    }
    if (!is_array($ctx['shares_ini'] ?? null)) {
        foreach (glob('/boot/config/shares/*.cfg') ?: [] as $f) {
            $names[basename($f, '.cfg')] = true;
        }
    }
    foreach (array_keys($settings) as $key) {
        if (str_starts_with((string) $key, 'share|')) {
            $names[substr((string) $key, 6)] = true;
        }
    }
    foreach ((array) ($state['partners'] ?? []) as $p) {
        foreach ((array) ($p['units'] ?? []) as $u) {
            if (str_starts_with((string) ($u['unit'] ?? ''), 'share:')) {
                $names[substr((string) $u['unit'], 6)] = true;
            }
        }
    }
    $place = (string) backupSetting($settings, 'general', 'dumps_share', '');
    unset($names[BACKUP_OFFICE_SHARE], $names[$place]);
    // who uses it: apps binding it whole or folders in it, VMs keeping their disks in it
    $users = [];
    foreach ((array) ($state['apps'] ?? []) as $a) {
        foreach (array_merge((array) $a['folders'], (array) ($a['shares'] ?? [])) as $f) {
            $users[(string) ($f['share'] ?? '')][(string) $a['name']] = !empty($f['whole']) ? 'whole' : ($users[(string) ($f['share'] ?? '')][(string) $a['name']] ?? 'folder');
        }
    }
    $vms = [];
    foreach ((array) ($state['vms'] ?? []) as $v) {
        foreach ((array) $v['folders'] as $f) {
            $vms[(string) $f['share']][] = (string) $v['name'];
        }
    }
    $out = [];
    foreach (array_keys($names) as $n) {
        $n = (string) $n;
        if (!preg_match(RS_SHARE_NAME, $n) || $n[0] === '.') {
            continue;
        }
        $mode = (string) backupSetting($settings, "share|$n", 'mode', 'off');
        $f = rsFolder("/mnt/user/$n", [], $ctx);
        $now = rsShareNow($n, $ctx);
        $out[] = [
            'name' => $n, 'path' => "/mnt/user/$n", 'state' => $now['state'], 'mode' => $mode, 'exclusive' => $now['exclusive'],
            'protection' => $f['protection'], 'protection_note' => $f['protection_note'],
            'exists' => $now['state'] !== 'missing', 'asleep' => $f['asleep'], 'places' => $f['places'],
            'snaps' => $f['snaps'], 'newest' => $f['newest'], 'oldest' => $f['oldest'],
            'kopia' => $engine['kopia'] && $mode === 'kopia' ? ['source' => $n, 'last' => $kopiaLast[$n] ?? null] : null,
            'at_partner' => rspChips((array) ($state['partners'] ?? []), [$n]),
            'pulled' => count(rspRestored("/mnt/user/$n")),
            'whole_of' => array_values(array_map('strval', array_keys(array_filter($users[$n] ?? [], fn ($w) => $w === 'whole')))),
            'folders_of' => array_values(array_map('strval', array_keys(array_filter($users[$n] ?? [], fn ($w) => $w === 'folder')))),
            'vms' => array_values(array_unique($vms[$n] ?? [])),
            'old' => $now['state'] === 'missing' ? $now['old'] : null,
        ];
    }
    usort($out, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $out;
}

/**
 * The owner of a share's restore when no app or VM owns the path: the share itself — for /mnt/user/<share> and an entry
 * at its top, only a share of his «Shares» list (null otherwise)
 */
function rsShareOwner(array $state, string $path): ?array
{
    if (!preg_match('#^/mnt/user/([^/]+)(?:/([^/]+))?$#D', $path, $m)) {
        return null;
    }
    foreach ((array) ($state['shares_back'] ?? []) as $s) {
        if (($s['name'] ?? null) === $m[1]) {
            return ['kind' => 'share', 'name' => $m[1], 'id' => $m[1], 'vm_state' => null, 'nextcloud' => []];
        }
    }
    return null;
}

// ===================================================================== Kopia: one directory, one item

/** A path inside a Kopia source as the page sends it: relative, every part a name (no . or .., no control characters) */
function rsKopiaRel(mixed $v): string
{
    if (!is_string($v)) {
        throw new Problem('bad_request');
    }
    $p = trim($v, '/');
    if ($p === '') {
        return '';
    }
    if (strlen($p) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $p)) {
        throw new Problem('unknown_target', ['target' => mb_substr($p, 0, 120)]);
    }
    foreach (explode('/', $p) as $part) {
        if ($part === '' || $part === '.' || $part === '..' || strlen($part) > RS_NAME_MAX) {
            throw new Problem('unknown_target', ['target' => mb_substr($p, 0, 120)]);
        }
    }
    return $p;
}

/** A Kopia call of a page's request or a plan (in the agent's round): through houseDocker() — tests name a stand-in */
function rsKopiaRun(array $k, int $uid, array $args, int $wait = 120): array
{
    $cmd = rsKopiaCmd((string) $k['container'], $uid, $args);
    [$exit, $out, $err] = houseDocker(array_slice($cmd, 1), $wait, $GLOBALS['rs']['docker'] ?? 'docker');
    if ($exit === 124 && houseDockerSilent()) {
        throw new Problem('docker_silent');
    }
    return [$exit, $out, $err];
}

/** The Kopia container ready to be asked, or the reason it isn't */
function rsKopiaReady(array $k): void
{
    if (!$k['container'] || !$k['running']) {
        throw new Problem($k['container'] ? 'restore_kopia_stopped' : 'restore_kopia_none', ['name' => (string) $k['container']]);
    }
    if (!$k['root']) {
        throw new Problem('restore_kopia_root');
    }
}

/** One snapshot of a source with its root object (`snapshot list --json`) — the id checked against Kopia's own list */
function rsKopiaSnap(array $k, int $uid, string $source, string $snapshot): array
{
    if (!preg_match('/^[0-9a-f]{16,64}$/D', $snapshot)) {
        throw new Problem('unknown_target', ['target' => $snapshot]);
    }
    [$exit, $out, $err] = rsKopiaRun($k, $uid, ['--no-progress', 'snapshot', 'list', "{$k['root']}/$source", '--json'], 180);
    if ($exit !== 0) {
        throw new Problem('restore_kopia_list', ['detail' => mb_substr(trim($err), 0, 300)]);
    }
    foreach (drillKopiaParse($out) as $s) {
        if ($s['id'] === $snapshot && $s['obj'] !== null) {
            return $s;
        }
    }
    throw new Problem('unknown_target', ['target' => $snapshot]);
}

/**
 * The entries of one directory of a snapshot: `kopia show <root object>/<path>` (Kopia walks the path itself) — dirs
 * first, by name; a folder's size and file count from Kopia's summary. Null when Kopia has no such directory.
 *
 * @return ?list<array{name:string, type:string, bytes:?int, files:?int, time:?int}>
 */
function rsKopiaDir(array $k, int $uid, string $obj, string $rel): ?array
{
    [$exit, $json] = rsKopiaRun($k, $uid, ['show', $rel === '' ? $obj : "$obj/$rel"], 120);
    $d = $exit === 0 ? json_decode($json, true) : null;
    if (!is_array($d) || ($d['stream'] ?? '') !== 'kopia:directory' || !is_array($d['entries'] ?? null)) {
        return null;
    }
    $int = fn (mixed $v): ?int => is_int($v) ? $v : (is_string($v) && ctype_digit($v) ? (int) $v : null);
    $out = [];
    foreach ($d['entries'] as $e) {
        // only in Kopia's shape: a name (no / . ..), a file or a folder (a link or a device stays where it is)
        if (!is_array($e) || !is_string($e['name'] ?? null) || !in_array($e['type'] ?? '', ['d', 'f'], true) || $e['name'] === ''
            || str_contains($e['name'], '/') || in_array($e['name'], ['.', '..'], true) || preg_match('/[\x00-\x1f\x7f]/', $e['name'])) {
            continue;
        }
        $summ = is_array($e['summ'] ?? null) ? $e['summ'] : [];
        $t = is_string($e['mtime'] ?? null) ? strtotime($e['mtime']) : false;
        $out[] = ['name' => $e['name'], 'type' => $e['type'], 'bytes' => $e['type'] === 'd' ? $int($summ['size'] ?? null) : $int($e['size'] ?? null),
                  'files' => $e['type'] === 'd' ? $int($summ['files'] ?? null) : null, 'time' => $t ?: null];
    }
    usort($out, fn ($a, $b) => [$a['type'] !== 'd', mb_strtolower($a['name'])] <=> [$b['type'] !== 'd', mb_strtolower($b['name'])]);
    return $out;
}

/**
 * A share the engine mounted split over its parts (<root>/<share>/<pool or disk>/…, backup.sh: a share whose parts
 * were snapshotted apart): every entry at the snapshot's top is a folder named after one of the share's pools or disks
 */
function rsKopiaSplit(?array $top, string $share, array &$ctx): bool
{
    if (!$top) {
        return false;
    }
    $bases = array_map('strval', rsShareBases($share, $ctx));
    foreach ($top as $e) {
        if ($e['type'] !== 'd' || !in_array($e['name'], $bases, true)) {
            return false;
        }
    }
    return count($bases) > 1 || count($top) > 1;
}

/**
 * Where a path of a Kopia source lies on the server: a share's source holds the share (<share>/<path>; split: the part's
 * name first), an app's or VM's own source <share>/<path>. Null for what is only staged — the office's own share and
 * the backup place (packages: they come back as earlier versions), a path at the top of an own source, a share's part.
 */
function rsKopiaLive(string $source, string $rel, bool $split, array $settings): ?string
{
    $parts = $rel === '' ? [] : explode('/', $rel);
    if (array_intersect($parts, ['', '.', '..'])) {
        return null;
    }
    if ($source[0] === '.') {
        if (count($parts) < 2) {
            return null;
        }
        $share = array_shift($parts);
    } else {
        $share = $source;
        if ($split) {
            array_shift($parts);
        }
        if (!$parts) {
            return null;
        }
    }
    if (in_array($share, [BACKUP_OFFICE_SHARE, (string) backupSetting($settings, 'general', 'dumps_share', '')], true) || !preg_match(RS_SHARE_NAME, $share) || $share[0] === '.') {
        return null;
    }
    return "/mnt/user/$share/" . implode('/', $parts);
}

/**
 * «Browse…» in a Kopia snapshot: one directory per request (a page's request, never the tick), its entries with sizes,
 * and where it lies on the server (the page offers «Restore…» beside each entry)
 */
function rsKopiaLs(array $r): array
{
    $source = textField($r, 'source');
    $snapshot = textField($r, 'snapshot');
    $rel = rsKopiaRel($r['path'] ?? '');
    $state = $GLOBALS['rs']['state'] ?? rsScan();
    if (!in_array($source, rsKopiaSources($state), true)) {
        throw new Problem('unknown_target', ['target' => $source]);
    }
    $k = $state['kopia'];
    rsKopiaReady($k);
    $uid = rsKopiaUid($k['container']);
    $snap = rsKopiaSnap($k, $uid, $source, $snapshot);
    $entries = rsKopiaDir($k, $uid, (string) $snap['obj'], $rel);
    if ($entries === null) {
        throw new Problem('restore_kopia_not_in', ['path' => $rel]);
    }
    $settings = $GLOBALS['rs']['settings'] ?? backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');
    $split = false;
    if ($source[0] !== '.') {
        $ctx = $GLOBALS['rs']['ctx'] ?? rsContext($settings);
        $split = rsKopiaSplit($rel === '' ? $entries : rsKopiaDir($k, $uid, (string) $snap['obj'], ''), $source, $ctx);
    }
    $more = max(0, count($entries) - RS_KOPIA_LS_MAX);
    return ['ok' => true, 'source' => $source, 'snapshot' => $snapshot, 'path' => $rel, 'time' => $snap['time'], 'split' => $split,
            'live' => $rel === '' ? null : rsKopiaLive($source, $rel, $split, $settings),
            'entries' => array_map(fn ($e) => $e + ['live' => rsKopiaLive($source, $rel === '' ? $e['name'] : "$rel/{$e['name']}", $split, $settings) !== null],
                array_slice($entries, 0, RS_KOPIA_LS_MAX)), 'more' => $more];
}

/**
 * Step 6 for one file or folder of a snapshot ($path inside the source): Kopia restores just it into a folder of its own
 * in the restore folder (<folder>/<source>-<time>/<name>); from there it is copied next to the original («copy», as
 * <name>.restored-<time>) or swapped in («swap»: copied beside first, then what uses it stops, what is there goes aside
 * as <name>.aside-<time> and the copy takes its place) — or it stays there («stage»: what is only staged, a package).
 * Never on a sleeping disk unasked: the path goes through /mnt/user, which may touch every part of its share.
 */
function rsPlanKopiaPath(array $r, string $stamp, array $state, string $source, string $snapshot, string $rel): array
{
    $k = $state['kopia'];
    $mode = in_array($r['mode'] ?? '', ['copy', 'swap', 'stage'], true) ? (string) $r['mode'] : '';
    $wake = !empty($r['wake']);
    $plan = rsPlanBase('kopia', ltrim($source, '.') . '/' . $rel, ['source' => $source, 'snapshot' => $snapshot, 'path' => $rel, 'mode' => $mode, 'wake' => $wake, 'live' => null]);
    if (!$k['restore']) {
        $plan['blockers'][] = ['key' => 'restore_kopia_no_mapping', 'params' => ['name' => (string) $k['container']]];
        return $plan;
    }
    rsKopiaReady($k);
    $uid = rsKopiaUid($k['container']);
    if (houseDockerSilent()) {
        throw new Problem('docker_silent');            // never a plan as root because Docker didn't say Kopia's user
    }
    $snap = rsKopiaSnap($k, $uid, $source, $snapshot);
    $parent = str_contains($rel, '/') ? substr($rel, 0, strrpos($rel, '/')) : '';
    $leaf = basename($rel);
    $entry = array_values(array_filter(rsKopiaDir($k, $uid, (string) $snap['obj'], $parent) ?? [], fn ($e) => $e['name'] === $leaf))[0] ?? null;
    $plan['source'] = ['path' => "{$k['root']}/$source/$rel", 'time' => $snap['time'], 'bytes' => $entry['bytes'] ?? null, 'files' => $entry['files'] ?? null];
    if (!$entry) {
        $plan['blockers'][] = ['key' => 'restore_kopia_not_in', 'params' => ['path' => $rel]];
        return $plan;
    }
    $settings = $GLOBALS['rs']['settings'] ?? backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');     // tests: their own
    $ctx = $GLOBALS['rs']['ctx'] ?? rsContext($settings);
    $split = $source[0] !== '.' && rsKopiaSplit(rsKopiaDir($k, $uid, (string) $snap['obj'], ''), $source, $ctx);
    $live = rsKopiaLive($source, $rel, $split, $settings);
    $file = $entry['type'] === 'f';
    if ($live === null) {
        $mode = 'stage';
    }

    // into the restore folder first: a folder of its own, made here for Kopia's user (a file needs its folder there)
    $name = trim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '_', ltrim($source, '.')), '_.') ?: 'restore';
    $dirHost = rtrim($k['restore']['source'], '/') . "/$name-$stamp";
    $dirDest = rtrim($k['restore']['dest'], '/') . "/$name-$stamp";
    $host = "$dirHost/$leaf";
    if (!rsCleanPath($host) || !rsCleanPath("$dirDest/$leaf")) {
        throw new Problem('unknown_target', ['target' => $host]);
    }
    if (file_exists($dirHost) || is_link($dirHost)) {
        $plan['blockers'][] = ['key' => 'restore_exists', 'params' => ['path' => $dirHost]];
    }
    $bytes = (int) ($entry['bytes'] ?? 0);
    $stageFree = @disk_free_space($k['restore']['source']);
    $stageFree = $stageFree === false ? null : (int) $stageFree;
    if ($bytes && $stageFree !== null && $bytes > $stageFree * 0.95) {
        $plan['blockers'][] = ['key' => 'restore_no_space', 'params' => ['need' => $bytes, 'free' => $stageFree]];
    }
    $steps = [['do' => 'kopia', 'container' => $k['container'], 'uid' => $uid, 'snapshot' => $snapshot, 'from' => "{$snap['obj']}/$rel",
               'dest' => "$dirDest/$leaf", 'host' => $host, 'make' => $dirHost, 'source' => "{$k['root']}/$source", 'path' => $rel] + ($file ? ['file' => true] : [])];
    $plan['sizes'] = ['need' => $bytes ?: null, 'free' => $stageFree, 'measuring' => false, 'what' => 'kopia'];
    $plan['notes'][] = ['key' => 'note.kopia_path', 'params' => ['path' => $rel, 'when' => $snap['time']]];
    $plan['options'] = ['way' => $live === null ? ['stage'] : ['copy', 'swap', 'stage'], 'live' => $live, 'kind' => $file ? 'file' : 'dir',
                        'nothing_live' => false, 'asleep' => [], 'woken' => [], 'split' => $split];
    if ($live === null) {
        $plan['target']['mode'] = 'stage';
        $plan['method'] = 'stage';
        $plan['steps'] = $steps;
        $plan['after'][] = ['key' => 'after.kopia_stage', 'params' => ['path' => $host]];
        return $plan;
    }
    $plan['target']['live'] = $live;

    // where it goes: the share must be there (Unraid makes it), its parts awake (or woken, asked for). $live names it as
    // the page does (/mnt/user/…), $T is where it is reached (the same, unless tests name another root)
    $sp = rsSharePath($live, $ctx);
    [$share] = $sp;
    $T = rtrim((string) ($ctx['user'] ?? '/mnt/user'), '/') . substr($live, strlen('/mnt/user'));
    $now = rsShareNow($share, $ctx);
    if ($now['state'] === 'missing') {
        $plan['blockers'][] = ['key' => 'restore_share_missing', 'params' => ['share' => $share]];
        $plan['share_now'] = $now;
        return $plan;
    }
    $sleeping = array_values(array_filter(array_map('strval', rsShareBases($share, $ctx)), fn ($b) => baseAsleep($b, $ctx['asleep'] ?? [])));
    $woke = null;
    if ($wake && $sleeping) {
        $woke = rsWake($sleeping, $ctx);
        $sleeping = array_values(array_filter($sleeping, fn ($b) => baseAsleep($b, $ctx['asleep'] ?? [])));
        if ($woke['woken'] && array_filter($woke['woken'], fn ($b) => ($ctx['fs'][$b] ?? '') === 'zfs')) {
            rsContextZfs($ctx, array_values(array_filter($woke['woken'], fn ($b) => ($ctx['fs'][$b] ?? '') === 'zfs')));
        }
    }
    $plan['woke'] = $woke;
    $plan['options']['asleep'] = $sleeping;
    $plan['options']['woken'] = $woke['woken'] ?? [];
    if ($sleeping && $mode !== 'stage') {
        // through /mnt/user shfs looks on every disk of the share: never on a sleeping one unasked
        $plan['blockers'][] = ['key' => $wake ? 'restore_wake_failed' : 'restore_asleep', 'params' => ['base' => implode(', ', $sleeping)]];
    }
    $places = $sleeping ? [] : rsLocate($live, $ctx);
    clearstatcache();
    $there = !$sleeping && (file_exists($T) || is_link($T));
    $content = $there && (!is_dir($T) || is_link($T) || rsDirHasEntries($T));
    $plan['options']['nothing_live'] = !$content && !$sleeping;
    if ($mode === '') {
        $mode = $sleeping ? 'copy' : ($content ? 'copy' : 'swap');
    }
    $plan['target']['mode'] = $mode;
    $plan['method'] = $mode;
    if ($mode === 'stage') {
        $plan['steps'] = $steps;
        $plan['after'][] = ['key' => 'after.kopia_stage', 'params' => ['path' => $host]];
        return $plan;
    }
    $to = rsBeside($T, "restored-$stamp");
    $asideP = rsBeside($T, "aside-$stamp");
    if (file_exists($to) || is_link($to)) {
        $plan['blockers'][] = ['key' => 'restore_exists', 'params' => ['path' => $to]];
    }
    $steps[] = ['do' => 'copy', 'from' => $host, 'sources' => [$host], 'to' => $to] + ($file ? ['file' => true] : []);
    // the room where Unraid puts it — and it lands twice: in the restore folder, then on the share
    $space = rsShareSpace($share, $ctx);
    $plan['sizes']['free'] = $space['free'];
    $plan['sizes']['stage_free'] = $stageFree;
    if ($bytes && $space['free'] !== null && $bytes > $space['free'] * 0.95) {
        $plan['blockers'][] = ['key' => 'restore_no_space', 'params' => ['need' => $bytes, 'free' => $space['free']]];
    }
    $plan['notes'][] = ['key' => $space['secondary'] !== null ? 'note.files_place2' : 'note.files_place',
                        'params' => ['path' => "/mnt/user/$share", 'primary' => $space['primary'], 'secondary' => (string) $space['secondary']]];
    $plan['notes'][] = ['key' => 'note.kopia_twice', 'params' => ['path' => $dirHost]];
    if ($mode === 'copy') {
        $plan['steps'] = $steps;
        $plan['notes'][] = ['key' => 'note.files_copy', 'params' => []];
        $plan['after'][] = ['key' => 'after.files_copy', 'params' => ['path' => $to]];
        $plan['after'][] = ['key' => 'after.kopia_left', 'params' => ['path' => $dirHost]];
        return $plan;
    }

    // swap: what is there goes aside (a folder that is a dataset of its own, or holds one, stays — step 4 does those)
    foreach ($places as $p) {
        if ($p['own_dataset']) {
            $plan['blockers'][] = ['key' => 'restore_kopia_swap_dataset', 'params' => ['dataset' => (string) $p['dataset'], 'share' => $share]];
        } elseif ($p['inner']) {
            $plan['blockers'][] = ['key' => 'restore_swap_inner', 'params' => ['list' => implode(', ', $p['inner'])]];
        }
    }
    $paths = array_merge([$T], array_map(fn ($p) => (string) $p['live'], $places));
    $vms = rsVmsUsing($paths);
    foreach ($vms as $v) {
        if ($v['state'] !== 'shut off') {
            $plan['blockers'][] = ['key' => 'restore_vm_uses', 'params' => ['name' => $v['name'], 'state' => $v['state'], 'path' => $v['path']]];
        }
    }
    $open = $there ? array_values(array_unique(array_merge([$T], array_map(fn ($p) => (string) $p['live'], array_filter($places, fn ($p) => $p['exists']))))) : [];
    $holders = $open ? rsOpenHolders($open) : [];
    if ($holders) {
        $plan['blockers'][] = ['key' => 'restore_in_use', 'params' => ['names' => implode(', ', $holders), 'path' => $live]];
    }
    [$users, $parents] = rsUsers($paths, []);
    if ($vms) {
        $steps[] = ['do' => 'vms_off', 'paths' => $paths, 'names' => array_column($vms, 'name')];
    }
    if ($open) {
        $steps[] = ['do' => 'not_open', 'paths' => $open];
    }
    if ($users) {
        $steps[] = ['do' => 'stop', 'containers' => $users];
    }
    if ($there) {
        $steps[] = ['do' => 'aside', 'path' => $T, 'to' => $asideP];
        $plan['aside'][] = ['what' => $content ? ($file ? 'file' : 'folder') : 'empty', 'from' => $T, 'to' => $asideP];
    }
    $steps[] = ['do' => 'move', 'from' => $to, 'to' => $T];
    if ($users) {
        $steps[] = ['do' => 'start', 'containers' => $users, 'only_stopped' => true];
        $plan['putback_pre'] = [['do' => 'stop', 'containers' => $users]];
        $plan['putback_post'] = [['do' => 'start', 'containers' => $users, 'only_stopped' => true]];
    }
    if ($parents) {
        $plan['notes'][] = ['key' => 'note.files_parents', 'params' => ['names' => implode(', ', $parents)]];
    }
    $plan['notes'][] = ['key' => $content ? 'note.files_swap' : 'note.files_place_in', 'params' => ['aside' => '']];
    $plan['stops'] = $users;
    $plan['downtime'] = $users ? 20 : 0;
    $plan['after'][] = $content ? ['key' => 'after.files_swap', 'params' => ['aside' => $asideP]] : ['key' => 'after.files_place', 'params' => ['path' => $T]];
    $plan['after'][] = ['key' => 'after.kopia_left', 'params' => ['path' => $dirHost]];
    $plan['steps'] = $steps;
    return $plan;
}

// ===================================================================== packages from Kopia

/**
 * Where a package lies inside a Kopia source: an app's or VM's own source holds the backup place as <share>/<folder>/…,
 * the backup place's share as <folder>/… — then apps|vms/<id>. Null when the backup place isn't in a share.
 */
function rsKopiaPackageRel(string $source, string $placeBase, string $kind, string $id): ?string
{
    if (!preg_match('#^/mnt/user/([^/]+)/(.+)$#D', rtrim($placeBase, '/'), $m)) {
        return null;
    }
    return ($source[0] === '.' ? "$m[1]/" : '') . "$m[2]/{$kind}s/$id";
}

/**
 * Earlier versions of a package that Kopia keeps (the page asks when «Earlier versions» opens; Kopia answers in seconds):
 * per source holding it (its own, or the backup place's share) the snapshots, newest first — each fetched with
 * rsPlanKopiaPath() (way «stage») before it can come back. What was fetched is in rsVersionList() already.
 */
function rsVersionsKopia(string $kind, string $id): array
{
    if (!in_array($kind, ['app', 'vm'], true)) {
        throw new Problem('unknown_target', ['target' => $kind]);
    }
    $state = $GLOBALS['rs']['state'] ?? rsScan();
    $item = array_values(array_filter((array) ($state[$kind . 's'] ?? []), fn ($x) => ($x['id'] ?? null) === $id))[0] ?? null;
    if (!$item) {
        throw new Problem('unknown_target', ['target' => $id]);
    }
    $k = $state['kopia'];
    $sources = array_values(array_filter((array) ($item['kopia'] ?? []), fn ($s) => in_array($s['covers'] ?? '', ['all', 'package'], true)
        && in_array($s['source'], rsKopiaSources($state) ?: [], true)));
    $place = backupDumpsPath((string) ($state['place']['share'] ?? '')) ?? '';
    if (!$sources || !$k['enabled']) {
        return ['ok' => true, 'kind' => $kind, 'id' => $id, 'versions' => []];
    }
    rsKopiaReady($k);
    $uid = rsKopiaUid($k['container']);
    $out = [];
    foreach ($sources as $s) {
        $rel = rsKopiaPackageRel((string) $s['source'], $place, $kind, $id);
        if ($rel === null) {
            continue;
        }
        [$exit, $json, $err] = rsKopiaRun($k, $uid, ['--no-progress', 'snapshot', 'list', "{$k['root']}/{$s['source']}", '--json'], 180);
        if ($exit !== 0) {
            throw new Problem('restore_kopia_list', ['detail' => mb_substr(trim($err), 0, 300)]);
        }
        $seen = [];
        foreach (drillKopiaParse($json) as $x) {
            if ($x['obj'] === null || isset($seen[$x['obj']]) || $x['incomplete'] !== '') {
                continue;                                   // the same root twice (nothing changed): once
            }
            $seen[$x['obj']] = true;
            $out[] = ['source' => (string) $s['source'], 'snapshot' => $x['id'], 'path' => $rel, 'time' => $x['time'], 'run' => $x['run'],
                      'run_time' => $x['run'] !== null ? rsRunTime($x['run']) : null, 'own' => !empty($s['own'])];
            if (count($seen) >= RS_KOPIA_VERSIONS_MAX) {
                break;
            }
        }
    }
    usort($out, fn ($a, $b) => $b['time'] <=> $a['time']);
    return ['ok' => true, 'kind' => $kind, 'id' => $id, 'versions' => $out];
}

/**
 * The packages Kopia brought back (his finished Kopia restores): a package fetched alone (way «stage»), or one inside a
 * whole source restored — each a snapshot of rsVersionList()'s shape, marked kopia, its folder in the restore folder
 *
 * @return list<array{id:string, time:int, path:string, kopia:bool}>
 */
function rsKopiaPackages(string $kind, string $id, string $placeBase): array
{
    $out = [];
    foreach (rsJournals() as $r) {
        if ($r['kind'] !== 'kopia' || !in_array($r['result'], ['ok', 'warnings'], true)) {
            continue;
        }
        $j = rsJournal($r['id']);
        $host = (string) ($j['steps'][0]['host'] ?? '');
        $source = (string) ($r['target']['source'] ?? '');
        $rel = (string) ($r['target']['path'] ?? '');
        if ($host === '' || !rsCleanPath($host) || $source === '') {
            continue;
        }
        $want = rsKopiaPackageRel($source, $placeBase, $kind, $id);
        if ($want === null) {
            continue;
        }
        // a whole source: the package inside it; one path: that path is the package (or holds it)
        $dir = $rel === '' ? "$host/$want" : ($rel === $want ? $host : (under($want, $rel) ? $host . substr($want, strlen($rel)) : null));
        if ($dir !== null && is_file("$dir/manifest.json")) {
            $time = (int) (readJson(rsDir($r['id']) . '/plan.json')['source']['time'] ?? 0) ?: (int) $r['finished'];
            $out[] = ['id' => 'kopia:' . $r['id'], 'name' => 'Kopia', 'time' => $time, 'path' => $dir, 'kopia' => true];
        }
    }
    return $out;
}
