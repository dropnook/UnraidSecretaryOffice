<?php
declare(strict_types=1);

/*
 * Ms. Snapshotini's schedules: snapshots at fixed times, with a simple
 * retention — "keep the last N", optionally "nothing older than X days".
 *
 * A plan names its targets (ZFS datasets, btrfs disks — the same ids as a
 * manual snapshot), when to run (a cron expression; the page offers hourly,
 * daily, weekly) and how many to keep. Its snapshots are called
 * auto-<plan>-YYYYMMDD-HHMM, and retention only ever looks at exactly those:
 * the backup engine's (unraidbackup-…, btrfs YYYYMMDD-HHMM), manual and held
 * snapshots are never touched.
 *
 * Running: one User Scripts entry (SNAPPLAN_SCRIPT) calls
 * "php agent.php job snapshot-plans" every few minutes, on the host itself, so
 * it works even when nobody has the office open. It runs whatever is due —
 * also a run missed while the server was off, once. The office sets the
 * entry up when the first plan is saved and switches its schedule off when
 * no plan is active any more.
 *
 * data/snapshot-plans.json        {"plans": [ … ]}
 * data/snapshot-plans-state.json  {"<plan>": {last_run, result, …}}
 */

const SNAPPLAN_SCRIPT  = 'unraid-office-snapshots';
const SNAPPLAN_CRON    = '*/5 * * * *';
const SNAPPLAN_MAX     = 20;
const SNAPPLAN_KEEP    = 1000;
const SNAPPLAN_ID      = '/^[a-z0-9][a-z0-9-]{0,23}$/';

function snapPlanFile(): string
{
    return DATA_DIR . '/snapshot-plans.json';
}

function snapPlanStateFile(): string
{
    return DATA_DIR . '/snapshot-plans-state.json';
}

/** @return list<array> */
function snapPlans(): array
{
    return array_values(array_filter((array) ((readJson(snapPlanFile()) ?? [])['plans'] ?? []), 'is_array'));
}

function snapPlanStates(): array
{
    return readJson(snapPlanStateFile()) ?? [];
}

function snapPlanSaveAll(array $plans): void
{
    writeAtomic(snapPlanFile(), jsonEncode(['plans' => array_values($plans)]));
}

/** The name a plan gives its snapshots, and the pattern that finds exactly those again */
function snapPlanName(string $id, int $time): string
{
    return "auto-$id-" . date('Ymd-Hi', $time);
}

function snapPlanPattern(string $id): string
{
    return '/^auto-' . preg_quote($id, '/') . '-\d{8}-\d{4}$/';
}

// ===================================================================== cron

/** @return list<array<int,bool>>|null  the allowed values of the five fields */
function cronFields(string $cron): ?array
{
    if (!cronValid($cron)) {
        return null;
    }
    $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
    $out = [];
    foreach (preg_split('/\s+/', trim($cron)) as $i => $field) {
        [$lo, $hi] = $ranges[$i];
        $set = [];
        foreach (explode(',', $field) as $part) {
            preg_match('#^(\*|(\d+)(?:-(\d+))?)(?:/(\d+))?$#', $part, $m);
            $from = $m[1] === '*' ? $lo : (int) $m[2];
            $to = $m[1] === '*' ? $hi : (isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (isset($m[4]) && $m[4] !== '' ? $hi : $from));
            $step = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 1;
            for ($v = $from; $v <= $to; $v += $step) {
                $set[$i === 4 && $v === 7 ? 0 : $v] = true;
            }
        }
        $out[] = $set;
    }
    $out[] = [preg_split('/\s+/', trim($cron))[2] !== '*', preg_split('/\s+/', trim($cron))[4] !== '*'];
    return $out;
}

function cronMatches(array $f, int $t): bool
{
    [$min, $hour, $dom, $mon, $dow, [$domSet, $dowSet]] = $f;
    if (!isset($min[(int) date('i', $t)], $hour[(int) date('G', $t)], $mon[(int) date('n', $t)])) {
        return false;
    }
    $d = isset($dom[(int) date('j', $t)]);
    $w = isset($dow[(int) date('w', $t)]);
    // cron's rule: with both day fields restricted, either one is enough
    return $domSet && $dowSet ? ($d || $w) : ($d && $w);
}

