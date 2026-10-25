<?php
declare(strict_types=1);

/*
 * Mr. Restori's file browser (the restore matrix of 2026-10, gap 4: cells A1, A2, A3) — functions only, loaded by
 * restore.php. Single files and folders at any depth of a moment come back from the page, three ways like one path out
 * of Kopia (restore-paths.php): next to what is there (<name>.restored-<time>), swapped in (what is there put aside
 * first, «Put back» puts it back), or into a restore folder of the office's share (restore/files/<…>-<time>/…).
 *
 *   local     a moment of a share, an app's or a VM's folder: a local snapshot (ZFS .zfs/snapshot/<name>/…, a btrfs
 *             disk's snapshot folder), what Kopia brought back, or what was pulled back from a partner (its read-only
 *             mount) — the moments of step 4 (rsMoments). A folder's content is the union of its parts on the share's
 *             pools and disks, like shfs shows it. A dataset inside is looked up in its own snapshots (rsLocate of the
 *             deep path).
 *   kept      a partner's copy kept here (the door's datasets, never mounted): the moment's snapshot mounted read-only
 *             in RAM (RUN_DIR) for the look and unmounted again, like rspPeek; a pick of it only goes into the restore
 *             folder — the partner's copy is never touched, nothing of it replaces anything here.
 *   at partner  my copy at a partner comes over whole first (Restori's «At <partner>» → «Bring back…», the door sends a
 *             unit, never a path); once here it is a moment of the share like any other.
 *
 * Browsing lists ONE folder per request (a page's request in the agent's round — never in the tick), paged and with a
 * search over the whole folder. Names come from the moment only in exactly their shape: no . or .., no control character
 * (named in `left`, never offered); a link is shown as a link (its text), never followed — no folder of the path may be
 * one. A sleeping pool or disk is never read: said, with «wake» (rsWake, only on the user's tick).
 */

const RS_BROWSE_PAGE = 200;         // entries of one folder per page
const RS_BROWSE_READ = 20000;       // names of one folder read at most (more: the search narrows it)
const RS_PICK_MAX = 100;            // files and folders picked at once
const RS_PICK_DIR = 'restore/files';                     // the restore folder in the office's share
const RS_PEEK_RE = '/^restore-peek-[0-9a-z-]{1,40}$/D';  // a kept moment mounted in RAM for a look or a copy

// ===================================================================== names and links

/**
 * The files and folders picked, as the page sends them: paths below the browse's start, each a clean relative path
 * (rsKopiaRel), each once, none inside another one picked (the folder brings it along)
 *
 * @return list<string>
 */
function rsPickItems(mixed $v): array
{
    if (!is_array($v) || !$v || count($v) > RS_PICK_MAX || !array_is_list($v)) {
        throw new Problem(is_array($v) && count($v) > RS_PICK_MAX ? 'restore_pick_too_many' : 'restore_nothing_chosen', ['max' => RS_PICK_MAX]);
    }
    $items = [];
    foreach ($v as $x) {
        $rel = rsKopiaRel($x);
        if ($rel === '') {
            throw new Problem('unknown_target', ['target' => '/']);
        }
        $items[$rel] = true;
    }
    $items = array_keys($items);
    sort($items, SORT_STRING);
    $out = [];
    foreach ($items as $rel) {
        $rel = (string) $rel;
        if (!array_filter($out, fn ($o) => under($rel, $o))) {
            $out[] = $rel;
        }
    }
    return $out;
}

/**
 * No folder of $depth parts above (and including) $path is a link — checked upward, the top of a ZFS snapshot
 * (<mountpoint>/.zfs/snapshot/<name>) standing for its dataset's mountpoint. A link inside a moment may point anywhere
 * on the server: never followed.
 */
function rsBrowseNoLink(string $path, int $depth): bool
{
    $cur = $path;
    for ($i = 0; $i < $depth; $i++) {
        clearstatcache(true, $cur);
        if (is_link($cur)) {
            return false;
        }
        $cur = dirname($cur);
        if (basename(dirname($cur)) === 'snapshot' && basename(dirname($cur, 2)) === '.zfs') {
            $cur = dirname($cur, 3);
        }
    }
    return true;
}

/**
 * One folder of a moment: the union of what its parts hold there (the first part's entry when a name lies on two, the
 * primary storage first), folders first, by name; a search ($q, any part of a name, case ignored) over the whole folder.
 * What he leaves himself (the storeroom, <name>.restored-/.aside-/.putback-<time>, .zfs) is left out; a name with a
 * control character is named in `left` (as `ls -b` shows it), never offered.
 *
 * @param list<array{base:string, path:string}> $dirs
 * @return array{entries: list<array{name:string, type:string, bytes:?int, time:?int, link:?string, bases:list<string>}>, total:int, offset:int, more:int, cut:bool, left:list<string>}
 */
