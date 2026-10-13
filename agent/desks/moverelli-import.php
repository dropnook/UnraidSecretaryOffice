<?php
declare(strict_types=1);

/*
 * Ms. Moverelli: taking over an earlier install of helmi1987's Smart Mover (run standalone before, usually as a User
 * Script; its smart_mover.ini lies next to the script). Functions only — loaded beside moverelli.php by the desk glob.
 *
 * The user types the folder where the old smart_mover.ini lies; she never searches. The folder is checked like Jack
 * Emby's (agent/lib/import.php: absolute, no "." or "..", the places she reads from, awake, real folders, not her own),
 * then only smart_mover.ini is read there (plain, one hard link, size-capped) and parsed as Smart Mover reads it
 * (moverelliIniParse() — never sourced, never run). What comes over:
 *   - the keys her setup knows ([GLOBAL] min_age, age_stat, move_when_used_above, move_prefer_shares, mover_debug,
 *     max_list; per share skip, min_age, age_stat, move_when_used_above), each checked like her setup page's values — a
 *     value that fails is named with why and left out (the GLOBAL key then gets Smart Mover's default, a share's «as
 *     for all») — but a bad min_age or move_when_used_above blocks the import (their fallback 0 would move at once /
 *     always); a GLOBAL key left out or empty gets Smart Mover's default, as the old install had it;
 *   - mover_bin and log_file stay hers (named); keys she doesn't know are named and left out; sections of shares this
 *     server has no cfg for are named as skipped;
 *   - the exclude lists (global_excludes, a share's excludes): their CONTENT is copied into her own lists
 *     (excludes/global.txt, excludes/share-<share>.txt) — merged, de-duplicated, comments and blank lines dropped, the
 *     order kept. Each file is read line by line, only when it is a plain text file (no NUL in its first 4 KB) in a
 *     folder she may read from (the same checks, never the office's own data folder), on awake disks, at most 2 MB and
 *     8 MB over all lists of one import. A protecting list that can't be had never silently disappears (the owner's
 *     rule): a list not read (asleep, missing, not allowed, too big, binary) or a line her lists can't take blocks the
 *     import, said with what to do; the user fixes it and looks again. EmbyCache's
 *     list (embycache_exclude.txt): while Jack Emby is hired it is not copied — the scope names Jack's own list instead,
 *     the way her setup's «Add Jack Emby's list» does; while he isn't, it is copied like any other (a frozen copy, said),
 *     also from his own data folder. Jack hired and a share of his left uncovered by the result: a warning (her real
 *     runs are refused until his list is added), no block.
 * The import replaces her settings as a whole (the old install's behaviour, not a mix) — the preview says what of hers
 * goes. Preview first (what will be set, per list how many entries, what is left out and why), then the import with the
 * preview's token (over the preview, what will be written and her files now): refused while a run of hers goes, a copy
 * of each of her files it replaces first (<file>.before-import-<time>), then her own save (her checks, her lock, her
 * writing). She never changes the old User Script or cron line — the page says to switch it off.
 */

const MOVERELLI_IMPORT_INI       = 'smart_mover.ini';
const MOVERELLI_IMPORT_INI_CAP   = 262144;          // bytes of the old smart_mover.ini
const MOVERELLI_IMPORT_LIST_CAP  = 2097152;         // bytes of one old exclude list (her own lists hold at most 2000 lines of ≤ 1000 bytes)
const MOVERELLI_IMPORT_READ_CAP  = 8388608;         // bytes read over all old exclude lists of one import (beyond: «too big», which blocks)
const MOVERELLI_IMPORT_FILES_MAX = 50;              // exclude files one scope of the old ini may name
const MOVERELLI_IMPORT_NO_FALLBACK = ['min_age', 'move_when_used_above'];     // a bad one blocks: its default (0) moves at once / always
const MOVERELLI_IMPORT_SAMPLE    = 3;               // entries of a list the preview shows
const MOVERELLI_IMPORT_EMBY      = 'embycache_exclude.txt';
const MOVERELLI_IMPORT_GLOBAL    = ['min_age', 'age_stat', 'move_when_used_above', 'move_until_used_below', 'move_prefer_shares', 'mover_debug', 'max_list', 'global_excludes'];
const MOVERELLI_IMPORT_SHARE     = ['skip', 'min_age', 'age_stat', 'move_when_used_above', 'move_until_used_below', 'excludes'];
const MOVERELLI_IMPORT_HERS      = ['mover_bin', 'log_file'];     // where the old install ran and logged: hers now

