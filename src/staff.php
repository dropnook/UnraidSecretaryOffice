<?php
declare(strict_types=1);

/*
 * Who works in the office.
 *
 * A fresh office has only the desks that are always there (desk.json
 * "always": true — the caretaker). He looks around and suggests whom to
 * hire; hiring shows a desk at the reception and in the tabs, firing hides
 * it again. A fired desk keeps its data and settings, so hiring it again
 * picks up where it left off — and whatever it set up on the server (a
 * nightly backup, say) keeps running until you switch it off there.
 *
 * data/office/staff.json: {"hired": {"<desk>": <since>, …}, "order": ["<desk>", …]}
 *
 * "order" is the user's order of the staff (office.staff_order, «Change the order» at the reception): the
 * reception's cards, the tabs and the team lead's «The team» follow it, in every browser. The desks that are
 * always there (the team lead) come first and are never in it; desks it doesn't name follow the named ones in
 * desk.json's order (someone newly hired joins at the end); ids of desks that are gone count for nothing. No
 * "order": desk.json's order alone (officeStaffOrderOf()).
 *
 * A desk that went into another one (OFFICE_DESKS_MERGED) is replaced by that one in the list —
 * hired since the earlier of the two, in the order at the earlier place: written once by the agent's migration step
 * `staff-merged` (agent/lib/migrate.php, which uses officeStaffMerged() below), read so here until then.
 */

// desks that went into another one: old id => the desk that does their work now
// (the agent's STAFF_MERGED in agent/lib/house.php is the same, until its migration step rewrote the list)
const OFFICE_DESKS_MERGED = ['whereabouts' => 'cleanup'];     // 2026-10: Ms. Whereabouts' work is Ms. Dustdevil's

function officeStaffFile(): string
{
    return OFFICE_DATA . '/office/staff.json';
}

/**
 * staff.json as it is now, a desk that went into another one (OFFICE_DESKS_MERGED) read as that one — never written
 * here. Both exist on purpose: the agent's migration step `staff-merged` (agent/lib/migrate.php) rewrites the file once
 * at its start (the one place for migrations); this read covers the moment before that — right after an update until
 * the new agent has started, and whenever it can't (the array stopped: the night shift doesn't migrate) — so the page
 * works on the list as an older office left it. The web side never migrates (CLAUDE.md «Updates»).
 */
function officeStaff(): array
{
    $staff = officeReadJson(officeStaffFile()) ?? [];
    return officeStaffMerged($staff, officeDesks()) ?? $staff;
}

/** @return array<string, int>  desk => hired since (the always-there desks included) */
function officeHired(): array
{
    $hired = [];
    $staff = officeStaff();
    foreach ((array) ($staff['hired'] ?? []) as $id => $since) {
        if (is_string($id) && isset(officeDesks()[$id]) && !officeDesks()[$id]['training']) {
            $hired[$id] = (int) $since;
        }
    }
    foreach (officeDesks() as $id => $desk) {
        if ($desk['always']) {
            $hired[$id] ??= 0;
        }
    }
    return $hired;
}

function officeIsHired(string $desk): bool
{
    return isset(officeHired()[$desk]);
}

/** office.hire {desks: [...]} and office.fire {desk} */
function officeStaffAction(string $action, array $data): array
{
    $desks = officeDesks();
    $ids = $action === 'office.hire' ? ($data['desks'] ?? []) : (array_key_exists('desk', $data) ? [$data['desk']] : []);
    // only strings: anything else is refused, never cast or dropped quietly (QA 2026-10-08, finding 14)
    if (!is_array($ids) || count($ids) > OFFICE_ORDER_MAX || count(array_filter($ids, 'is_string')) !== count($ids)) {
        throw new OfficeProblem('bad_request', 400);
    }
    $ids = array_values(array_unique($ids));
    if (!$ids) {
        throw new OfficeProblem('missing_field', 400, ['field' => 'desks']);
    }
    foreach ($ids as $id) {
        if (!isset($desks[$id])) {
            throw new OfficeProblem('unknown_desk', 400, ['desk' => $id]);
        }
        if ($desks[$id]['always']) {
            throw new OfficeProblem('always_there', 400, ['desk' => $id]);
        }
        if ($desks[$id]['training'] && $action === 'office.hire') {
            throw new OfficeProblem('in_training', 400, ['desk' => $id]);
        }
    }
    officeStaffChange(officeStaffFile(), function (array $staff) use ($ids, $action, $desks): array {
        $staff = officeStaffMerged($staff, $desks) ?? $staff;
        $hired = (array) ($staff['hired'] ?? []);
        foreach ($ids as $id) {
            if ($action === 'office.hire') {
                $hired[$id] ??= time();
            } else {
                unset($hired[$id]);
            }
        }
        $staff['hired'] = $hired;
        return $staff;
    });
    return ['ok' => true, 'hired' => array_keys(officeHired())];
}

const OFFICE_ORDER_MAX = 64;      // ids in an office.staff_order request — far more than there are desks

/** @return list<string>  every desk's id in the order the office shows them (see the head of this file) */
function officeStaffOrder(): array
{
    return officeStaffOrderOf(officeStaff(), officeDesks());
}