function rsBrowseList(array $dirs, string $q, int $offset): array
{
    $names = $left = [];
    $cut = false;
    $q = mb_strtolower(trim($q));
    foreach ($dirs as $d) {
        $h = @opendir($d['path']);
        if (!$h) {
            continue;
        }
        while (($n = readdir($h)) !== false) {
            if ($n === '.' || $n === '..' || $n === '.zfs' || $n === RS_STOREROOM || $n === OFFICE_STOREROOM_OLD || preg_match(RS_OWN_LEFTOVER, $n)) {
                continue;
            }
            if (preg_match('/[\x00-\x1f\x7f]/', $n) || strlen($n) > RS_NAME_MAX) {
                $left[rsNameShown($n)] = true;
                continue;
            }
            if ($q !== '' && !str_contains(mb_strtolower($n), $q)) {
                continue;
            }
            if (isset($names[$n])) {
                $names[$n]['bases'][] = (string) $d['base'];
                continue;
            }
            if (count($names) >= RS_BROWSE_READ) {
                $cut = true;
                continue;
            }
            $st = @lstat("{$d['path']}/$n");
            $mode = $st ? $st['mode'] & 0170000 : 0;
            $type = $mode === 0120000 ? 'l' : ($mode === 0040000 ? 'd' : 'f');
            $names[$n] = ['name' => $n, 'type' => $type, 'bytes' => $type === 'f' && $st ? (int) $st['size'] : null, 'time' => $st ? (int) $st['mtime'] : null,
                          'link' => null, 'bases' => [(string) $d['base']], 'dir' => $d['path']];
        }
        closedir($h);
    }
    $list = array_values($names);
    usort($list, fn ($a, $b) => [$a['type'] !== 'd', mb_strtolower($a['name'])] <=> [$b['type'] !== 'd', mb_strtolower($b['name'])] ?: strcmp($a['name'], $b['name']));
    $total = count($list);
    $offset = max(0, min($offset, max(0, $total - 1)));
    $page = array_slice($list, $offset, RS_BROWSE_PAGE);
    foreach ($page as &$e) {
        if ($e['type'] === 'l') {
            $t = @readlink("{$e['dir']}/{$e['name']}");      // shown, never followed
            $e['link'] = $t === false ? '' : mb_strcut(rsNameShown($t), 0, 300, 'UTF-8');
        }
        unset($e['dir']);
    }
    unset($e);
    $left = array_keys($left);
    sort($left, SORT_STRING);
    return ['entries' => $page, 'total' => $total, 'offset' => $offset, 'more' => max(0, $total - $offset - count($page)), 'cut' => $cut, 'left' => $left];
}

// ===================================================================== where a browse starts

/**
 * Where a browse or a pick starts: a unit of his (an app's or VM's folder, a share an app binds whole) or a share of his
 * «Shares» tile (`path`), or Ms. Snapshotini's snapshot (`zsnap`, <pool>/<dataset>@<name>): its dataset's folder as a
 * share path, the moment of that snapshot, and below which folder it starts. Never the office's share or the backup
 * place (their packages come back as earlier versions), never a pool's own top.
 *
 * @return array{root:string, owner:array, rel:string, snap:string}
 */
function rsBrowseFrom(array $r, array $state, array $ctx): array
{
    $z = optText($r, 'zsnap');
    if ($z === '') {
        $root = rtrim(textField($r, 'path'), '/');
        $owner = rsUnitOwner($state, $root) ?? rsShareOwner($state, $root);
        if (!$owner || !rsCleanPath($root)) {
            throw new Problem('unknown_target', ['target' => $root]);
        }
        return ['root' => $root, 'owner' => $owner, 'rel' => '', 'snap' => optText($r, 'snap')];
    }
    if (!preg_match('#^([A-Za-z0-9_.:/-]{1,250})@([A-Za-z0-9_.:-]{1,200})$#D', $z, $m)) {
        throw new Problem('unknown_target', ['target' => $z]);
    }
    $mp = array_search($m[1], $ctx['zfs'], true);
    if ($mp === false) {
        throw new Problem('restore_browse_not_share', ['snapshot' => $z]);
    }
    $mnt = rtrim((string) ($ctx['mnt'] ?? '/mnt'), '/');
    if (!preg_match('#^' . preg_quote($mnt, '#') . '/([^/]+)/([^/]+)((?:/[^/]+)*)$#D', (string) $mp, $x) || !isset($ctx['fs'][$x[1]])) {
        throw new Problem('restore_browse_not_share', ['snapshot' => $z]);
    }
    $below = trim($x[3], '/');
    $first = $below === '' ? '' : explode('/', $below)[0];
    // the unit nearest to it: the share's top folder an app or VM owns, else the share itself
    $root = "/mnt/user/$x[2]";
    $owner = null;
    if ($first !== '') {
        $owner = rsUnitOwner($state, "$root/$first");
        if ($owner) {
            $root .= "/$first";
            $below = substr($below, strlen($first) + 1) ?: '';
        }
    }
    $owner ??= rsUnitOwner($state, $root) ?? rsShareOwner($state, $root);
    if (!$owner) {
        throw new Problem('restore_browse_not_share', ['snapshot' => $z]);
    }
    $snap = ['name' => $m[2], 'ours' => backupIsEngineSnap($m[2], $ctx['prefixes'] ?? [])];
    return ['root' => $root, 'owner' => $owner, 'rel' => $below === '' ? '' : rsKopiaRel($below), 'snap' => rsMomentKey($snap)];
}

/** A moment as the page lists it */
function rsBrowseMoment(array $m): array
{
    return ['id' => $m['id'], 'name' => $m['name'], 'time' => $m['time'], 'ours' => $m['ours'], 'kopia' => $m['kopia'], 'aside' => $m['aside'] ?? null,
            'partner' => $m['partner'] ?? null, 'bases' => array_map('strval', array_keys($m['parts']))];
}