/** What the import looks at: her folder, the shares (their cfg on the flash), pools, sleeping disks, Jack Emby (the tests put their own in moverelliHost()['import']) */
function moverelliImportContext(?array $h = null): array
{
    $h ??= moverelliHost();
    $sharesDir = $h['shares_dir'];
    $over = (array) $h['import'];
    return $over + [
        'dir'       => $h['dir'],
        'fs'        => '',
        'pools'     => array_key_exists('pools', $over) ? [] : embyPools(),
        'asleep'    => array_key_exists('asleep', $over) ? [] : sleepingDisks(),
        'share_cfg' => fn (string $s): array => preg_match(MOVERELLI_SHARE, $s) ? readCfg("$sharesDir/$s.cfg") : [],
        'shares'    => moverelliShareNames($sharesDir, $h),
        'emby'      => ($h['emby'])(),
        'own'       => [$h['dir'], "$h[dir]/excludes"],
        // never read from the office's own data (as the agent reaches it, and as set) — but Jack Emby's list (emby_dirs)
        'own_below' => array_values(array_unique([DATA_DIR, DATA_DIR_USER])),
        'emby_dirs' => array_values(array_unique([EMBY_DATA, dataPathUser(EMBY_DATA)])),
        'read_cap'  => MOVERELLI_IMPORT_READ_CAP,
    ];
}

/** The folder of a request (trimmed; none typed: refused) */
function moverelliImportFolderField(array $r): string
{
    $folder = trim(optText($r, 'folder'));
    if ($folder === '') {
        throw new Problem('moverelli_import_nothing');
    }
    return $folder;
}

/** moverelli.import_preview {folder}: what would come over — nothing changes */
function moverelliImportPreview(array $r, ?array $h = null): array
{
    $h ??= moverelliHost();
    $plan = moverelliImportPlan(moverelliImportFolderField($r), moverelliImportContext($h));
    return ['ok' => true, 'preview' => $plan['preview'] + ['running' => moverelliBusy($h['dir'])]];
}

/**
 * An earlier install she finds by herself — only on the flash (it never sleeps, nothing is woken): a smart_mover.ini in
 * a User Script's folder or a plugin's folder (`MOVERELLI_IMPORT_LOOK`), a plain file. The folders, at most 5, without
 * the one she took over last (office-import.json). Her setup page offers it at the top; elsewhere the user types it.
 */
const MOVERELLI_IMPORT_LOOK = ['/boot/config/plugins/user.scripts/scripts/*/smart_mover.ini', '/boot/config/plugins/*/smart_mover.ini'];

function moverelliImportFound(?array $h = null): array
{
    $h ??= moverelliHost();
    $done = (string) (readJson("$h[dir]/office-import.json")['folder'] ?? '');
    $out = [];
    foreach ((array) ($h['import_look'] ?? MOVERELLI_IMPORT_LOOK) as $pattern) {
        foreach (glob($pattern, GLOB_NOSORT) ?: [] as $file) {
            $dir = dirname($file);
            if (is_file($file) && !is_link($file) && !is_link($dir) && $dir !== $done && !in_array($dir, $out, true)) {
                $out[] = $dir;
            }
        }
    }
    sort($out);
    return array_slice($out, 0, 5);
}

