<?php
declare(strict_types=1);

/*
 * Fanboy's guard — what gives the fans back when nobody else can. Small on purpose: only the write helper
 * (desks/fanboy-write.php) and lib/util.php, nothing else of the agent (an agent that doesn't start, a desk file that
 * doesn't parse after an update never keeps a fan where Fanboy left it).
 *
 *   php fanboy-guard.php release   scripts/agent.sh stop|release (stop, restart, the plugin's update and removal, the
 *                                  array stopping): every holder asked to end (TERM — the control and a setup step give
 *                                  their outputs back themselves), at most 3 s, then EVERY output still held given back
 *                                  to exactly its state before Fanboy and read back. Prints one line for agent.sh's log.
 *                                  `release array_stopping` (agent.sh at the array's stop, 2026-11-22): the same, and the
 *                                  status the page and the Dashboard tile read says why — «the array is stopped», not
 *                                  «the office stopped».
 *   php fanboy-guard.php check-back   after a release (agent.sh starts it in a session of its own, 2026-11-22): did the
 *                                  mainboard take the fans back? The agent's tick asks that FANBOY_BACK_CHECK s after every
 *                                  give-back — after a stop there is no agent, and the cron line below looks only while
 *                                  the journal names a holder. Waits for that moment (at most FANBOY_GUARD_BACK_WAIT s),
 *                                  then fanboyGivenCheck() once: a fan clearly slower than before Fanboy and not rising
 *                                  is set to full speed, noted in the journal — the agent tells it at its next start.
 *                                  RAM and /sys only: never the data folder (the array may be unmounting).
 *   php fanboy-guard.php watch     the cron line (agent-watch.cron, every minute — only when the journal names a holder):
 *                                  the outputs of a holder that is gone (killed hard) or hung (no beat for 30 s; ended
 *                                  for good first) given back. The agent's tick does the same every 5 s while it runs —
 *                                  this one is there for when the agent is gone too.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/place.php';
define('RUN_DIR', officeRunDir());
const FILE_UID = 99;
const FILE_GID = 100;
const LOG_MAX = 512 * 1024;         // as agent.php's: logLine() rotates the log there
define('DATA_DIR', rtrim(getenv('OFFICE_DATA_DIR') ?: officePluginDataDir(), '/'));
define('AGENT_LOG', DATA_DIR . '/agent.log');
require __DIR__ . '/lib/util.php';
require __DIR__ . '/desks/fanboy-write.php';
date_default_timezone_set(fanboyZone());        // the agent's zone (agent.php: agentZone()) — one zone in agent.log

/** A line for the agent's log (only while its folder is there) and the guard's own note in RAM for the agent to pick up */
function fanboyGuardSay(string $what, array $back, ?string $why = null): void
{
    $bad = array_keys(array_filter($back, fn ($x) => !$x['ok'] && $x['why'] !== 'gone'));
    $line = "Fanboy: $what — " . count($back) . ' outputs back with the BIOS' . ($bad ? ', NOT read back as before: ' . implode(', ', $bad) : '');
    echo date('Y-m-d H:i:s') . " $line\n";
    try {
        logLine($line);             // the agent's log in the data folder — never in the way of what follows
    } catch (Throwable) {
    }
    $note = ['v' => 1, 'what' => $what, 'time' => time(), 'outputs' => array_keys($back), 'failed' => $bad,
             'holders' => array_values(array_unique(array_map(fn ($x) => ($x['owner']['kind'] ?? '?') . ':' . ($x['owner']['pid'] ?? '?'), $back)))];
    @mkdir(fanboyRunDir(), 0700, true);
    writeAtomic(fanboyRunDir() . '/guard.json', jsonEncode($note), 0644, 0, 0);
    // the status the page reads: the BIOS, and why
    $s = readJson(fanboyRunDir() . '/control.json');
    if (is_array($s)) {
        $s['who'] = 'bios';
        // $why: the reason agent.sh named for a release (the array stopping) — else released, or crashed for a holder gone
        $s['why'] = $what === 'released' ? ($why ?? 'released') : 'crashed';
        $s['phase'] = 'ended';
        $s['time'] = time();
        writeAtomic(fanboyRunDir() . '/control.json', jsonEncode($s), 0644, 0, 0);
    }
}

const FANBOY_GUARD_BACK_WAIT = 30;     // check-back: seconds it waits at most for the give-backs' moment (FANBOY_BACK_CHECK after each)

/** The give-backs nobody looked at yet (no holder, no `checked`): [id => given.at] */
function fanboyGuardUnchecked(): array
{
    $out = [];
    foreach (fanboyJournal()['outputs'] as $id => $o) {
        $g = $o['given'] ?? null;
        if (!is_array($o['owner'] ?? null) && is_array($g) && !isset($g['checked']) && is_int($g['at'] ?? null)) {
            $out[$id] = $g['at'];
        }
    }
    return $out;
}