/**
 * The folder (or item) $rel below $root in a moment, in each part holding it — a dataset inside looked up in its own
 * snapshots (rsLocate of the deep path), what Kopia or a partner brought back by the path inside it. Never through a link.
 *
 * @return array{0: list<array{base:string, path:string, snap:?string}>, 1: bool}  [where, a link was in the way]
 */
function rsBrowseSources(array $moment, string $root, string $rel, array &$ctx, bool $item = false): array
{
    $deep = $rel === '' ? $root : "$root/$rel";
    [$src] = rsItemSources($moment, $moment['kopia'] ? [] : rsLocate($deep, $ctx), $rel === '' ? '' : "/$rel");
    $depth = $rel === '' ? 0 : count(explode('/', $rel));
    $ok = [];
    $link = false;
    foreach ($src as $s) {
        // the folder itself and every folder above it inside the moment; an item picked may be a link (copied as one)
        if (!($item ? rsBrowseNoLink(dirname($s['path']), max(0, $depth - 1)) : rsBrowseNoLink($s['path'], $depth))) {
            $link = true;
        } elseif ($item || is_dir($s['path'])) {
            $ok[] = $s;
        }
    }
    return [$ok, $link];
}

/**
 * The moments of a unit and the one asked for (else the newest whose parts hold it) — and the sleeping parts (named,
 * never read; with «wake» woken first and waited for)
 *
 * @return array{moments: list<array>, moment: ?array, asleep: list<string>, woken: list<string>, share: string, failed: list<string>}
 */
function rsBrowseMoments(string $root, string $snap, bool $wake, array &$ctx): array
{
    $sp = rsSharePath($root, $ctx);
    if (!$sp || $sp[2] !== null) {
        throw new Problem('unknown_target', ['target' => $root]);
    }
    $share = $sp[0];
    $woke = ['woken' => [], 'failed' => []];
    if ($wake) {
        $sleeping = array_values(array_filter(array_map('strval', rsShareBases($share, $ctx)), fn ($b) => baseAsleep($b, $ctx['asleep'] ?? [])));
        if ($sleeping) {
            $woke = rsWake($sleeping, $ctx);
            $z = array_values(array_filter($woke['woken'], fn ($b) => ($ctx['fs'][$b] ?? '') === 'zfs'));
            if ($z) {
                rsContextZfs($ctx, $z);
            }
            $ctx['now'] = [];
        }
    }
    $places = rsLocate($root, $ctx);
    $asleep = array_values(array_map(fn ($p) => (string) $p['base'], array_filter($places, fn ($p) => $p['asleep'])));
    $moments = rsMoments($places, array_merge(rsKopiaRestored($root, $ctx), rspRestored($root)));
    $moment = null;
    foreach ($moments as $m) {
        if ($snap !== '' ? $m['id'] === $snap : (bool) rsItemSources($m, $places)[0]) {
            $moment = $m;
            break;
        }
    }
    if ($snap !== '' && !$moment && !$asleep) {
        throw new Problem('unknown_target', ['target' => $snap]);
    }
    return ['moments' => $moments, 'moment' => $moment, 'asleep' => $asleep, 'woken' => $woke['woken'], 'failed' => $woke['failed'], 'share' => $share];
}

// ===================================================================== a partner's copy kept here

/** A kept unit's moment: the pair, the unit, its snapshot named in his look — [kept, unit, snapshot] or a Problem */
function rsKeptMoment(string $id, string $unit, string $snap): array
{
    $k = isset($GLOBALS['rs']['kept_one']) ? ($GLOBALS['rs']['kept_one'])($id) : rspKeptOne($id);
    if (!$k) {
        throw new Problem('unknown_target', ['target' => $id]);
    }
    foreach ($k['units'] as $u) {
        if ($u['unit'] !== $unit) {
            continue;
        }
        if ($k['asleep']) {
            return [$k, $u, null];
        }
        foreach ($u['snaps'] as $s) {
            if ($s['name'] === $snap) {
                return [$k, $u, $s + ['id' => "{$u['dataset']}@{$s['name']}"]];
            }
        }
    }
    throw new Problem('unknown_target', ['target' => "$unit@$snap"]);
}

/** Where a kept moment is mounted for a look or a copy: RAM, never the pool (an array stop must never wait for it) */
function rsPeekDir(string $tag): string
{
    $dir = ($GLOBALS['rs']['peek_root'] ?? RUN_DIR) . "/restore-peek-$tag";
    if (!preg_match(RS_PEEK_RE, basename($dir))) {
        throw new Problem('unknown_target', ['target' => $tag]);
    }
    return $dir;
}

/** A kept moment mounted read-only at $dir (tests: a folder of their own stands in) */
function rsPeekMount(string $snapshot, string $dir): bool
{
    if (isset($GLOBALS['rs']['peek_mount'])) {
        return ($GLOBALS['rs']['peek_mount'])($snapshot, $dir);
    }
    clearstatcache(true, $dir);
    if (is_link($dir) || file_exists($dir) && !is_dir($dir) || !is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false;
    }
    if (array_intersect([$dir, realpath($dir) ?: $dir], rspMounts()[$snapshot] ?? [])) {
        return true;
    }
    return run([partnerBin('mount') ?? 'mount', '-t', 'zfs', '-o', RSP_MOUNT_OPTIONS, $snapshot, $dir], 60)[0] === 0;
}