/** moverelli.import_apply {folder, token, mover_off?}: does what the preview showed — not while a run of hers goes, not when anything changed since */
function moverelliImportDo(array $r, ?array $h = null): array
{
    $h ??= moverelliHost();
    $folder = moverelliImportFolderField($r);
    $token = textField($r, 'token');
    if (moverelliBusy($h['dir'])) {
        throw new Problem('moverelli_running');
    }
    $plan = moverelliImportPlan($folder, moverelliImportContext($h));
    if (!hash_equals($plan['preview']['token'], $token)) {
        throw new Problem('moverelli_import_changed');
    }
    if (!$plan['preview']['ready']) {
        throw new Problem('moverelli_import_blocked');
    }
    $stamp = date('Ymd-His');
    $backups = [];
    // her own save: checked again, her lock held — the copies of her files made under it, right before the writing
    $saved = moverelliSave($plan['set'], $h, function () use ($h, $stamp, &$backups): void {
        $names = ['smart_mover.ini' => true];
        foreach (glob("$h[dir]/excludes/*.txt") ?: [] as $f) {
            $names['excludes/' . basename($f)] = true;
        }
        $backups = importBackup($h['dir'], $names, $stamp);
    }, moverelliConsent($r));       // Unraid's own mover goes off with it, like her save (moverelli-unraid.php)
    // the folder taken over: her page doesn't offer it again
    writeAtomic("$h[dir]/office-import.json", jsonEncode(['v' => 1, 'folder' => $folder, 'at' => time()]), 0600, 0, 0);
    $lines = count($plan['set']['global']['patterns']) + array_sum(array_map(fn ($s) => count($s['patterns']), $plan['set']['shares']));
    logLine("Ms. Moverelli: took over an earlier install from $folder (" . count($plan['set']['shares']) . " shares with rules of their own, $lines lines of what stays)"
        . ($backups ? "; copies of hers: .before-import-$stamp" : ''));
    return ['ok' => true, 'done' => ['stamp' => $stamp, 'backups' => $backups, 'shares' => count($plan['set']['shares']), 'lines' => $lines,
                                     'jack' => (bool) $plan['preview']['jack_lists']], 'state' => $saved['state']];
}

/**
 * What would come over from the old folder: the preview (for the page — values, counts, a few entries of each list, the
 * token), the settings that will be saved (`set`, agent only, in the shape moverelliCheck() takes).
 */
