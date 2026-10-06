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
 */

function officeStaffFile(): string
{
    return OFFICE_DATA . '/office/staff.json';
}

/** @return array<string, int>  desk => hired since (the always-there desks included) */
function officeHired(): array
{
    $hired = [];
    foreach ((array) ((officeReadJson(officeStaffFile()) ?? [])['hired'] ?? []) as $id => $since) {
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
    $file = officeStaffFile();
    $dir = dirname($file);
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new OfficeProblem('office_storage', 503);
    }
    $h = fopen("$dir/.staff.lock", 'c');
    if (!$h || !flock($h, LOCK_EX)) {
        throw new OfficeProblem('office_storage', 503);
    }
    try {
        $staff = officeReadJson($file) ?? [];
        $hired = (array) ($staff['hired'] ?? []);
        foreach ($ids as $id) {
            if ($action === 'office.hire') {
                $hired[$id] ??= time();
            } else {
                unset($hired[$id]);
            }
        }
        $staff['hired'] = (object) $hired;
        $tmp = "$dir/.staff." . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($staff, JSON_UNESCAPED_SLASHES)) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new OfficeProblem('office_storage', 503);
        }
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
    return ['ok' => true, 'hired' => array_keys(officeHired())];
}