/** …and unmounted again, its folder gone */
function rsPeekUnmount(string $dir): bool
{
    if (isset($GLOBALS['rs']['peek_umount'])) {
        return ($GLOBALS['rs']['peek_umount'])($dir);
    }
    if (!preg_match(RS_PEEK_RE, basename($dir))) {
        return false;
    }
    // the kernel names it by its real path (/var/run is Unraid's link to /run)
    $names = array_unique([$dir, realpath($dir) ?: $dir]);
    $ok = true;
    foreach (rspMounts() as $mounts) {
        if (array_intersect($names, $mounts)) {
            $ok = run([partnerBin('umount') ?? 'umount', $dir], 60)[0] === 0 && $ok;
        }
    }
    if ($ok) {
        @rmdir($dir);
    }
    return $ok;
}

// ===================================================================== «Browse…»: one folder per request

/**
 * One folder of a moment for his file browser (a page's request in the agent's round, never the tick): local — a
 * unit's or share's moments, the chosen one (else the newest holding it), the folder's entries; kept — a partner's moment
 * kept here, mounted read-only in RAM for the look. Paged (`offset`), a search over the whole folder (`q`).
 */
function rsBrowse(array $r): array
{
    $rel = rsKopiaRel($r['rel'] ?? '');
    $q = optText($r, 'q');
    $offset = is_int($r['offset'] ?? null) ? max(0, $r['offset']) : 0;
    $wake = ($r['wake'] ?? false) === true;
    $settings = $GLOBALS['rs']['settings'] ?? backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');
    $ctx = $GLOBALS['rs']['ctx'] ?? rsContext($settings);
    if (optText($r, 'kept') !== '') {
        return rsBrowseKept($r, $rel, $q, $offset, $wake, $ctx);
    }
    $state = $GLOBALS['rs']['state'] ?? (readJson(deskFile('restore')) ?: rsScan());
    $from = rsBrowseFrom($r, $state, $ctx);
    if ($rel === '' && $from['rel'] !== '') {
        $rel = $from['rel'];
    }
    $mo = rsBrowseMoments($from['root'], $from['snap'], $wake, $ctx);
    $out = ['ok' => true, 'from' => 'local', 'root' => $from['root'], 'share' => $mo['share'], 'owner' => ['kind' => $from['owner']['kind'], 'name' => $from['owner']['name']],
            'rel' => $rel, 'q' => $q, 'moments' => array_map('rsBrowseMoment', $mo['moments']), 'snap' => $mo['moment']['id'] ?? null,
            'moment' => $mo['moment'] ? rsBrowseMoment($mo['moment']) : null, 'asleep' => $mo['asleep'], 'woken' => $mo['woken'], 'failed' => $mo['failed'],
            'at_partner' => rspChips((array) ($state['partners'] ?? []), [$mo['share']]), 'pulled' => count(rspRestored($from['root'])),
            'live' => $rel === '' ? $from['root'] : "{$from['root']}/$rel", 'here' => false, 'link' => false,
            'entries' => [], 'total' => 0, 'offset' => 0, 'more' => 0, 'cut' => false, 'left' => []];
    if (!$mo['moment']) {
        return $out;
    }
    [$dirs, $link] = rsBrowseSources($mo['moment'], $from['root'], $rel, $ctx);
    $out['link'] = $link;
    $out['here'] = (bool) $dirs;
    return array_merge($out, $dirs ? rsBrowseList($dirs, $q, $offset) : []);
}

/** «Browse…» in a partner's moment kept here: mounted read-only in RAM, one folder listed, unmounted again */
function rsBrowseKept(array $r, string $rel, string $q, int $offset, bool $wake, array &$ctx): array
{
    $id = textField($r, 'kept');
    $unit = textField($r, 'unit');
    $snap = textField($r, 'ksnap');
    [$k, $u, $s] = rsKeptMoment($id, $unit, $snap);
    $woken = [];
    if ($s === null && $wake) {
        $woken = rsWake([$k['pool']], $ctx)['woken'];
        [$k, $u, $s] = rsKeptMoment($id, $unit, $snap);
    }
    $out = ['ok' => true, 'from' => 'kept', 'kept' => $id, 'partner' => $k['name'], 'unit' => $unit, 'ksnap' => $snap, 'rel' => $rel, 'q' => $q,
            'time' => $s['creation'] ?? null, 'asleep' => $s === null ? [$k['pool']] : [], 'woken' => $woken, 'here' => false, 'link' => false,
            'entries' => [], 'total' => 0, 'offset' => 0, 'more' => 0, 'cut' => false, 'left' => []];
    if ($s === null) {
        return $out;
    }
    $dir = rsPeekDir('b' . bin2hex(random_bytes(4)));
    if (!rsPeekMount($s['id'], $dir)) {
        @rmdir($dir);
        throw new Problem('restore_peek_failed', ['snapshot' => $s['id']]);
    }
    try {
        $path = $rel === '' ? $dir : "$dir/$rel";
        $depth = $rel === '' ? 0 : count(explode('/', $rel));
        $out['link'] = !rsBrowseNoLink($path, $depth);
        $out['here'] = !$out['link'] && is_dir($path);
        if ($out['here']) {
            $out = array_merge($out, rsBrowseList([['base' => $k['pool'], 'path' => $path]], $q, $offset));
        }
    } finally {
        rsPeekUnmount($dir);
    }
    return $out;
}

// ===================================================================== «Restore…» of what was picked

