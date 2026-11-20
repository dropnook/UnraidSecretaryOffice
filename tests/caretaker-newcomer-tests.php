<?php
declare(strict_types=1);

/*
 * The Team Lead introduces a new character once after an update (agent/desks/caretaker.php caretakerNewcomersStart()) —
 * Fanboy first. Uses Fanboy's test helpers (fanboyT(): a notify stand-in, fanboyR6Refused()).
 */

/**
 * 2026-11-16 (the owner: «eine einmalige Nachricht vom Teamchef, dass ein neuer Mitarbeiter zum Einstellen zur Verfügung
 * steht … nur 1x, nach einem Update, falls jemand das Plugin schon hatte»): caretakerNewcomersStart() — an office that ran
 * a version without him for everyone is told once (the entry, one «normal» notification through the gate as the Team
 * Lead's); a restart, a later update, a downgrade and back: never again; a fresh install: never; hired before: nothing
 * told; hired after: the card away for good; «Nur Alarme» for the Team Lead: the gate drops it as it drops his notices
 */
function testCaretakerNewcomers(): void
{
    $t = fanboyT('newcomers');
    $homeWas = getenv('OFFICE_NOTIFY_HOME');
    $h = "{$t['tmp']}/home";
    @mkdir($h, 0700, true);
    $before = CARETAKER_NEWCOMERS['fanboy'] ?? null;
    $next = preg_replace_callback('/(\d+)\.(\d+)\.\d+$/', fn ($m) => $m[1] . '.' . ((int) $m[2] + 1) . '.0', (string) $before);
    $folder = function (string $name, array $history, ?array $staff = null) use ($t, $next): string {
        $d = "{$t['tmp']}/$name";
        @mkdir("$d/office", 0700, true);
        file_put_contents("$d/office.json", json_encode(['version' => $next, 'since' => 1, 'updated_from' => array_map(fn ($v) => ['version' => $v, 'at' => 1], $history), 'done' => [], 'pending' => []]));
        if ($staff !== null) {
            file_put_contents("$d/office/staff.json", json_encode(['hired' => $staff]));
        }
        return $d;
    };
    try {
        same('newcomers: Fanboy is the one so far — the last released version without him for everyone: 1.94.1 (the fix release of 2026-11-20 went out without him too)', ['fanboy' => '1.94.1'], CARETAKER_NEWCOMERS);
        putenv("OFFICE_NOTIFY_HOME=$h");
        touch("$h/agent.json");
        officeNotifyCfgKeep(officeNotifySettings([]), $h, null);
        $calls = fn (): array => fanboyTNotified($t);
        $drops = fn (): array => array_values(array_map(fn ($i) => [$i['desk'] ?? '', $i['event'], $i['level'], $i['fate']], officeNotifyDropsRead($h)));

        // an update from an office that had the plugin (1.81.0 → 1.94.0 → this one): told once
        $up = $folder('update', ['1.81.0', '1.94.0'], ['backup' => 1]);
        $told = caretakerNewcomersStart($up, $next, 1000);
        $e = caretakerNewcomersRead($up)['fanboy'] ?? [];
        $sent = $calls();
        same('newcomers: an update — told: the entry (when, from which version), ONE notification of the lowest level as the Team Lead\'s, sent through the gate',
            [['fanboy'], 1000, '1.94.0', true, null, 1, true, [['caretaker', 'newcomer', 'normal', 'sent']]],
            [$told, $e['told'] ?? null, $e['from'] ?? null, $e['sent'] ?? null, array_key_exists('away', $e) ? $e['away'] : 'x', count($sent), str_contains($sent[0] ?? '', ' -i normal'), $drops()]);
        $en = json_decode((string) file_get_contents(OFFICE_WEB . '/desks/caretaker/lang/en.json'), true);
        check('newcomers: … its words: «New in the office: Fanboy», his two sentences, the link to the team', str_contains($sent[0] ?? '', 'New in the office: Fanboy')
            && str_contains($sent[0] ?? '', $en['newcomer.fanboy']) && str_contains($sent[0] ?? '', '#/caretaker/team'), $sent[0] ?? '');
        same('newcomers: … the card on his page and at the reception (the state\'s newcomers)', [['id' => 'fanboy', 'since' => 1000]], caretakerNewcomersOpen(['backup'], $up));

        // never again: the agent restarts, a later update, a downgrade (the older office keeps the file) and back
        $again = [caretakerNewcomersStart($up, $next, 2000)];
        $file = (string) file_get_contents(caretakerNewcomersFile($up));
        $again[] = caretakerNewcomersStart($up, preg_replace('/\.0$/', '.1', $next), 3000);
        $again[] = caretakerNewcomersStart($up, $next, 4000);
        same('newcomers: a restart, a later update, a downgrade and back — never told again, the entry as it was', [[[], [], []], 1, $file],
            [$again, count($calls()), (string) file_get_contents(caretakerNewcomersFile($up))]);

        // hired after the card was told: gone for good (also when let go later); put away the same
        same('newcomers: hired — the card away for good: not now, not after he is let go', [[], [], 'hired'],
            [caretakerNewcomersOpen(['backup', 'fanboy'], $up, 5000), caretakerNewcomersOpen(['backup'], $up), caretakerNewcomersRead($up)['fanboy']['why'] ?? null]);

        // a fresh install (no version before this one in the history), no marker at all, this office not newer than 1.94.0
        $fresh = $folder('fresh', []);
        $none = "{$t['tmp']}/none";
        @mkdir($none, 0700, true);
        $old = $folder('old', ['1.90.0']);
        same('newcomers: a fresh install, a folder without a marker, an office still at 1.94.0 — never told, nothing written',
            [[], [], [], false, false, false, 1],
            [caretakerNewcomersStart($fresh, $next), caretakerNewcomersStart($none, $next), caretakerNewcomersStart($old, (string) $before),
             is_file(caretakerNewcomersFile($fresh)), is_file(caretakerNewcomersFile($none)), is_file(caretakerNewcomersFile($old)), count($calls())]);
        // an office that only knew versions after him (it came with him for everyone): never
        $after = $folder('after', [$next]);
        same('newcomers: an office whose history starts after 1.94.0 — never told', [[], 1], [caretakerNewcomersStart($after, preg_replace('/\.0$/', '.1', $next)), count($calls())]);

        // hired before the update (behind his old switch): nothing told, the entry says so — and no card
        $hired = $folder('hired', ['1.94.0'], ['fanboy' => 1]);
        same('newcomers: already hired before — nothing told, no card, the entry «hired_before»', [[], 1, 'hired_before', null, []],
            [caretakerNewcomersStart($hired, $next, 6000), count($calls()), caretakerNewcomersRead($hired)['fanboy']['why'] ?? null,
             array_key_exists('told', $hb = caretakerNewcomersRead($hired)['fanboy'] ?? []) ? $hb['told'] : 'x', caretakerNewcomersOpen([], $hired)]);

        // «Nur Alarme» for the Team Lead: the gate drops his notice as it drops every one of his (counted in the history),
        // nothing forced to Unraid — and it stays told (never again)
        officeNotifyCfgKeep(officeNotifySettings(['desks' => ['caretaker' => ['level' => 'alert']]]), $h, null);
        $quiet = $folder('quiet', ['1.94.0']);
        $told = caretakerNewcomersStart($quiet, $next, 7000);
        $d = $drops();
        same('newcomers: «alerts only» for the Team Lead — dropped by the gate (in its history), nothing to Unraid, told all the same (the card) and never again',
            [['fanboy'], ['caretaker', 'newcomer', 'normal', 'dropped'], 1, [['id' => 'fanboy', 'since' => 7000]], []],
            [$told, $d[count($d) - 1] ?? null, count($calls()), caretakerNewcomersOpen([], $quiet), caretakerNewcomersStart($quiet, $next, 8000)]);

        // «Put away»: only a desk that was told
        same('newcomers: «put away» for a desk never told — refused', 'bad_request', fanboyR6Refused(fn () => caretakerNewcomerAway('backup')));
        $js = (string) file_get_contents(OFFICE_WEB . '/desks/caretaker/desk.js');
        check('newcomers page: the card on his page and at the reception, gone when hired (from anywhere) — his bubble starts with the line',
            str_contains($js, "&& !Office.desks.get(x.id).hired && Office.has(`\${ID}.newcomer.\${x.id}`)") && str_contains($js, 'newcomers().forEach((x) => sw.el.insertBefore(newcomerCard(x), sw.body));')
            && str_contains($js, 'return { bubble: bubbleText(), facts, note };') && str_contains($js, 'return newcomerLine() + bubbleRest();')
            && str_contains((string) file_get_contents(OFFICE_WEB . '/assets/core.js'), 'if (r && r.note instanceof Element) card.insertBefore(r.note, foot);'));
        same('newcomers: the centre lists his notices', ['normal', 'warning'], (function () { $s = OFFICE_NOTIFY_SENDS['caretaker']; sort($s); return $s; })());
        // an office that began with the last release without him (a fresh install of 1.94.1, never 1.94.0) and updates: told too
        $fix = $folder('fix', [(string) $before], ['backup' => 1]);
        same('newcomers: an office that only ever ran the last release without him — told once at the update, from that version (the notice itself: the gate holds a second one of the same words in this test)',
            [['fanboy'], (string) $before, 3000, []],
            [caretakerNewcomersStart($fix, $next, 3000), caretakerNewcomersRead($fix)['fanboy']['from'] ?? null, caretakerNewcomersRead($fix)['fanboy']['told'] ?? null, caretakerNewcomersStart($fix, $next, 3100)]);
    } finally {
        putenv($homeWas === false ? 'OFFICE_NOTIFY_HOME' : "OFFICE_NOTIFY_HOME=$homeWas");
        fanboyTEnd();
    }
}
