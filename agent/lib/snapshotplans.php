<?php
declare(strict_types=1);

/*
 * Ms. Snapshotini's schedules: snapshots at fixed times, with a simple
 * retention — "keep the last N", optionally "nothing older than X days".
 *
 * A plan names its targets (ZFS datasets, btrfs disks — the same ids as a
 * manual snapshot), when to run (a cron expression; the page offers hourly,
 * daily, weekly) and how many to keep. Its snapshots are called
 * uso-plan-<plan>-YYYYMMDD-HHMM (up to office 1.27: auto-<plan>-…, still
 * hers), and retention only ever looks at exactly those: the backup engine's
 * (uso-backup-… and the older unraidbackup-…, btrfs YYYYMMDD-HHMM), manual and
 * held snapshots are never touched — whatever matches the engine's names is
 * refused even when it looks like a plan's. Not uso-<plan>-: a plan called
 * "backup" would make the engine's names.
 *
 * Running: "php agent.php job snapshot-plans" every few minutes, on the host
 * itself, so it works even when nobody has the office open — a line in the
 * plugin's cron file (see officeJobSchedule()). It runs whatever is due — also a run missed while
 * the server was off, once. The office sets it up when the first plan is
 * saved and switches it off when no plan is active any more.
 *
 * data/snapshot-plans.json        {"plans": [ … ]}
 * data/snapshot-plans-state.json  {"<plan>": {last_run, result, detail, created, deleted, skipped, gone}}
 *
 * A target that isn't there any more (the share deleted, the dataset renamed) is no failure of every
 * run: the run takes the targets that exist, skips the gone ones and remembers them in the plan's state
 * (`gone`: target => since when) — told once (a warning) when a target is first seen gone, again only for
 * a further target; one that comes back is forgotten. A plan whose targets are all gone creates nothing
 * (result `gone`). The team lead hears it as a recommended finding (`plan_target_gone`).
 */

const SNAPPLAN_CRON    = '*/5 * * * *';
const SNAPPLAN_MAX     = 20;
const SNAPPLAN_KEEP    = 1000;
const SNAPPLAN_ID      = '/^[a-z0-9][a-z0-9-]{0,23}$/D';

function snapPlanFile(): string
{
    return $GLOBALS['snapPlanFile'] ?? DATA_DIR . '/snapshot-plans.json';           // the global: tests only
}

function snapPlanStateFile(): string
{
    return $GLOBALS['snapPlanStateFile'] ?? DATA_DIR . '/snapshot-plans-state.json';
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

/** What a plan's state remembers as gone: target => since when (checked — the file lies on the pool) */
function snapPlanGoneOf(array $state): array
{
    $out = [];
    foreach (is_array($state['gone'] ?? null) ? $state['gone'] : [] as $target => $since) {
        if (is_string($target) && $target !== '' && is_int($since)) {
            $out[$target] = $since;
        }
    }
    return $out;
}

/** A target as the page and the texts name it: without its zfs:/btrfs: prefix */
function snapPlanTargetLabel(string $target): string
{
    return (string) preg_replace('/^(?:zfs|btrfs):/', '', $target);
}

function snapPlanSaveAll(array $plans): void
{
    writeAtomic(snapPlanFile(), jsonEncode(['plans' => array_values($plans)]));
}

/** The name a plan gives its snapshots, and the pattern that finds exactly those again (also the older auto-<plan>-…) */
function snapPlanName(string $id, int $time): string
{
    return "uso-plan-$id-" . date('Ymd-Hi', $time);
}

function snapPlanPattern(string $id): string
{
    return '/^(?:uso-plan|auto)-' . preg_quote($id, '/') . '-\d{8}-\d{4}$/D';
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
    $runner = officeJobSchedule('snapshots');
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
            'gone'     => snapPlanGoneOf($st),
            'count'    => $count,
            'bytes'    => $bytes,
        ];
    }
    return ['plans' => $out, 'runner' => $runner];
}

// ===================================================================== changing plans