/**
 * Every desk's id in the order the office shows them: the always-there desks (the team lead) first, then the
 * user's order (staff.json "order", cleaned by officeStaffOrderClean()), then the rest in desk.json's order.
 *
 * @param array $desks officeDesks() (in desk.json's order)
 * @return list<string>
 */
function officeStaffOrderOf(array $staff, array $desks): array
{
    $first = array_keys(array_filter($desks, fn ($d) => !empty($d['always'])));
    $own = officeStaffOrderClean(is_array($staff['order'] ?? null) ? $staff['order'] : [], $desks);
    return array_values(array_unique(array_merge($first, $own, array_keys($desks))));
}

/**
 * The user's order as the office takes it: desks that went into another one as that one (at the earlier place),
 * only desks that exist, never one that is always there (the team lead stays first), each once.
 *
 * @param array $desks officeDesks()
 * @return list<string>
 */
function officeStaffOrderClean(array $order, array $desks): array
{
    $out = [];
    foreach ($order as $id) {
        if (!is_string($id)) {
            continue;
        }
        if (!isset($desks[$id]) && isset(OFFICE_DESKS_MERGED[$id])) {
            $id = OFFICE_DESKS_MERGED[$id];
        }
        if (isset($desks[$id]) && empty($desks[$id]['always']) && !in_array($id, $out, true)) {
            $out[] = $id;
        }
    }
    return $out;
}

/**
 * office.staff_order {order: [desk, …]} — «Change the order» at the reception: the desks in the order the user
 * wants them (the hired ones; the page sends them all after every move). Ids of desks that don't exist are dropped,
 * the team lead stays first, an empty list goes back to desk.json's order («As at the start»). Kept in staff.json
 * under its lock; answers the order every desk now has.
 */
function officeStaffOrderAction(array $data): array
{
    if (!array_key_exists('order', $data)) {
        throw new OfficeProblem('missing_field', 400, ['field' => 'order']);
    }
    $order = $data['order'];
    if (!is_array($order) || !array_is_list($order) || count($order) > OFFICE_ORDER_MAX
        || count(array_filter($order, 'is_string')) !== count($order)) {
        throw new OfficeProblem('bad_request', 400);
    }
    $desks = officeDesks();
    $clean = officeStaffOrderClean($order, $desks);
    $staff = officeStaffChange(officeStaffFile(), function (array $staff) use ($clean, $desks): array {
        $staff = officeStaffMerged($staff, $desks) ?? $staff;
        if ($clean) {
            $staff['order'] = $clean;
        } else {
            unset($staff['order']);
        }
        return $staff;
    });
    return ['ok' => true, 'order' => officeStaffOrderOf($staff, $desks)];
}

/**
 * Reads, changes and writes staff.json under its lock (new file + rename, officeWriteAtomic()).
 * Throws office_storage when its folder (data/office, made by the agent) isn't there or writable.
 *
 * @param callable(array): array $change  the list as read => the list to write
 */
function officeStaffChange(string $file, callable $change): array
{
    $dir = dirname($file);
    clearstatcache(true, $dir);
    if (is_link($dir) || !is_dir($dir) || !is_writable($dir)) {
        throw new OfficeProblem('office_storage', 503);
    }
    $h = @fopen("$dir/.staff.lock", 'c');
    if (!$h || !flock($h, LOCK_EX)) {
        throw new OfficeProblem('office_storage', 503);
    }
    try {
        $staff = $change(officeReadJson($file) ?? []);
        $staff['hired'] = (object) (array) ($staff['hired'] ?? []);
        if (!officeWriteAtomic($file, (string) json_encode($staff, JSON_UNESCAPED_SLASHES), 0644)) {
            throw new OfficeProblem('office_storage', 503);
        }
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
    return $staff;
}

/**
 * The staff list with every desk that went into another one (OFFICE_DESKS_MERGED) replaced by that
 * one — hired since the earlier of the two; in the user's order at the earlier of the two places —, or
 * null when there is nothing to replace. Only while the old desk is gone and the one that took over exists.
 *
 * @param array $desks officeDesks()
 */
function officeStaffMerged(array $staff, array $desks): ?array
{
    $hired = (array) ($staff['hired'] ?? []);
    $order = is_array($staff['order'] ?? null) ? array_values($staff['order']) : null;
    $changed = $reordered = false;
    foreach (OFFICE_DESKS_MERGED as $old => $new) {
        if (isset($desks[$old]) || !isset($desks[$new])) {
            continue;
        }
        if (array_key_exists($old, $hired)) {
            $since = (int) $hired[$old];
            $hired[$new] = array_key_exists($new, $hired) ? min((int) $hired[$new], $since) : $since;
            unset($hired[$old]);
            $changed = true;
        }
        if ($order !== null && in_array($old, $order, true)) {
            // the one that took over once, at the earlier of the two places; nothing else in the list is touched
            $next = [];
            $placed = false;
            foreach ($order as $id) {
                if ($id === $old || $id === $new) {
                    if ($placed) {
                        continue;
                    }
                    [$id, $placed] = [$new, true];
                }
                $next[] = $id;
            }
            $order = $next;
            $reordered = true;
        }
    }
    if (!$changed && !$reordered) {
        return null;
    }
    $staff['hired'] = $hired;
    if ($reordered) {
        $staff['order'] = $order;
    }
    return $staff;
}