/** The last time the expression fired at or before $now (null: not within 40 days) */
function cronPrevious(string $cron, int $now): ?int
{
    $f = cronFields($cron);
    if (!$f) {
        return null;
    }
    $t = intdiv($now, 60) * 60;
    for ($i = 0; $i < 40 * 1440; $i++, $t -= 60) {
        if (cronMatches($f, $t)) {
            return $t;
        }
    }
    return null;
}

/** The next time the expression fires after $now */
function cronNext(string $cron, int $now): ?int
{
    $f = cronFields($cron);
    if (!$f) {
        return null;
    }
    $t = intdiv($now, 60) * 60 + 60;
    for ($i = 0; $i < 40 * 1440; $i++, $t += 60) {
        if (cronMatches($f, $t)) {
            return $t;
        }
    }
    return null;
}

// ===================================================================== plans for the page

/** Plans with their state, next run and how many of their snapshots exist now */
function snapPlansPublic(?array $scan): array
{
    $states = snapPlanStates();
    $all = snapshotAll($scan);
    $runner = backupScheduleOf(SNAPPLAN_SCRIPT);
    $out = [];
    foreach (snapPlans() as $p) {
        $pattern = snapPlanPattern($p['id']);
        $count = 0;
        $bytes = 0;
        foreach ($all as $s) {
            if (empty($s['docker']) && preg_match($pattern, (string) $s['name'])) {
                $count++;
                $bytes += (int) ($s['used'] ?? 0);
            }
        }
        $st = $states[$p['id']] ?? [];
        $out[] = $p + [
            'next'     => $p['enabled'] ? cronNext($p['cron'], max(time(), (int) ($st['last_run'] ?? 0))) : null,
            'last_run' => $st['last_run'] ?? null,
            'result'   => $st['result'] ?? null,
            'detail'   => $st['detail'] ?? [],
            'created'  => $st['created'] ?? 0,
            'deleted'  => $st['deleted'] ?? 0,
            'skipped'  => $st['skipped'] ?? [],
            'count'    => $count,
            'bytes'    => $bytes,
        ];
    }
    return ['plans' => $out, 'runner' => $runner + ['name' => SNAPPLAN_SCRIPT], 'user_scripts' => housePlugin('user.scripts')];
}

/** Like backupSchedule(), for any User Scripts entry */
function backupScheduleOf(string $name): array
{
    $script = US_DIR . "/scripts/$name/script";
    $result = ['script' => is_file($script), 'frequency' => null, 'custom' => null, 'enabled' => false];
    foreach ((array) json_decode((string) @file_get_contents(US_SCHEDULE), true) as $entry) {
        if (is_array($entry) && ($entry['script'] ?? '') === $script) {
            $result['frequency'] = (string) ($entry['frequency'] ?? '');
            $result['custom'] = (string) ($entry['custom'] ?? '');
            $result['enabled'] = !in_array($result['frequency'], ['', 'disabled'], true);
        }
    }
    return $result;
}

// ===================================================================== changing plans

