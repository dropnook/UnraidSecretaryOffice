<?php
declare(strict_types=1);

/*
 * Fanboy while the array isn't started — what the night shift does for him («always on», 2026-11-22, step S5b).
 *
 * His control runs without the data folder and is to steer whatever the array does. While the array runs the agent's tick
 * looks after it (fanboyTick() in fanboy.php); while it doesn't there is no agent — the night shift takes that part over
 * (agent.php nightShift() calls every desk's 'night'): RAM only, never the data folder, never anything under /mnt.
 *
 *   · holders gone (killed hard) or hung: their outputs back with the BIOS (fanboyWatch(), as the agent's tick)
 *   · a fan the mainboard didn't take back after a give-back: full speed, told (fanboyBackCheck())
 *   · the control, when it should run and doesn't: started — after a death only after a pause, not after too many
 *     (fanboyEnsureControl(); the deaths are counted in RAM for the agent and the night shift alike)
 *
 * Everything he knows then comes from his mirror in RAM (fanboyRamMode() holds in the night shift; FANBOY_DIR names a
 * place that can never be there). What only the agent can do waits for it: the mirror itself, the history's copy, a
 * give-back to the chip's automatic seen to work (the journal keeps it), the fans he doesn't steer (told by the agent).
 * What the night shift says of him goes to RAM (logLine() there) and into the agent's log at its next start.
 *
 * The guard's cron line (every minute while the journal names a holder) stays the net under both of them.
 */

/** The night shift's look for him, every FANBOY_TICK_EVERY s on the monotonic clock. True: the night shift should stay on for him */
function fanboyNight(?float $now = null): bool
{
    $now ??= fanboyMono();
    if ($now >= ($GLOBALS['fanboyNightNext'] ?? -INF)) {
        $GLOBALS['fanboyNightNext'] = $now + FANBOY_TICK_EVERY;
        fanboyWatch();
        fanboyBackCheck();
        fanboyEnsureControl($now);
        $GLOBALS['fanboyNightWanted'] = fanboyNightWanted();
    }
    return ($GLOBALS['fanboyNightWanted'] ?? false) === true;
}

/**
 * Has the night shift anything to look after for him? A control runs, something holds an output — or he would steer: by
 * his mirror in RAM hired, armed, with a zone (then a control that died is started again).
 */
function fanboyNightWanted(): bool
{
    if (fanboyControlPid() !== null || (is_file(fanboyRunDir() . '/journal.json') && fanboyHeld())) {
        return true;
    }
    $s = fanboyBoardRaw();
    return $s !== null && ($s['armed'] ?? false) === true && fanboyHiredNow() && (bool) fanboyControlNew($s, '', ['pid' => 0])['zones'];
}