function moverelliImportPlan(string $folder, array $ctx): array
{
    $folder = rtrim($folder, '/');
    $real = importFolder($folder, $ctx, 'moverelli_import');
    $text = importRead($real, MOVERELLI_IMPORT_INI, MOVERELLI_IMPORT_INI_CAP, 'moverelli_import');
    if ($text === null) {
        throw new Problem('moverelli_import_none', ['path' => $folder]);
    }
    $ini = moverelliIniParse($text);
    $left = $skipped = $blocked = $lists = [];
    $show = fn (string $v): string => mb_substr($v, 0, 120);
    $budget = ['left' => (int) ($ctx['read_cap'] ?? MOVERELLI_IMPORT_READ_CAP)];
    // a bad age or fill blocks (its fallback 0 would move at once / always); other bad values keep the default, named
    $bad = function (string $sec, string $k, string $why, string $v) use (&$left, &$blocked, $show): void {
        if (in_array($k, MOVERELLI_IMPORT_NO_FALLBACK, true)) {
            $blocked[] = ['scope' => $sec, 'why' => 'value', 'key' => $k, 'reason' => $why, 'value' => $show($v)];
        } else {
            $left[] = ['section' => $sec, 'key' => $k, 'why' => 'bad', 'reason' => $why, 'value' => $show($v)];
        }
    };

    // [GLOBAL]: the keys she knows, checked; empty or left out = Smart Mover's default (what the old install had)
    $g = $ini['GLOBAL'] ?? [];
    $global = MOVERELLI_DEFAULTS + ['files' => [], 'patterns' => []];
    $taken = [];
    foreach ($g as $k => $v) {
        $k = (string) $k;
        if ($v === '' || $k === 'global_excludes') {
            continue;
        }
        if (in_array($k, MOVERELLI_IMPORT_HERS, true)) {
            $left[] = ['section' => 'GLOBAL', 'key' => $k, 'why' => 'hers', 'value' => $show($v)];
        } elseif (!in_array($k, MOVERELLI_IMPORT_GLOBAL, true)) {
            $left[] = ['section' => 'GLOBAL', 'key' => mb_substr($k, 0, 80), 'why' => 'unknown'];
        } elseif (($why = moverelliImportValue($k, $v, $value)) !== null) {
            $bad('GLOBAL', $k, $why, $v);
        } else {
            $global[$k] = $value;
            $taken[$k] = true;
        }
    }
    // the fill (2026-10-12): an old install's threshold was a gate; here it starts the fill, whose target it needs —
    // 10 points below it, unless the old ini names one (the preview shows it as not from the old install)
    if ($global['move_when_used_above'] > 0 && !isset($taken['move_until_used_below'])) {
        $global['move_until_used_below'] = max(1, $global['move_when_used_above'] - 10);
    }
    [$global['files'], $global['patterns'], $lists[]] = moverelliImportLists('GLOBAL', (string) ($g['global_excludes'] ?? ''), $ctx, $blocked, $budget);
    // what Jack Emby keeps for sure is the office's own (an earlier install never has it): left out — her save keeps the one set
    unset($global['jack_min_percent']);
    $values = [];
    foreach (array_diff(array_keys(MOVERELLI_DEFAULTS), ['jack_min_percent']) as $k) {
        $values[] = ['key' => $k, 'value' => $global[$k], 'old' => isset($taken[$k])];
    }

    // a section per share: only shares this server has a cfg for
    $shares = $rows = [];
    foreach ($ini as $sec => $vals) {
        $sec = (string) $sec;                // a section «[2024]» is an int key in PHP
        if ($sec === 'GLOBAL') {
            continue;
        }
        if (!preg_match(MOVERELLI_SHARE, $sec)) {
            $skipped[] = ['section' => mb_substr($sec, 0, 80), 'why' => 'name'];
            continue;
        }
        if (!in_array($sec, (array) $ctx['shares'], true)) {
            $skipped[] = ['section' => $sec, 'why' => 'no_share'];
            continue;
        }
        $row = ['skip' => false, 'min_age' => null, 'age_stat' => null, 'move_when_used_above' => null, 'move_until_used_below' => null, 'files' => [], 'patterns' => []];
        $given = [];
        foreach ($vals as $k => $v) {
            $k = (string) $k;
            if ($v === '' || $k === 'excludes') {
                continue;
            }
            if (!in_array($k, MOVERELLI_IMPORT_SHARE, true)) {
                $left[] = ['section' => $sec, 'key' => mb_substr($k, 0, 80), 'why' => 'unknown'];
            } elseif (($why = moverelliImportValue($k, $v, $value)) !== null) {
                $bad($sec, $k, $why, $v);
            } else {
                $row[$k] = $value;
                $given[] = ['key' => $k, 'value' => $value];
            }
        }
        if ($row['move_when_used_above'] && $row['move_until_used_below'] === null
            && ($global['move_until_used_below'] <= 0 || $global['move_until_used_below'] >= $row['move_when_used_above'])) {
            $row['move_until_used_below'] = max(1, $row['move_when_used_above'] - 10);      // the fill's target below its own trigger
            $given[] = ['key' => 'move_until_used_below', 'value' => $row['move_until_used_below']];
        }
        if (($vals['excludes'] ?? '') !== '') {
            [$row['files'], $row['patterns'], $lists[]] = moverelliImportLists($sec, (string) $vals['excludes'], $ctx, $blocked, $budget);
        }
        if ($row['skip'] || $row['min_age'] !== null || $row['age_stat'] !== null || $row['move_when_used_above'] !== null || $row['move_until_used_below'] !== null
            || $row['files'] || $row['patterns']) {
            $shares[$sec] = $row;
            $rows[] = ['share' => $sec, 'values' => $given];
        }
    }
    $lists = array_values(array_filter($lists, fn ($l) => $l['files'] !== []));
    $set = ['global' => $global, 'shares' => $shares];
    if (!$blocked) {
        $set = moverelliCheck($set, (array) $ctx['shares'], (string) $ctx['dir']);       // her setup's checks, as the save does them again
    }

    // what of hers the import replaces: her settings as a whole
    $mine = moverelliSettings((string) $ctx['dir']);
    $gone = $mine === null ? [] : array_values(array_diff(array_map('strval', array_keys($mine['shares'])), array_map('strval', array_keys($shares))));
    $preview = [
        'folder'     => $folder,
        'global'     => $values,
        'shares'     => $rows,
        'lists'      => $lists,
        'left'       => $left,
        'skipped'    => $skipped,
        'blocked'    => $blocked,
        'configured' => $mine !== null,
        'gone'       => $gone,
        'jack'       => $ctx['emby'] !== null,
        'jack_lists' => count(array_filter($lists, fn ($l) => in_array('jack', array_column($l['files'], 'how'), true))),
        // Jack Emby hired: his shares the imported settings don't keep EmbyCache's list away from (her real runs are refused then)
        'jack_uncovered' => moverelliImportJackUncovered($set, $ctx['emby']),
        'ready'      => !$blocked,
    ];
    // the token: what the page saw, what will be written, and her own files as they are now
    $now = '';
    foreach (array_merge([$ctx['dir'] . '/smart_mover.ini'], glob($ctx['dir'] . '/excludes/*.txt') ?: []) as $f) {
        $now .= basename($f) . ':' . (string) @sha1_file($f) . '|';
    }
    $preview['token'] = sha1(jsonEncode($preview) . "\n" . sha1(jsonEncode($set)) . "\n" . $now);
    return ['preview' => $preview, 'set' => $set];
}