function snapPlanSave(mixed $in): array
{
    if (!is_array($in)) {
        throw new Problem('missing_field', ['field' => 'plan']);
    }
    $plans = snapPlans();
    $label = trim(optText($in, 'label'));
    if ($label === '' || mb_strlen($label) > 40) {
        throw new Problem('plan_bad_label');
    }
    $id = optText($in, 'id');                      // '' or none: a new plan; anything but a string refused, never «new»
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

    $cron = preg_replace('/\s+/', ' ', trim(optText($in, 'cron')));
    if (!cronValid($cron)) {
        throw new Problem('bad_cron', ['cron' => $cron]);
    }
    $state = snapshotScan(false);
    $known = [];
    foreach ($state['zfs']['volumes'] as $v) {
        if (snapshotPartnerLocked($v) && in_array($v['id'], (array) ($in['targets'] ?? []), true)) {
            throw new Problem('partner_dataset', ['target' => $v['name'], 'partner' => (string) ($v['partner']['name'] ?? $v['partner']['id'] ?? '')]);
        }
        $known[$v['id']] = true;
    }
    foreach ($state['btrfs']['devices'] as $d) {
        $known["btrfs:{$d['mount']}"] = true;
    }
    $old = [];
    foreach ($plans as $p) {
        if ($p['id'] === $id) {
            $old = $p;
        }
    }
    $sorted = snapPlanSaveTargets((array) ($in['targets'] ?? []), $known, array_values(array_filter((array) ($old['targets'] ?? []), 'is_string')));
    $targets = $sorted['targets'];
    $keep = $in['keep'] ?? null;
    $days = $in['max_days'] ?? null;
    if (!is_int($keep) || !is_int($days) || $keep < 1 || $keep > SNAPPLAN_KEEP || $days < 0 || $days > 3650) {
        throw new Problem('plan_bad_keep');
    }
    // the page sends the whole plan: a switch missing would be «off» unasked; `enabled` only when said (QA 2026-10-08)
    $recursive = boolField($in, 'recursive');
    $skipAsleep = boolField($in, 'skip_asleep');
    if (array_key_exists('enabled', $in) && !is_bool($in['enabled'])) {
        throw new Problem('bad_request');
    }

    $plan = [
        'id'          => $id,
        'label'       => $label,
        'targets'     => $targets,
        'recursive'   => $recursive,
        'cron'        => $cron,
        'keep'        => $keep,
        'max_days'    => $days,
        'skip_asleep' => $skipAsleep,
        'enabled'     => array_key_exists('enabled', $in) ? $in['enabled'] : ($old['enabled'] ?? true),
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
    } else {
        // every target was just found: nothing is gone any more (one that goes again is told again)
        $states = snapPlanStates();
        if (isset($states[$id]['gone'])) {
            unset($states[$id]['gone']);
            writeAtomic(snapPlanStateFile(), jsonEncode($states));
        }
    }
    snapPlanRunner();
    logLine("Ms. Snapshotini: plan $id saved ($cron, keep $keep" . ($days ? ", max $days days" : '')
        . ($sorted['dropped'] ? '; gone and dropped: ' . implode(', ', $sorted['dropped']) : '') . ')');
    return ['ok' => true, 'id' => $id, 'dropped' => $sorted['dropped'], 'state' => snapshotScan(false)];
}

/**
 * The targets a saved plan keeps. A target the plan had before and that is gone now (the share deleted, the
 * dataset renamed — the page can't even show it any more) is dropped quietly: «choose another target» must be
 * possible. A target nobody knows that the plan didn't have is refused (a stale page, a typo); none left:
 * plan_no_targets.
 *
 * @param array<string, true> $known  what exists now: zfs:<dataset>, btrfs:<mount>
 * @param list<string> $before        the plan's targets as saved (none for a new plan)
 * @return array{targets: list<string>, dropped: list<string>}
 */
