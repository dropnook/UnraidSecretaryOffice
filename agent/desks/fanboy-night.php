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
 *   · «give the fans back to the mainboard», the one wish heard without the agent (the mark, below)
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
        fanboyHandBackKeep();           // «give the fans back»: the mark and its copy on the flash say the same
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

// ===================================================================== «give the fans back to the mainboard» without the agent (step S5c)

/*
 * While the array isn't started nobody can change his settings: they lie in the data folder. ONE wish must still be
 * heard — «give the fans back to the mainboard». It is a mark, never a change of the mirror:
 *
 *   RUN_DIR/fanboy/handback.json   the mark in RAM ({v: 1, at}; the page's side writes it — RAM state only). While it
 *                                  stands the control ends (every output given back, read back, within a round) and
 *                                  nobody starts it: not the night shift, not the agent's tick.
 *   <flash>/fanboy-handback.json   its copy on the flash, written by the night shift: after a reboot his mirror from the
 *                                  flash would say «armed» — the copy says the user took the fans back since.
 *
 * At its next start the agent, who has the data folder, makes it true there — «Hand back» as on the page (not armed, the
 * guard's cron line gone) — and takes both marks away. A mark counts by being there: whatever it says (a cut file), the
 * BIOS has the fans.
 */
const FANBOY_HANDBACK_FLASH = '/boot/config/plugins/unraid-secretary-office/fanboy-handback.json';

function fanboyHandBackFile(): string
{
    return fanboyRunDir() . '/handback.json';
}

/** The copy on the flash — in the tests' tree in a test */
function fanboyHandBackFlash(): string
{
    return fanboySysRoot() . FANBOY_HANDBACK_FLASH;
}

/** Did the user give the fans back while no agent could hear it? The mark in RAM is there (a file, whatever it says) */
function fanboyHandBackAsked(): bool
{
    clearstatcache(true, fanboyHandBackFile());
    return @lstat(fanboyHandBackFile()) !== false;
}

/** The mark set: in RAM (the page's side does the same by itself: src/fanboy-stopped.php); true when it stands */
function fanboyHandBackMark(?int $at = null): bool
{
    @mkdir(fanboyRunDir(), 0700, true);
    if (is_link(fanboyHandBackFile())) {
        @unlink(fanboyHandBackFile());
    }
    writeAtomic(fanboyHandBackFile(), jsonEncode(['v' => 1, 'at' => $at ?? time()]), 0600, 0, 0);
    return fanboyHandBackAsked();
}

/**
 * The night shift's part (every look of fanboyNight()): the mark and its copy say the same — a mark in RAM goes to the
 * flash (it must outlive a reboot), a copy on the flash comes back into RAM (after a reboot). Returns what it did:
 * null · 'flash' (copied there) · 'ram' (brought back).
 */
function fanboyHandBackKeep(): ?string
{
    $flash = fanboyHandBackFlash();
    clearstatcache(true, $flash);
    $onFlash = @lstat($flash) !== false;
    if (fanboyHandBackAsked()) {
        if (!$onFlash && is_dir(dirname($flash))) {         // no flash folder of the plugin: never made here
            writeAtomic($flash, jsonEncode(['v' => 1, 'at' => readJson(fanboyHandBackFile())['at'] ?? time()]), 0600, 0, 0);
            return 'flash';
        }
        return null;
    }
    if ($onFlash) {
        $at = readJson($flash)['at'] ?? null;
        fanboyHandBackMark(is_int($at) ? $at : null);
        return 'ram';
    }
    return null;
}

/**
 * The agent's part, at its start: a mark (in RAM or on the flash) made true in the data folder — «Hand back» as on the
 * page — then both taken away. True when there was one.
 */
function fanboyHandBackTake(): bool
{
    $flash = fanboyHandBackFlash();
    clearstatcache(true, $flash);
    if (!fanboyHandBackAsked() && @lstat($flash) === false) {
        return false;
    }
    $at = readJson(fanboyHandBackFile())['at'] ?? readJson($flash)['at'] ?? null;
    $armed = (fanboyBoardRaw()['armed'] ?? false) === true;
    if ($armed) {
        fanboyHandBack();
    }
    logLine('Fanboy: the fans were given back to the mainboard while the array wasn\'t started' . (is_int($at) ? ' (' . date('Y-m-d H:i:s', $at) . ')' : '')
        . ($armed ? ' — kept so now: handed back, until «Take over»' : ' — he was not armed any more'));
    @unlink(fanboyHandBackFile());
    @unlink($flash);
    return true;
}