/**
 * check-back: one look once the newest give-back is FANBOY_BACK_CHECK s old (a look every half second meanwhile) — or,
 * the wall clock saying «too early» for FANBOY_GUARD_BACK_WAIT s on the monotonic clock (it was set back): then all the
 * same, that long has really passed. Nothing to look at: ends at once. Never logLine() (the data folder may be
 * unmounting) — only its line for agent.sh's log in RAM. Returns the outputs set to full.
 */
function fanboyGuardCheckBack(): array
{
    $start = fanboyMono();
    // the give-backs of this release, as found now: only those are waited for (a journal made anew meanwhile — a boot id,
    // another release — is not this look's)
    $mine = fanboyGuardUnchecked();
    while (($open = array_intersect_assoc(fanboyGuardUnchecked(), $mine))) {
        if (time() - max($open) >= FANBOY_BACK_CHECK || fanboyMono() - $start >= FANBOY_GUARD_BACK_WAIT) {
            $full = fanboyGivenCheck(max(time(), max($open) + FANBOY_BACK_CHECK));
            foreach ($full as $id => $x) {
                echo date('Y-m-d H:i:s') . " Fanboy: $id not taken back by the mainboard ({$x['rpm']} rpm, {$x['before']} before Fanboy) — set to full speed"
                    . ($x['ok'] ? '' : ' — NOT written') . " (the guard's look after the release)\n";
            }
            return $full;
        }
        usleep(500000);
    }
    return [];
}

const FANBOY_GUARD_LOOKS = 3;          // round 12: looks per cron run while an output stands at 0 (0, 20 and 40 s) …
const FANBOY_GUARD_AGAIN = 20;         // … seconds apart

function fanboyGuardMain(string $cmd, string $reason = ''): int
{
    if (!is_file(fanboyRunDir() . '/journal.json')) {
        return 0;
    }
    if ($cmd === 'check-back') {
        fanboyGuardCheckBack();
        return 0;
    }
    if ($cmd === 'watch') {
        // round 5: a give-back the mainboard didn't take: full speed (the agent tells it when it is there)
        foreach (fanboyGivenCheck() as $id => $x) {
            logLine("Fanboy: $id not taken back by the mainboard ({$x['rpm']} rpm, {$x['before']} before Fanboy) — set to full speed (the guard's look)");
        }
        // round 12: while an output stands at 0 (a CPU cooler may) the guard looks again every FANBOY_GUARD_AGAIN s within
        // its minute — with the agent gone too, a dead or hung holder's standing fan is back within about 20 s, not 60
        for ($look = 0; ; $look++) {
            if (!fanboyHeld()) {
                return 0;
            }
            $back = fanboyGiveBack(null, 'holder_gone', null, true);
            if ($back) {
                fanboyGuardSay('a holder was gone (the guard\'s look)', $back);
            }
            if ($look >= FANBOY_GUARD_LOOKS - 1 || !fanboyStandingHeld()) {
                return 0;
            }
            sleep(FANBOY_GUARD_AGAIN);
        }
    }
    if ($cmd === 'release') {
        $j = fanboyJournal();
        $t0 = time();
        $asked = [];
        foreach ($j['outputs'] as $o) {
            $w = $o['owner'];
            if (is_array($w) && !isset($asked[$w['pid'] ?? 0]) && fanboyOwnerState($w) !== 'dead') {
                $asked[$w['pid']] = true;
                @posix_kill((int) $w['pid'], 15);
            }
        }
        $until = fanboyMono() + 3;
        while ($asked && fanboyMono() < $until && array_filter(fanboyJournal()['outputs'], fn ($o) => is_array($o['owner']) && fanboyOwnerState($o['owner']) !== 'dead')) {
            usleep(100000);
        }
        $back = fanboyGiveBack(null, 'release');
        // what the holders gave back themselves on the TERM counts too (read back by them)
        foreach (fanboyJournal()['outputs'] as $id => $o) {
            if (!isset($back[$id]) && is_array($o['given']) && ($o['given']['at'] ?? 0) >= $t0 && isset($j['outputs'][$id]) && is_array($j['outputs'][$id]['owner'])) {
                $back[$id] = ['ok' => (bool) ($o['given']['ok'] ?? false), 'why' => null, 'owner' => $j['outputs'][$id]['owner']];
            }
        }
        fanboyGuardSay('released', $back, $reason === 'array_stopping' ? 'array_stopped' : null);
        return 0;
    }
    fwrite(STDERR, "Usage: php fanboy-guard.php release [array_stopping]|check-back|watch\n");
    return 2;
}

exit(fanboyGuardMain((string) ($argv[1] ?? ''), (string) ($argv[2] ?? '')));