/** Where a pick goes in the restore folder: /mnt/user/<office's share>/restore/files/<label>-<time> */
function rsPickFolder(string $label, string $stamp, array $ctx): string
{
    $name = trim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '_', $label), '_.') ?: 'restore';
    return rtrim((string) ($ctx['user'] ?? '/mnt/user'), '/') . '/' . BACKUP_OFFICE_SHARE . '/' . RS_PICK_DIR . '/' . mb_strcut($name, 0, 80) . "-$stamp";
}

/**
 * The restore folder's share: there (the office never makes it — only on the user's click elsewhere), its parts awake
 * (or woken) — blockers into $plan; the room there
 */
function rsPickFolderCheck(array &$plan, array &$ctx, bool $wake): ?int
{
    $share = BACKUP_OFFICE_SHARE;
    $now = rsShareNow($share, $ctx);
    if ($now['state'] === 'missing') {
        $plan['blockers'][] = ['key' => 'restore_pick_no_folder', 'params' => ['share' => $share]];
        return null;
    }
    $sleeping = array_values(array_filter(array_map('strval', rsShareBases($share, $ctx)), fn ($b) => baseAsleep($b, $ctx['asleep'] ?? [])));
    if ($sleeping && $wake) {
        $woke = rsWake($sleeping, $ctx);
        $plan['options']['woken'] = array_values(array_unique(array_merge($plan['options']['woken'] ?? [], $woke['woken'])));
        $sleeping = $woke['failed'];
    }
    if ($sleeping) {
        $plan['options']['asleep'] = array_values(array_unique(array_merge($plan['options']['asleep'] ?? [], $sleeping)));
        $plan['blockers'][] = ['key' => $wake ? 'restore_wake_failed' : 'restore_asleep', 'params' => ['base' => implode(', ', $sleeping)]];
    }
    return rsShareSpace($share, $ctx)['free'];
}

/**
 * Bytes of what was picked in a kept moment (mounted for the plan): files by their size, folders walked — at most
 * 200 000 entries, else null (not known: no room check, said)
 */