/**
 * One value of the old ini, checked like her setup's: numbers whole and in their range, age_stat ctime|mtime, switches
 * yes|no. Returns null and sets $value, or why it can't be taken (number, range, word).
 */
function moverelliImportValue(string $key, string $v, mixed &$value): ?string
{
    $value = null;
    if (isset(MOVERELLI_RANGES[$key])) {
        if (!preg_match('/^\d{1,9}$/D', $v)) {
            return 'number';
        }
        [$min, $max] = MOVERELLI_RANGES[$key];
        $n = (int) $v;
        if ($n < $min || $n > $max) {
            return 'range';
        }
        $value = $n;
        return null;
    }
    if ($key === 'age_stat') {
        if (!in_array($v, ['ctime', 'mtime'], true)) {
            return 'word';
        }
        $value = $v;
        return null;
    }
    if (!in_array($v, ['yes', 'no'], true)) {          // move_prefer_shares, skip
        return 'word';
    }
    $value = $v === 'yes';
    return null;
}

/** Jack Emby's shares the settings to be written don't keep EmbyCache's list away from (embyMoverelliUncovered(), as her rule will see it); [] while he isn't hired */
function moverelliImportJackUncovered(array $set, ?array $emby): array
{
    if ($emby === null) {
        return [];
    }
    $look = ['global' => $set['global']['files'], 'shares' => []];
    foreach ($set['shares'] as $share => $s) {
        $look['shares'][(string) $share] = ['skip' => $s['skip'], 'excludes' => $s['files']];
    }
    return embyMoverelliUncovered($look, (array) ($emby['lists'] ?? []), array_map('strval', (array) ($emby['shares'] ?? [])));
}

/**
 * A scope's exclude files of the old ini (a comma list): their lines copied — merged, de-duplicated, comments and blank
 * lines dropped, the order kept; EmbyCache's list while Jack Emby is hired: his own list named instead. Returns [files
 * (Jack's list or none), lines, the report for the preview]. What would let something protected move blocks the import
 * ($blocked): a list not read, a line her lists can't take, more than her lists hold. $budget: bytes left to read.
 */
