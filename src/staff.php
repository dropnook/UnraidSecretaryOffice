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
 * data/office/staff.json: {"hired": {"<desk>": <since>, …}}
 *
 * A desk that went into another one (OFFICE_DESKS_MERGED) is replaced by that one in the list —
 * hired since the earlier of the two —, once, the first time the list is read afterwards.
 */

// desks that went into another one: old id => the desk that does their work now
// (the agent's STAFF_MERGED in agent/lib/house.php is the same, until the list is rewritten here)
const OFFICE_DESKS_MERGED = ['whereabouts' => 'cleanup'];     // 2026-10: Ms. Whereabouts' work is Ms. Dustdevil's

function officeStaffFile(): string
{
    return OFFICE_DATA . '/office/staff.json';
}

/** @return array<string, int>  desk => hired since (the always-there desks included) */
function officeHired(): array
{
    $hired = [];
    $staff = officeReadJson(officeStaffFile()) ?? [];
    if (officeStaffMerged($staff, officeDesks()) !== null) {
        $staff = officeStaffMigrate(officeStaffFile(), officeDesks()) ?? officeStaffMerged($staff, officeDesks());
    }
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
    $ids = $action === 'office.hire' ? (array) ($data['desks'] ?? []) : [(string) ($data['desk'] ?? '')];
    $ids = array_values(array_unique(array_filter($ids, 'is_string')));
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
 * one — hired since the earlier of the two —, or null when there is nothing to replace. Only while the
 * old desk is gone and the one that took over exists.
 *
 * @param array $desks officeDesks()
 */
function officeStaffMerged(array $staff, array $desks): ?array
{
    $hired = (array) ($staff['hired'] ?? []);
    $changed = false;
    foreach (OFFICE_DESKS_MERGED as $old => $new) {
        if (!array_key_exists($old, $hired) || isset($desks[$old]) || !isset($desks[$new])) {
            continue;
        }
        $since = (int) $hired[$old];
        $hired[$new] = array_key_exists($new, $hired) ? min((int) $hired[$new], $since) : $since;
        unset($hired[$old]);
        $changed = true;
    }
    if (!$changed) {
        return null;
    }
    $staff['hired'] = $hired;
    return $staff;
}

/** Rewrites staff.json with the merged desks, once; the list written, or null when it couldn't (read as merged anyway) */
function officeStaffMigrate(string $file, array $desks): ?array
{
    try {
        $staff = officeStaffChange($file, fn (array $staff): array => officeStaffMerged($staff, $desks) ?? $staff);
    } catch (OfficeProblem) {
        return null;
    }
    $staff['hired'] = (array) $staff['hired'];
    return $staff;
}