function snapPlanSaveTargets(array $sent, array $known, array $before): array
{
    $targets = $dropped = [];
    foreach (array_unique(array_filter($sent, 'is_string')) as $t) {
        if (isset($known[$t])) {
            $targets[] = $t;
        } elseif (in_array($t, $before, true)) {
            $dropped[] = $t;
        } else {
            throw new Problem('unknown_target', ['target' => $t]);
        }
    }
    if (!$targets) {
        throw new Problem('plan_no_targets');
    }
    return ['targets' => $targets, 'dropped' => $dropped];
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

/** The line in the plugin's cron file that runs the plans: there while a plan is active, gone when none is */
function snapPlanRunner(): void
{
    $active = array_filter(snapPlans(), fn ($p) => !empty($p['enabled']));
    if (isset(officeCronLines()['snapshots']) !== (bool) $active) {
        officeJobSetSchedule('snapshots', $active ? SNAPPLAN_CRON : null);
    }
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

/**
 * A plan's targets sorted by what the scan knows: those to take now, those skipped because their disk
 * sleeps (and the plan says so), those that aren't there any more. A dataset nobody has seen on a pool
 * that sleeps (the scan lists only the awake pools — a sleeping one keeps its last list, and since the
 * agent started it may have none) is asleep, never gone.
 *
 * @param array<string, bool> $asleep  disk name => asleep (sleepingDisks())
 * @return array{take: list<string>, skipped: list<string>, gone: list<string>, wake: list<string>}
 *         wake: those of `take` that sleep — the plan takes them anyway (it says so), the create has to wake them
 */
function snapPlanTargets(array $plan, array $state, array $asleep): array
{
    $volumes = [];
    foreach ($state['zfs']['volumes'] ?? [] as $v) {
        $volumes[$v['id']] = $v;
    }
    $devices = [];
    foreach ($state['btrfs']['devices'] ?? [] as $d) {
        $devices["btrfs:{$d['mount']}"] = $d;
    }
    $pools = [];
    foreach ($state['zfs']['pools'] ?? [] as $p) {
        if (is_array($p) && is_string($p['name'] ?? null)) {
            $pools[$p['name']] = true;
        }
    }
    $out = ['take' => [], 'skipped' => [], 'gone' => [], 'wake' => []];
    foreach ($plan['targets'] as $t) {
        if (isset($volumes[$t])) {
            $sleeping = baseAsleep((string) $volumes[$t]['pool'], $asleep);
        } elseif (isset($devices[$t])) {
            $sleeping = (bool) $devices[$t]['asleep'];
        } elseif (preg_match('#^zfs:([^/@]+)#', (string) $t, $m) && isset($pools[$m[1]]) && baseAsleep($m[1], $asleep)) {
            $sleeping = true;                       // on a pool that sleeps and wasn't listed: not known, not gone
        } else {
            $out['gone'][] = $t;
            continue;
        }
        if ($sleeping && !empty($plan['skip_asleep'])) {
            $out['skipped'][] = $t;
            continue;
        }
        $out['take'][] = $t;
        if ($sleeping) {
            $out['wake'][] = $t;
        }
    }
    return $out;
}

/**
 * What the plan remembers as gone after this run — and which targets are gone for the first time (those
 * are told). A target keeps the time it was first missed; one that is back is forgotten, so it is told
 * again should it go once more.
 *
 * @param array<string, int> $before  target => since, from the plan's state
 * @param list<string>       $gone    the targets missed in this run
 * @return array{gone: array<string, int>, new: list<string>}
 */
function snapPlanGone(array $before, array $gone, int $now): array
{
    $out = ['gone' => [], 'new' => []];
    foreach ($gone as $t) {
        if (isset($before[$t])) {
            $out['gone'][$t] = $before[$t];
        } else {
            $out['gone'][$t] = $now;
            $out['new'][] = $t;
        }
    }
    return $out;
}

/**
 * One run of a plan: snapshots, then retention of exactly this plan's snapshots.
 *
 * @param array|null $host  stand-ins for what touches the server (tests only): scan(array $btrfsOnly), asleep(),
 *                          create(array $request), delete(array $ids) — as snapshotScan(false, false, …),
 *                          sleepingDisks(), snapshotCreate() and snapshotDelete(…, false) answer
 */
function snapPlanRun(array $plan, int $now, ?array $host = null): array
{
    $id = $plan['id'];
    $scan = $host['scan'] ?? fn (array $btrfsOnly = []): array => snapshotScan(false, false, $btrfsOnly);
    $create = $host['create'] ?? fn (array $r): array => snapshotCreate($r);
    $delete = $host['delete'] ?? fn (array $ids): array => snapshotDelete($ids, false);

    $state = $scan();
    ['take' => $take, 'skipped' => $skipped, 'gone' => $gone, 'wake' => $wake] = snapPlanTargets($plan, $state, ($host['asleep'] ?? 'sleepingDisks')());
    $devices = [];
    foreach ($state['btrfs']['devices'] ?? [] as $d) {
        $devices["btrfs:{$d['mount']}"] = $d;
    }

    $failures = [];
    $created = [];
    if ($take) {
        try {
            // a sleeping target the plan takes anyway (it doesn't skip them): the create lists its pool fresh — that wakes it, as the plan says
            $r = $create(['name' => snapPlanName($id, $now), 'targets' => $take, 'recursive' => !empty($plan['recursive']), 'wake' => (bool) $wake]);
            $created = $r['created'] ?? [];
            $failures = $r['failures'] ?? [];
        } catch (Problem $p) {
            $failures[] = ['key' => $p->key, 'params' => $p->params];
        }
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
        $doomed = snapPlanDoomed($plan, $take, snapshotAll($scan($btrfsMounts)), $now);
        if ($doomed) {
            $r = $delete($doomed);
            $deleted = count($r['deleted'] ?? []);
            foreach ($r['failures'] ?? [] as $f) {
                if ($f['key'] !== 'held') {        // a snapshot someone holds stays on purpose
                    $failures[] = $f;
                }
            }
        }
    }

    // a target that is gone is no failure of this run: taken note of, told once (below)
    $states = snapPlanStates();
    $remembered = snapPlanGone(snapPlanGoneOf(is_array($states[$id] ?? null) ? $states[$id] : []), $gone, $now);
    $result = $failures ? ($created ? 'partly' : 'failed') : ($take ? 'ok' : ($skipped || !$gone ? 'skipped' : 'gone'));
    $states[$id] = ['last_run' => $now, 'result' => $result, 'detail' => array_slice($failures, 0, 5),
                    'created' => count($created), 'deleted' => $deleted, 'skipped' => $skipped]
                 + ($remembered['gone'] ? ['gone' => $remembered['gone']] : []);
    writeAtomic(snapPlanStateFile(), jsonEncode($states));
    logLine(sprintf('Ms. Snapshotini: plan %s — %d created, %d removed%s%s%s%s', $id, count($created), $deleted,
        $wake ? ', woken (the plan takes sleeping targets): ' . implode(', ', $wake) : '',
        $skipped ? ', skipped (asleep): ' . implode(', ', $skipped) : '',
        $gone ? ', gone: ' . implode(', ', $gone) . ($remembered['new'] ? '' : ' (known)') : '',
        $failures ? ', ' . count($failures) . ' problem(s)' : ''));
    if ($failures) {
        officeNotify("Snapshot plan $id", "Ms. Snapshotini: plan \"{$plan['label']}\" had " . count($failures) . ' problem(s) — see the office.',
            'warning', '', officeNotifyLink('#/snapshot'));
    }
    if ($remembered['new']) {
        $lang = officeNotifyLang();
        $params = ['plan' => (string) $plan['label'], 'targets' => implode(', ', array_map('snapPlanTargetLabel', $remembered['new']))];
        officeNotify(officeNotifyText('snapshot', 'notify.plan_gone_subject', $params, $lang),
            officeNotifyText('snapshot', 'notify.plan_gone', $params, $lang), 'warning', '', officeNotifyLink('#/snapshot'));
    }
    return $states[$id];
}

/**
 * Which of this plan's snapshots go: per dataset/disk the newest `keep` stay,
 * and with max_days nothing older than that — but never the one just taken.
 * Its older auto-<plan>-… count with the new names (one series), and nothing
 * that matches the engine's names ($enginePrefixes, default: its settings.ini).
 *
 * @param list<string> $take  the targets of this run
 * @return list<string> snapshot ids
 */
function snapPlanDoomed(array $plan, array $take, array $all, int $now, ?array $enginePrefixes = null): array
{
    $pattern = snapPlanPattern($plan['id']);
    // what is (or was) the backup engine's is never a plan's, whatever it looks like
    $engine = array_values(array_unique(array_merge($enginePrefixes ?? backupEngineSnapPrefixes(), backupSnapPrefixes(null))));
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
        if (!empty($s['docker']) || ($s['fs'] ?? '') === 'vm' || !preg_match($pattern, (string) $s['name'])
            || backupIsEngineSnap((string) $s['name'], $engine, (string) ($s['fs'] ?? 'zfs')) || !empty($s['partner'])) {
            continue;                       // the engine's, a partner's copies (the door's retention keeps those): never a plan's
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
    $out = snapPlanFindings(snapPlans(), snapPlanStates());
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

/**
 * The findings about the active plans themselves, from their files only: the runner's cron line, a
 * last run that failed, targets gone (one per plan and target — the team lead's «I know, thanks» is
 * keyed by the params). $runner stands in for officeJobSchedule('snapshots') in the tests.
 */
function snapPlanFindings(array $plans, array $states, ?array $runner = null): array
{
    $out = [];
    $active = array_filter($plans, fn ($p) => !empty($p['enabled']));
    if ($active) {
        $runner ??= officeJobSchedule('snapshots');
        $out[] = finding('plans_runner', 'required', $runner['script'] && $runner['enabled'], [], '#/snapshot');
        foreach ($active as $p) {
            $st = is_array($states[$p['id']] ?? null) ? $states[$p['id']] : [];
            if (in_array($st['result'] ?? '', ['failed', 'partly'], true)) {
                $out[] = finding('plan_failed', 'recommended', false, ['name' => $p['label']], '#/snapshot');
            }
            foreach (array_keys(snapPlanGoneOf($st)) as $t) {
                $out[] = finding('plan_target_gone', 'recommended', false, ['plan' => $p['label'], 'target' => snapPlanTargetLabel($t)], '#/snapshot');
            }
        }
    }
    return $out;
}