function moverelliImportLists(string $scope, string $value, array $ctx, array &$blocked, array &$budget): array
{
    $names = moverelliList($value);
    $files = $lines = $seen = $report = [];
    $bad = 0;
    if (count($names) > MOVERELLI_IMPORT_FILES_MAX) {
        $blocked[] = ['scope' => $scope, 'why' => 'files', 'n' => count($names), 'max' => MOVERELLI_IMPORT_FILES_MAX];
        $names = array_slice($names, 0, MOVERELLI_IMPORT_FILES_MAX);
    }
    foreach ($names as $f) {
        $entry = ['file' => mb_substr($f, 0, 300)];
        $emby = basename($f) === MOVERELLI_IMPORT_EMBY;
        if ($emby && $ctx['emby'] !== null && ($jack = (string) ($ctx['emby']['lists'][0] ?? '')) !== '') {
            if (!in_array($jack, $files, true)) {
                $files[] = $jack;
            }
            $report[] = $entry + ['how' => 'jack', 'to' => $jack];
            continue;
        }
        [$read, $why] = moverelliImportListRead($f, $ctx, $budget, $emby);
        if ($read === null) {
            $report[] = $entry + ['how' => 'not_read', 'why' => $why];
            $blocked[] = ['scope' => $scope, 'why' => 'not_read', 'file' => $entry['file'], 'reason' => $why];
            continue;
        }
        $n = $new = 0;
        foreach ($read as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            if (strlen($line) > 1000 || preg_match('/[\x00-\x1f\x7f]/', $line) || !mb_check_encoding($line, 'UTF-8')) {
                $bad++;                      // her own lists take no such line (moverelliCheck())
                continue;
            }
            $n++;
            if (!isset($seen[$line])) {
                $seen[$line] = true;
                $lines[] = $line;
                $new++;
            }
            if (count($lines) > MOVERELLI_LINES_MAX) {
                break;                       // more than her lists take: not taken at all (below) — no need to read on
            }
        }
        $report[] = $entry + ['how' => 'copied', 'lines' => $n, 'new' => $new, 'frozen' => $emby];
        if (count($lines) > MOVERELLI_LINES_MAX) {
            break;
        }
    }
    if ($bad > 0) {
        $blocked[] = ['scope' => $scope, 'why' => 'bad_lines', 'n' => $bad];
    }
    if (count($lines) > MOVERELLI_LINES_MAX) {
        $blocked[] = ['scope' => $scope, 'why' => 'lines', 'max' => MOVERELLI_LINES_MAX];
    }
    $sample = array_map(fn ($l) => mb_substr($l, 0, 200), array_slice($lines, 0, MOVERELLI_IMPORT_SAMPLE));
    return [$files, $lines, ['scope' => $scope, 'taken' => count($lines), 'bad' => $bad, 'sample' => $sample, 'files' => $report]];
}

/** Is this EmbyCache list Jack Emby's own (in his data folder, which lies in the office's)? */
function moverelliImportIsJackList(string $file, array $ctx): bool
{
    $here = realpath(($ctx['fs'] ?? '') . dirname($file));
    foreach ((array) ($ctx['emby_dirs'] ?? []) as $d) {
        if ($here !== false && $here === realpath((string) $d)) {
            return true;
        }
    }
    return false;
}

/**
 * One old exclude file, line by line: only in a folder she may read from (importFolder() — never the office's own data,
 * but Jack Emby's own EmbyCache list there), plain, a text (no NUL in its first 4 KB), at most MOVERELLI_IMPORT_LIST_CAP
 * bytes and what is left of the import's $budget. [lines, null] or [null, why: asleep|missing|not_allowed|big|binary].
 */
function moverelliImportListRead(string $file, array $ctx, array &$budget, bool $emby = false): array
{
    if (!str_starts_with($file, '/')) {
        return [null, 'not_allowed'];
    }
    if ($emby && moverelliImportIsJackList($file, $ctx)) {
        unset($ctx['own_below']);
    }
    $cap = max(0, min(MOVERELLI_IMPORT_LIST_CAP, (int) $budget['left']));
    try {
        $h = importOpen(importFolder(dirname($file), $ctx, 'moverelli_import'), basename($file), $cap, 'moverelli_import');
    } catch (Problem $p) {
        return [null, match ($p->key) {
            'moverelli_import_asleep'  => 'asleep',
            'moverelli_import_missing' => 'missing',
            'moverelli_import_big'     => 'big',
            default                    => 'not_allowed',
        }];
    }
    if ($h === null) {
        return [null, 'missing'];
    }
    $head = (string) fread($h, 4096);
    if (str_contains($head, "\0")) {
        fclose($h);
        $budget['left'] -= strlen($head);
        return [null, 'binary'];
    }
    rewind($h);
    $lines = [];
    $read = 0;
    while (($line = fgets($h)) !== false) {
        $read += strlen($line);
        if ($read > $cap) {
            break;
        }
        $lines[] = $line;
    }
    fclose($h);
    $budget['left'] -= $read;
    return $read > $cap ? [null, 'big'] : [$lines, null];
}
