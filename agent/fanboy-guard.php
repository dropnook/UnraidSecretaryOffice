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
function fanboyGuardSay(string $what, array $back): void
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
        $s['why'] = $what === 'released' ? 'released' : 'crashed';
        $s['phase'] = 'ended';
        $s['time'] = time();
        writeAtomic(fanboyRunDir() . '/control.json', jsonEncode($s), 0644, 0, 0);
    }
}

const FANBOY_GUARD_LOOKS = 3;          // round 12: looks per cron run while an output stands at 0 (0, 20 and 40 s) …
const FANBOY_GUARD_AGAIN = 20;         // … seconds apart

function fanboyGuardMain(string $cmd): int
{
    if (!is_file(fanboyRunDir() . '/journal.json')) {
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
        fanboyGuardSay('released', $back);
        return 0;
    }
    fwrite(STDERR, "Usage: php fanboy-guard.php release|watch\n");
    return 2;
}

exit(fanboyGuardMain((string) ($argv[1] ?? '')));