function snapPlanSave(mixed $in): array
{
    if (!is_array($in)) {
        throw new Problem('missing_field', ['field' => 'plan']);
    }
    if (!housePlugin('user.scripts')) {
        throw new Problem('no_user_scripts_plugin');
    }
    $plans = snapPlans();
    $label = trim((string) ($in['label'] ?? ''));
    if ($label === '' || mb_strlen($label) > 40) {
        throw new Problem('plan_bad_label');
    }
    $id = (string) ($in['id'] ?? '');
    $isNew = $id === '';
    if ($isNew) {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($label, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss']))), '-');
        $base = substr($base !== '' ? $base : 'plan', 0, 20);
        $taken = array_column($plans, 'id');
        $id = $base;
        for ($n = 2; in_array($id, $taken, true); $n++) {
            $id = "$base-$n";
        }
        if (count($plans) >= SNAPPLAN_MAX) {
            throw new Problem('plan_too_many', ['max' => SNAPPLAN_MAX]);
        }
    } elseif (!in_array($id, array_column($plans, 'id'), true)) {
        throw new Problem('plan_unknown', ['id' => $id]);
    }
    if (!preg_match(SNAPPLAN_ID, $id)) {
        throw new Problem('plan_unknown', ['id' => $id]);
    }

    $cron = preg_replace('/\s+/', ' ', trim((string) ($in['cron'] ?? '')));
    if (!cronValid($cron)) {
        throw new Problem('bad_cron', ['cron' => $cron]);
    }
    $state = snapshotScan(false);
    $known = [];
    foreach ($state['zfs']['volumes'] as $v) {
        $known[$v['id']] = true;
    }
    foreach ($state['btrfs']['devices'] as $d) {
        $known["btrfs:{$d['mount']}"] = true;
    }
    $targets = array_values(array_unique(array_filter((array) ($in['targets'] ?? []), 'is_string')));
    if (!$targets) {
        throw new Problem('plan_no_targets');
    }
    foreach ($targets as $t) {
        if (!isset($known[$t])) {
            throw new Problem('unknown_target', ['target' => $t]);
        }
    }
    $keep = (int) ($in['keep'] ?? 0);
    $days = (int) ($in['max_days'] ?? 0);
    if ($keep < 1 || $keep > SNAPPLAN_KEEP || $days < 0 || $days > 3650) {
        throw new Problem('plan_bad_keep');
    }

    $old = [];
    foreach ($plans as $p) {
        if ($p['id'] === $id) {
            $old = $p;
        }
    }
    $plan = [
        'id'          => $id,
        'label'       => $label,
        'targets'     => $targets,
        'recursive'   => !empty($in['recursive']),
        'cron'        => $cron,
        'keep'        => $keep,
        'max_days'    => $days,
        'skip_asleep' => !empty($in['skip_asleep']),
        'enabled'     => array_key_exists('enabled', $in) ? !empty($in['enabled']) : ($old['enabled'] ?? true),
        'since'       => $old['since'] ?? time(),
    ];
    $plans = array_values(array_filter($plans, fn ($p) => $p['id'] !== $id));
    $plans[] = $plan;
    snapPlanSaveAll($plans);
    if ($isNew) {
        // the first run is the next time the plan fires, not right now
        $states = snapPlanStates();
        $states[$id] = ['last_run' => time()];
        writeAtomic(snapPlanStateFile(), jsonEncode($states));
    }
    snapPlanRunner();
    logLine("Ms. Snapshotini: plan $id saved ($cron, keep $keep" . ($days ? ", max $days days" : '') . ')');
    return ['ok' => true, 'id' => $id, 'state' => snapshotScan(false)];
}

function snapPlanToggle(string $id, bool $on): array
{
    $plans = snapPlans();
    $found = false;
    foreach ($plans as &$p) {
        if ($p['id'] === $id) {
            $p['enabled'] = $on;
            $found = true;
        }
    }
    unset($p);
    if (!$found) {
        throw new Problem('plan_unknown', ['id' => $id]);
    }
    snapPlanSaveAll($plans);
    if ($on) {
        // paused for a while: start again at the next time, don't catch up on everything missed
        $states = snapPlanStates();
        $states[$id]['last_run'] = time();
        writeAtomic(snapPlanStateFile(), jsonEncode($states));
    }
    snapPlanRunner();
    logLine("Ms. Snapshotini: plan $id " . ($on ? 'resumed' : 'paused'));
    return ['ok' => true, 'state' => snapshotScan(false)];
}

/** Removes the plan; its snapshots stay (they are ordinary snapshots now) */
function snapPlanDelete(string $id): array
{
    $plans = snapPlans();
    $left = array_values(array_filter($plans, fn ($p) => $p['id'] !== $id));
    if (count($left) === count($plans)) {
        throw new Problem('plan_unknown', ['id' => $id]);
    }
    snapPlanSaveAll($left);
    $states = snapPlanStates();
    unset($states[$id]);
    writeAtomic(snapPlanStateFile(), jsonEncode($states));
    snapPlanRunner();
    logLine("Ms. Snapshotini: plan $id removed");
    return ['ok' => true, 'state' => snapshotScan(false)];
}

/**
 * The User Scripts entry that runs the plans: written while a plan is
 * active, its schedule switched off when none is.
 */
function snapPlanRunner(): void
{
    $active = array_filter(snapPlans(), fn ($p) => !empty($p['enabled']));
    $dir = US_DIR . '/scripts/' . SNAPPLAN_SCRIPT;
    if (!$active && !is_dir($dir)) {
        return;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new Problem('command_failed', ['detail' => "cannot create $dir"]);
    }
    $agent = userSharePath(OFFICE_DIR . '/agent/agent.php');
    $script = "#!/bin/bash\n"
        . "#description=Ms. Snapshotini's schedules (Unraid Secretary Office): takes the snapshots that are due and removes the plan's old ones. Plans are managed in the office, not here.\n"
        . "#arrayStarted=true\n"
        . 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($agent) . " job snapshot-plans\n";
    if ((string) @file_get_contents("$dir/script") !== $script) {
        writeAtomic("$dir/script", $script, 0755, 0, 0);
    }
    if (!is_file("$dir/name")) {
        @file_put_contents("$dir/name", SNAPPLAN_SCRIPT);
    }
    userScriptSchedule(SNAPPLAN_SCRIPT, $active ? SNAPPLAN_CRON : null);
}

// ===================================================================== running

/** "php agent.php job snapshot-plans": every plan that is due, one after the other */
function snapPlansRunDue(): int
{
    @mkdir(RUN_DIR, 0700, true);
    $lock = fopen(RUN_DIR . '/snapshot-plans.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return 0;                              // the last round is still at work
    }
    $now = time();
    foreach (snapPlans() as $p) {
        if (empty($p['enabled'])) {
            continue;
        }
        $last = (int) (snapPlanStates()[$p['id']]['last_run'] ?? 0);
        $due = cronPrevious($p['cron'], $now);
        if ($due !== null && $due > $last) {
            snapPlanRun($p, $now);
        }
    }
    flock($lock, LOCK_UN);
    return 0;
}

/** One run of a plan: snapshots, then retention of exactly this plan's snapshots */
function snapPlanRun(array $plan, int $now): array
{
    $id = $plan['id'];
    $state = snapshotScan(false);
    $volumes = [];
    foreach ($state['zfs']['volumes'] as $v) {
        $volumes[$v['id']] = $v;
    }
    $devices = [];
    foreach ($state['btrfs']['devices'] as $d) {
        $devices["btrfs:{$d['mount']}"] = $d;
    }
    $asleep = sleepingDisks();
    $poolAsleep = function (string $pool) use ($asleep): bool {
        foreach ($asleep as $name => $sleeping) {
            if ($sleeping && preg_match('/^' . preg_quote($pool, '/') . '\d*$/', (string) $name)) {
                return true;
            }
        }
        return false;
    };

    $take = [];
    $skipped = [];
    $missing = [];
    foreach ($plan['targets'] as $t) {
        if (isset($volumes[$t])) {
            $sleeping = $poolAsleep($volumes[$t]['pool']);
        } elseif (isset($devices[$t])) {
            $sleeping = (bool) $devices[$t]['asleep'];
        } else {
            $missing[] = $t;
            continue;
        }
        if ($sleeping && !empty($plan['skip_asleep'])) {
            $skipped[] = $t;
        } else {
            $take[] = $t;
        }
    }

    $failures = [];
    $created = [];
    if ($take) {
        try {
            $r = snapshotCreate(['name' => snapPlanName($id, $now), 'targets' => $take, 'recursive' => !empty($plan['recursive'])]);
            $created = $r['created'] ?? [];
            $failures = $r['failures'] ?? [];
        } catch (Problem $p) {
            $failures[] = ['key' => $p->key, 'params' => $p->params];
        }
    }
    foreach ($missing as $t) {
        $failures[] = ['key' => 'unknown_target', 'params' => ['target' => $t]];
    }

    // retention: only this plan's snapshots, only on what was taken now (sleeping disks stay asleep)
    $deleted = 0;
    if ($take) {
        $btrfsMounts = [];
        foreach ($take as $t) {
            if (isset($devices[$t])) {
                $btrfsMounts[] = $devices[$t]['mount'];
            }
        }
        $fresh = snapshotScan(false, false, $btrfsMounts);
        $doomed = snapPlanDoomed($plan, $take, snapshotAll($fresh), $now);
        if ($doomed) {
            $r = snapshotDelete($doomed, false);
            $deleted = count($r['deleted'] ?? []);
            foreach ($r['failures'] ?? [] as $f) {
                if ($f['key'] !== 'held') {        // a snapshot someone holds stays on purpose
                    $failures[] = $f;
                }
            }
        }
    }

    $result = $failures ? ($created ? 'partly' : 'failed') : ($take ? 'ok' : 'skipped');
    $states = snapPlanStates();
    $states[$id] = ['last_run' => $now, 'result' => $result, 'detail' => array_slice($failures, 0, 5),
                    'created' => count($created), 'deleted' => $deleted, 'skipped' => $skipped];
    writeAtomic(snapPlanStateFile(), jsonEncode($states));
    logLine(sprintf('Ms. Snapshotini: plan %s — %d created, %d removed%s%s', $id, count($created), $deleted,
        $skipped ? ', skipped (asleep): ' . implode(', ', $skipped) : '', $failures ? ', ' . count($failures) . ' problem(s)' : ''));
    if ($failures && is_executable('/usr/local/emhttp/webGui/scripts/notify')) {
        run(['/usr/local/emhttp/webGui/scripts/notify', '-e', 'Unraid Secretary Office', '-s', "Snapshot plan $id",
             '-d', "Ms. Snapshotini: plan \"{$plan['label']}\" had " . count($failures) . ' problem(s) — see the office.', '-i', 'warning'], 20);
    }
    return $states[$id];
}

/**
 * Which of this plan's snapshots go: per dataset/disk the newest `keep` stay,
 * and with max_days nothing older than that — but never the one just taken.
 *
 * @param list<string> $take  the targets of this run
 * @return list<string> snapshot ids
 */
function snapPlanDoomed(array $plan, array $take, array $all, int $now): array
{
    $pattern = snapPlanPattern($plan['id']);
    $volumes = [];
    foreach ($take as $t) {
        $volumes[$t] = true;
    }
    $roots = [];
    foreach ($take as $t) {
        if (str_starts_with($t, 'zfs:')) {
            $roots[] = substr($t, 4);
        }
    }
    $groups = [];
    foreach ($all as $s) {
        if (!empty($s['docker']) || ($s['fs'] ?? '') === 'vm' || !preg_match($pattern, (string) $s['name'])) {
            continue;
        }
        $mine = isset($volumes[$s['vol'] ?? '']);
        if (!$mine && !empty($plan['recursive']) && $s['fs'] === 'zfs') {
            foreach ($roots as $r) {
                if (str_starts_with((string) $s['ds'], "$r/")) {
                    $mine = true;
                }
            }
        }
        if ($mine) {
            $groups[$s['ds']][] = $s;
        }
    }
    $limit = $plan['max_days'] ? $now - $plan['max_days'] * 86400 : null;
    $doomed = [];
    foreach ($groups as $list) {
        // newest first, by the time in the name (btrfs has no creation time of its own here)
        usort($list, fn ($a, $b) => strcmp(substr($b['name'], -13), substr($a['name'], -13)));
        foreach ($list as $i => $s) {
            $when = DateTime::createFromFormat('Ymd-Hi', substr($s['name'], -13))?->getTimestamp() ?? (int) ($s['t'] ?? $now);
            if ($i === 0) {
                continue;                       // the newest always stays
            }
            if ($i >= $plan['keep'] || ($limit !== null && $when < $limit)) {
                $doomed[] = $s['id'];
            }
        }
    }
    return $doomed;
}

/** "Run now" from the page */
function snapPlanRunNow(string $id): array
{
    foreach (snapPlans() as $p) {
        if ($p['id'] === $id) {
            $result = snapPlanRun($p, time());
            return ['ok' => true, 'result' => $result, 'state' => snapshotScan(false)];
        }
    }
    throw new Problem('plan_unknown', ['id' => $id]);
}

// ===================================================================== checks (for the caretaker)

function snapPlanChecks(): array
{
    $out = [];
    $plans = snapPlans();
    $active = array_filter($plans, fn ($p) => !empty($p['enabled']));
    if ($active) {
        $out[] = finding('plans_user_scripts', 'required', housePlugin('user.scripts'), [], 'apps');
        $runner = backupScheduleOf(SNAPPLAN_SCRIPT);
        $out[] = finding('plans_runner', 'required', $runner['script'] && $runner['enabled'], ['name' => SNAPPLAN_SCRIPT], '#/snapshot');
        $states = snapPlanStates();
        foreach ($active as $p) {
            $st = $states[$p['id']] ?? [];
            if (in_array($st['result'] ?? '', ['failed', 'partly'], true)) {
                $out[] = finding('plan_failed', 'recommended', false, ['name' => $p['label']], '#/snapshot');
            }
        }
    }
    // other snapshot schedulers: worth knowing, so nothing runs twice
    foreach (housePlugins() as $pl) {
        if (preg_match('/sanoid|auto.?snap|znapzend|snapshot/i', $pl['name'])) {
            $out[] = finding('other_snapshot_tool', 'hint', null, ['name' => $pl['name']], 'plugins');
        }
    }
    foreach (houseContainers() as $c) {
        if (preg_match('/sanoid|znapzend|zfs-auto-snap/i', $c['name'] . ' ' . $c['image'])) {
            $out[] = finding('other_snapshot_tool', 'hint', null, ['name' => $c['name']], 'docker');
        }
    }
    return $out;
}