function rsPickWalk(array $paths): ?int
{
    $sum = 0;
    $seen = 0;
    foreach ($paths as $p) {
        $st = @lstat($p);
        if (!$st) {
            continue;
        }
        if (($st['mode'] & 0170000) !== 0040000) {
            $sum += (int) $st['size'];
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        try {
            foreach ($it as $f) {
                if (++$seen > 200000) {
                    return null;
                }
                if (!$f->isLink() && $f->isFile()) {
                    $sum += $f->getSize();
                }
            }
        } catch (Throwable) {
            return null;
        }
    }
    return $sum;
}

/** What a pick is called in his journal: the start's name, the items (three at most, then how many more) */
function rsPickWhat(string $label, array $items): string
{
    $shown = array_slice($items, 0, 3);
    return $label . ': ' . implode(', ', $shown) . (count($items) > 3 ? ' (+' . (count($items) - 3) . ')' : '');
}

/**
 * The plan of a pick (kind «pick» asked, a «files» restore made): what was ticked in a moment of a unit or share — next to
 * what is there («copy», <name>.restored-<time>), swapped in («swap»: copied beside first, then what binds it stops, what
 * is there goes aside as <name>.aside-<time> and the copy takes its place; nothing there: put in place) or into the
 * restore folder («folder», the paths below the start kept); a partner's kept moment only into the restore folder.
 */
function rsPlanPick(array $r, string $stamp): array
{
    $items = rsPickItems($r['items'] ?? null);
    $mode = in_array($r['mode'] ?? '', ['copy', 'swap', 'folder'], true) ? (string) $r['mode'] : '';
    $wake = ($r['wake'] ?? false) === true;
    $settings = $GLOBALS['rs']['settings'] ?? backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');
    $ctx = $GLOBALS['rs']['ctx'] ?? rsContext($settings);
    if (optText($r, 'kept') !== '') {
        return rsPlanPickKept($r, $items, $wake, $stamp, $ctx);
    }
    $state = $GLOBALS['rs']['state'] ?? rsScan();
    $from = rsBrowseFrom($r, $state, $ctx);
    if ($from['rel'] !== '') {
        // from Ms. Snapshotini's snapshot of a folder deeper down: the items are below it
        $items = array_map(fn ($i) => "{$from['rel']}/$i", $items);
    }
    return rsPlanPickFor($from['root'], $from['owner'], $from['snap'], $items, $mode, $wake, $stamp, $ctx);
}

/** rsPlanPick() for a local moment (tests call it with their own server) */
function rsPlanPickFor(string $root, array $owner, string $snap, array $items, string $mode, bool $wake, string $stamp, array &$ctx): array
{
    $user = rtrim((string) ($ctx['user'] ?? '/mnt/user'), '/');
    $label = substr($root, strlen('/mnt/user/'));
    $plan = rsPlanBase('files', rsPickWhat($label, $items), ['path' => $root, 'snap' => $snap, 'mode' => $mode, 'wake' => $wake,
                                                              'owner' => $owner['kind'], 'id' => $owner['id'], 'items' => $items, 'pick' => true]);
    $plan['pick'] = true;
    $mo = rsBrowseMoments($root, $snap, $wake, $ctx);
    $share = $mo['share'];
    $now = rsShareNow($share, $ctx);
    $plan['share_now'] = $now;
    $plan['options'] = ['way' => ['copy', 'swap', 'folder'], 'asleep' => $mo['asleep'], 'woken' => $mo['woken'], 'nothing_live' => false,
                        'share' => $share, 'items' => [], 'folder' => null];
    $moment = $mo['moment'];
    if (!$moment) {
        $plan['blockers'][] = $mo['asleep'] ? ['key' => $wake ? 'restore_wake_failed' : 'restore_asleep', 'params' => ['base' => implode(', ', $mo['asleep'])]]
            : ['key' => 'restore_no_snapshot', 'params' => []];
        return $plan;
    }
    $plan['target']['snap'] = $moment['id'];
    $plan['moment'] = ['id' => $moment['id'], 'name' => $moment['name'], 'time' => $moment['time'], 'ours' => $moment['ours'], 'kopia' => $moment['kopia'],
                       'aside' => $moment['aside'] ?? null, 'partner' => $moment['partner'] ?? null, 'parts' => []];
    $folder = rsPickFolder($label, $stamp, $ctx);

    // per item: where it lies in the moment (never through a link), whether something is there now
    $infos = [];
    $sleeping = $mo['asleep'];
    foreach ($items as $rel) {
        [$src, $link] = rsBrowseSources($moment, $root, $rel, $ctx, true);
        $T = $user . substr("$root/$rel", strlen('/mnt/user'));
        clearstatcache(true, $T);
        $there = !$sleeping && (file_exists($T) || is_link($T));
        $file = $src && (is_link($src[0]['path']) || !is_dir($src[0]['path']));
        $infos[$rel] = ['src' => $src, 'link' => $link, 'T' => $T, 'there' => $there, 'file' => $file,
                        'content' => $there && (!is_dir($T) || is_link($T) || rsDirHasEntries($T))];
        $plan['options']['items'][] = ['rel' => $rel, 'kind' => $file ? 'file' : 'dir', 'there' => $infos[$rel]['content'], 'found' => (bool) $src];
        if ($link) {
            $plan['blockers'][] = ['key' => 'restore_browse_link', 'params' => ['path' => $rel]];
        } elseif (!$src) {
            $plan['blockers'][] = $sleeping ? ['key' => $wake ? 'restore_wake_failed' : 'restore_asleep', 'params' => ['base' => implode(', ', $sleeping)]]
                : ['key' => 'restore_not_in_snapshot', 'params' => ['path' => "$root/$rel"]];
        }
    }
    $nothingLive = !array_filter($infos, fn ($i) => $i['content']);
    $plan['options']['nothing_live'] = $nothingLive;
    if ($mode === '') {
        $mode = $nothingLive && !$sleeping ? 'swap' : 'copy';
    }
    $plan['target']['mode'] = $mode;
    $plan['method'] = $mode;
    if ($plan['blockers']) {
        return $plan;
    }

    // where it goes and the room there: the share (Unraid places it; never a sleeping part unasked), or the restore folder
    $free = null;
    if ($mode === 'folder') {
        $free = rsPickFolderCheck($plan, $ctx, $wake);
        $plan['options']['folder'] = $folder;
    } else {
        if ($now['state'] === 'missing') {
            $plan['blockers'][] = ['key' => 'restore_share_missing', 'params' => ['share' => $share]];
            return $plan;
        }
        if ($sleeping) {
            $plan['blockers'][] = ['key' => $wake ? 'restore_wake_failed' : 'restore_asleep', 'params' => ['base' => implode(', ', $sleeping)]];
        }
        $space = rsShareSpace($share, $ctx);
        $free = $space['free'];
        $plan['notes'][] = ['key' => $space['secondary'] !== null ? 'note.files_place2' : 'note.files_place',
                            'params' => ['path' => "/mnt/user/$share", 'primary' => $space['primary'], 'secondary' => (string) $space['secondary']]];
    }

    // the copies (the union of a folder's parts; a file or a link from the first part holding it)
    $steps = $paths = $restored = $asides = $targets = $userPaths = $openPaths = $places = [];
    foreach ($items as $rel) {
        $it = $infos[$rel];
        $to = $mode === 'folder' ? "$folder/$rel" : rsBeside($it['T'], "restored-$stamp");
        if (!rsCleanPath($to)) {
            throw new Problem('unknown_target', ['target' => $to]);
        }
        clearstatcache(true, $to);
        if (file_exists($to) || is_link($to)) {
            $plan['blockers'][] = ['key' => 'restore_exists', 'params' => ['path' => $to]];
        }
        $src = $it['file'] ? [$it['src'][0]['path']] : array_column($it['src'], 'path');
        $steps[] = ['do' => 'copy', 'from' => implode(' + ', $src), 'sources' => $src, 'to' => $to, 'dataset' => null,
                    'like' => dirname($src[0]), 'like_depth' => count(explode('/', $rel)) - 1] + ($it['file'] ? ['file' => true] : []);
        array_push($paths, ...$src);
        $restored[] = $to;
        $targets[] = $it['T'];
    }
    $sizes = rsSizes();
    $plan['sizes'] = rsSizesNeed(array_values(array_unique($paths)), $sizes, [], false) + ['free' => $free, 'what' => 'files'];
    foreach ($plan['sizes']['paths'] as $p) {
        if (rsSizeOf($sizes, $p) === null) {
            rsDuQueue($p);
        }
    }
    if (!$plan['sizes']['measuring'] && $free !== null && (int) $plan['sizes']['need'] > $free * 0.95) {
        $plan['blockers'][] = ['key' => 'restore_no_space', 'params' => ['need' => (int) $plan['sizes']['need'], 'free' => $free]];
    }
    if ($mode === 'folder') {
        $plan['steps'] = $steps;
        $plan['notes'][] = ['key' => 'note.pick_folder', 'params' => ['path' => $folder]];
        $plan['after'][] = ['key' => 'after.pick_folder', 'params' => ['path' => $folder]];
        return $plan;
    }
    if ($mode === 'copy') {
        $plan['steps'] = $steps;
        $plan['notes'][] = ['key' => 'note.pick_copy', 'params' => []];
        $plan['after'][] = ['key' => 'after.files_copy', 'params' => ['path' => implode(', ', $restored)]];
        return $plan;
    }

    // swap: what is there goes aside (a dataset of its own, or one holding one, stays — step 4 does those)
    foreach ($items as $rel) {
        $it = $infos[$rel];
        foreach (rsLocate("$root/$rel", $ctx) as $p) {
            if ($p['own_dataset']) {
                $plan['blockers'][] = ['key' => 'restore_kopia_swap_dataset', 'params' => ['dataset' => (string) $p['dataset'], 'share' => $share]];
            } elseif ($p['inner']) {
                $plan['blockers'][] = ['key' => 'restore_swap_inner', 'params' => ['list' => implode(', ', $p['inner'])]];
            }
            $places[] = (string) $p['live'];
            if ($p['exists']) {
                $openPaths[] = (string) $p['live'];
            }
        }
        $userPaths[] = "$root/$rel";
        if ($it['there']) {
            $openPaths[] = $it['T'];
        }
    }
    $paths = array_values(array_unique(array_merge($targets, $places)));
    $vms = rsVmsUsing($paths);
    foreach ($vms as $v) {
        if ($v['state'] !== 'shut off') {
            $plan['blockers'][] = ['key' => 'restore_vm_uses', 'params' => ['name' => $v['name'], 'state' => $v['state'], 'path' => $v['path']]];
        }
    }
    $openPaths = array_values(array_unique($openPaths));
    $holders = $openPaths ? rsOpenHolders($openPaths) : [];
    if ($holders) {
        $plan['blockers'][] = ['key' => 'restore_in_use', 'params' => ['names' => implode(', ', $holders), 'path' => implode(', ', $targets)]];
    }
    [$users, $parents] = rsUsers(array_values(array_unique(array_merge($userPaths, $places))), []);
    if ($vms) {
        $steps[] = ['do' => 'vms_off', 'paths' => $paths, 'names' => array_column($vms, 'name')];
    }
    if ($openPaths) {
        $steps[] = ['do' => 'not_open', 'paths' => $openPaths];
    }
    if ($users) {
        $steps[] = ['do' => 'stop', 'containers' => $users];
    }
    foreach ($items as $i => $rel) {
        $it = $infos[$rel];
        if ($it['there']) {
            $asideP = rsBeside($it['T'], "aside-$stamp");
            $steps[] = ['do' => 'aside', 'path' => $it['T'], 'to' => $asideP];
            $plan['aside'][] = ['what' => $it['content'] ? ($it['file'] ? 'file' : 'folder') : 'empty', 'from' => $it['T'], 'to' => $asideP];
            $asides[] = $asideP;
        }
        $steps[] = ['do' => 'move', 'from' => $restored[$i], 'to' => $it['T']];
    }
    if ($users) {
        $steps[] = ['do' => 'start', 'containers' => $users, 'only_stopped' => true];
        $plan['putback_pre'] = [['do' => 'stop', 'containers' => $users]];
        $plan['putback_post'] = [['do' => 'start', 'containers' => $users, 'only_stopped' => true]];
    }
    if ($parents) {
        $plan['notes'][] = ['key' => 'note.files_parents', 'params' => ['names' => implode(', ', $parents)]];
    }
    $plan['notes'][] = ['key' => $nothingLive ? 'note.files_place_in' : 'note.files_swap', 'params' => ['aside' => '']];
    $plan['stops'] = $users;
    $plan['downtime'] = $users ? 20 : 0;
    $plan['after'][] = $asides ? ['key' => 'after.files_swap', 'params' => ['aside' => implode(', ', $asides)]] : ['key' => 'after.files_place', 'params' => ['path' => implode(', ', $targets)]];
    if ($owner['kind'] === 'vm') {
        $plan['target']['vm'] = $owner['name'];
    }
    $plan['steps'] = $steps;
    return $plan;
}

/**
 * A pick out of a partner's moment kept here: only into the restore folder (his copy stays exactly as it is, nothing of
 * it replaces anything here). The job mounts the moment read-only in RAM, copies, and unmounts it again — also when a
 * copy fails or the array stops (rsJob's end: rsPeekRelease()).
 */
function rsPlanPickKept(array $r, array $items, bool $wake, string $stamp, array &$ctx): array
{
    $id = textField($r, 'kept');
    $unit = textField($r, 'unit');
    $snap = textField($r, 'ksnap');
    [$k, $u, $s] = rsKeptMoment($id, $unit, $snap);
    $woken = [];
    if ($s === null && $wake) {
        $woken = rsWake([$k['pool']], $ctx)['woken'];
        [$k, $u, $s] = rsKeptMoment($id, $unit, $snap);
    }
    $label = $k['name'] . '-' . rspUnitName($unit);
    $plan = rsPlanBase('files', rsPickWhat($k['name'] . ' ' . rspUnitName($unit), $items),
        ['kept' => $id, 'unit' => $unit, 'ksnap' => $snap, 'mode' => 'folder', 'wake' => $wake, 'owner' => 'kept', 'id' => $id, 'items' => $items, 'pick' => true]);
    $plan['pick'] = true;
    $plan['method'] = 'folder';
    $plan['options'] = ['way' => ['folder'], 'asleep' => $s === null ? [$k['pool']] : [], 'woken' => $woken, 'nothing_live' => false, 'items' => [], 'folder' => null,
                        'partner' => $k['name']];
    if ($s === null) {
        $plan['blockers'][] = ['key' => $wake ? 'restore_wake_failed' : 'restore_asleep', 'params' => ['base' => $k['pool']]];
        return $plan;
    }
    $plan['moment'] = ['id' => $s['id'], 'name' => $snap, 'time' => (int) $s['creation'], 'ours' => true, 'kopia' => false, 'aside' => null,
                       'partner' => null, 'kept' => $k['name'], 'parts' => []];
    $folder = rsPickFolder($label, $stamp, $ctx);
    $plan['options']['folder'] = $folder;
    $free = rsPickFolderCheck($plan, $ctx, $wake);
    // looked at once now (mounted for a moment); the job mounts it again at the same place
    $dir = rsPeekDir("j$stamp");
    $look = rsPeekDir('p' . bin2hex(random_bytes(4)));
    if (!rsPeekMount($s['id'], $look)) {
        @rmdir($look);
        throw new Problem('restore_peek_failed', ['snapshot' => $s['id']]);
    }
    $steps = [['do' => 'peek_mount', 'snapshot' => $s['id'], 'path' => $dir]];
    $need = 0;
    try {
        $there = [];
        foreach ($items as $rel) {
            $p = "$look/$rel";
            $depth = count(explode('/', $rel));
            if (!rsBrowseNoLink(dirname($p), $depth - 1)) {
                $plan['blockers'][] = ['key' => 'restore_browse_link', 'params' => ['path' => $rel]];
                continue;
            }
            clearstatcache(true, $p);
            if (!file_exists($p) && !is_link($p)) {
                $plan['blockers'][] = ['key' => 'restore_not_in_snapshot', 'params' => ['path' => $rel]];
                continue;
            }
            $file = is_link($p) || !is_dir($p);
            $there[] = $p;
            $plan['options']['items'][] = ['rel' => $rel, 'kind' => $file ? 'file' : 'dir', 'there' => false, 'found' => true];
            $to = "$folder/$rel";
            if (!rsCleanPath($to)) {
                throw new Problem('unknown_target', ['target' => $to]);
            }
            clearstatcache(true, $to);
            if (file_exists($to) || is_link($to)) {
                $plan['blockers'][] = ['key' => 'restore_exists', 'params' => ['path' => $to]];
            }
            $steps[] = ['do' => 'copy', 'from' => "$dir/$rel", 'sources' => ["$dir/$rel"], 'to' => $to, 'dataset' => null,
                        'like' => dirname("$dir/$rel"), 'like_depth' => $depth - 1] + ($file ? ['file' => true] : []);
        }
        $need = rsPickWalk($there);
    } finally {
        rsPeekUnmount($look);
    }
    $steps[] = ['do' => 'peek_umount', 'path' => $dir];
    $plan['sizes'] = ['need' => $need, 'free' => $free, 'measuring' => false, 'what' => 'files'];
    if ($need === null) {
        $plan['notes'][] = ['key' => 'note.pick_size_unknown', 'params' => []];
    } elseif ($free !== null && $need > $free * 0.95) {
        $plan['blockers'][] = ['key' => 'restore_no_space', 'params' => ['need' => $need, 'free' => $free]];
    }
    $plan['notes'][] = ['key' => 'note.pick_kept', 'params' => ['name' => $k['name'], 'path' => $folder]];
    $plan['after'][] = ['key' => 'after.pick_folder', 'params' => ['path' => $folder]];
    $plan['steps'] = $steps;
    return $plan;
}

// ===================================================================== the job's steps

/** A kept moment mounted read-only in RAM for the copies that follow — noted, so the job's end unmounts it in any case */
function rsDoPeekMount(array &$j, array $s): array
{
    $snap = (string) ($s['snapshot'] ?? '');
    $dir = (string) ($s['path'] ?? '');
    if (!preg_match('#^[A-Za-z0-9_.:/-]+@[A-Za-z0-9_.:-]+$#D', $snap) || !str_contains($snap, '/' . PARTNER_PARENT . '/') && !isset($GLOBALS['rs']['peek_mount'])
        || !preg_match(RS_PEEK_RE, basename($dir))) {
        return rsFail('mount_failed', ['path' => $dir]);
    }
    $j['peek'] = $dir;
    rsJournalWrite($j);
    return rsPeekMount($snap, $dir) ? ['state' => 'ok'] : rsFail('mount_failed', ['path' => $dir]);
}

function rsDoPeekUmount(array &$j, array $s): array
{
    $dir = (string) ($s['path'] ?? '');
    if (!rsPeekUnmount($dir)) {
        return ['state' => 'warning', 'note' => 'peek_busy'];
    }
    unset($j['peek']);
    return ['state' => 'ok'];
}

/** At a job's end (done, failed, stopped): a kept moment it mounted is unmounted — RAM never holds the pool at an array stop */
function rsPeekRelease(array &$j): void
{
    if (is_string($j['peek'] ?? null) && preg_match(RS_PEEK_RE, basename($j['peek']))) {
        if (rsPeekUnmount($j['peek'])) {
            unset($j['peek']);
        }
    }
}
