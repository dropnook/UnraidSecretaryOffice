<?php
declare(strict_types=1);

/*
 * Ms. Moverelli and Unraid's own mover (2026-10-12, the owner's decision): once she moves, Unraid's mover doesn't.
 *
 *   - Her setup switches Unraid's own ⟦Mover schedule⟧ off — with consent: while it is on, moverelli.save (and the
 *     import's apply) is refused with `moverelli_mover_consent` {schedule} unless the request carries `mover_off: true`;
 *     her page asks first («Unraid's own mover will be switched off — I move from now on»). With it, after her checks
 *     and before anything of hers is written, jackMoverOff() does what Unraid's form does with «Disabled» + ⟦Apply⟧
 *     (emcmd, jack-mover.php) and looks that it took — refused (`moverelli_mover_off_failed`), nothing of hers is
 *     written. Never a hand-written cron line.
 *   - What it was is noted once (MOVERELLI_UNRAID_NOTE in her data folder: {v, was, logging, at}) — by every switch-off
 *     through jackMoverOff() (hers, Jack Emby's button), only when there is no note yet: the first schedule is the one
 *     that comes back.
 *   - Letting her go (her part of the let-go dialog: moverelli.letgo_look, moverelli.letgo {confirm: true}): refused
 *     while Jack Emby is hired (`moverelli_jack_hired` — he never works without her; src/staff.php refuses the let-go
 *     too); her schedule off (the night run's time); Unraid's own schedule back exactly as noted, again through
 *     Unraid's form (`shareMoverSchedule=<was>&shareMoverLogging=<as it is>&changeMover=Apply`, the value URL-encoded
 *     as the browser sends it), looked at afterwards: share.cfg says it and mover.cron has its `mover start` line. A
 *     schedule set by hand meanwhile stays (`kept`); a noted value of a shape Unraid's form can't make is never sent
 *     (`moverelli_unraid_back_failed`). The note goes once it is back (or kept).
 */

const MOVERELLI_UNRAID_NOTE = 'office-unraid-mover.json';
// the shapes Unraid's ⟦Mover Settings⟧ form makes: «min hour dotm * day» — hourly (*/N hours), daily, weekly, monthly
const MOVERELLI_UNRAID_CRON = '/^(?:[0-5]?\d) (?:[01]?\d|2[0-3]|\*\/[1-9]) (?:[1-9]|[12]\d|3[01]|\*) \* (?:[0-6]|\*)$/D';

/** The schedule noted when Unraid's mover was switched off: {was, logging, at} or null */
function moverelliUnraidNote(string $dir = MOVERELLI_DATA): ?array
{
    $n = readJson("$dir/" . MOVERELLI_UNRAID_NOTE);
    return is_array($n) && is_string($n['was'] ?? null) ? $n : null;
}

/** Notes the schedule Unraid's mover had before it is switched off — only the first (jackMoverOff() calls it) */
function moverelliUnraidNoteKeep(string $was, string $logging, string $dir = MOVERELLI_DATA): void
{
    if ($was === '' || moverelliUnraidNote($dir) !== null) {
        return;
    }
    moverelliDataDir($dir);
    writeAtomic("$dir/" . MOVERELLI_UNRAID_NOTE, jsonEncode(['v' => 1, 'was' => $was, 'logging' => $logging, 'at' => time()]), 0600, 0, 0);
}

/**
 * Her save's consent: Unraid's own schedule on (her host's look, `unraid`) and no `mover_off` → refused with what it
 * is; with it → switched off now (jackMoverOff(), her name in the log). Off already: nothing. {off: bool — switched off
 * just now, was}
 */
function moverelliUnraidOffForSave(bool $consent, array $h, ?array $moverHost = null): array
{
    $own = ($h['unraid'])();
    if (jackMoverScheduleOff($own)) {
        return ['off' => false, 'was' => null];
    }
    if (!$consent) {
        throw new Problem('moverelli_mover_consent', ['schedule' => (string) ($own['cron'] ?? $own['set'])]);
    }
    $r = moverelliMoverOff(['confirm' => true], $moverHost);
    return ['off' => !$r['already'], 'was' => $r['was']];
}

/**
 * Unraid's own schedule back as noted — after her let-go's checks. {back: true, was} | {kept: true, schedule} (set by
 * hand meanwhile) | {none: true} (nothing noted) | throws moverelli_unraid_back_failed.
 */
function moverelliUnraidBack(?array $moverHost = null, string $dir = MOVERELLI_DATA): array
{
    $h = $moverHost ?? jackMoverHost();
    $note = moverelliUnraidNote($dir);
    if ($note === null) {
        return ['none' => true];
    }
    $own = jackMoverOwn($h);
    if (!jackMoverScheduleOff($own)) {
        @unlink("$dir/" . MOVERELLI_UNRAID_NOTE);
        return ['kept' => true, 'schedule' => (string) ($own['cron'] ?? $own['set'])];
    }
    $was = $note['was'];
    if (!preg_match(MOVERELLI_UNRAID_CRON, $was)) {
        throw new Problem('moverelli_unraid_back_failed', ['detail' => 'the noted schedule «' . mb_substr($was, 0, 40) . '» isn\'t one Unraid\'s form makes']);
    }
    $logging = jackMoverLogging($h) ?? 'no';
    $bin = $h['emcmd'];
    if (!is_file($bin) || !is_executable($bin)) {
        throw new Problem('moverelli_unraid_back_failed', ['detail' => "$bin not found"]);
    }
    [$exit, $out, $err] = run([$bin, 'shareMoverSchedule=' . rawurlencode($was) . "&shareMoverLogging=$logging&changeMover=Apply"], 30);
    $on = fn (array $o): bool => $o['set'] === $was && $o['cron'] !== null;
    for ($i = 0; $i < 10 && !$on($own = jackMoverOwn($h)); $i++) {
        ($h['wait'])(300);
    }
    $said = mb_substr(trim($out . ' ' . $err), 0, 300);
    if (!$on($own)) {
        logLine("Ms. Moverelli: could not switch Unraid's mover schedule back on («{$was}»)" . ($exit !== 0 ? " — emcmd: exit $exit $said" : ''));
        throw new Problem('moverelli_unraid_back_failed', ['detail' => "share.cfg says «{$own['set']}»" . ($exit !== 0 && $said !== '' ? " — $said" : '')]);
    }
    @unlink("$dir/" . MOVERELLI_UNRAID_NOTE);
    logLine("Ms. Moverelli: let go — Unraid's mover schedule back on («{$was}», as before her)");
    return ['back' => true, 'was' => $was];
}

/** moverelli.letgo_look — what letting her go does: Jack Emby still hired (then she can't go), her schedule, Unraid's schedule back */
function moverelliLetGoLook(?array $h = null): array
{
    $h ??= moverelliHost();
    $own = ($h['unraid'])();
    $note = moverelliUnraidNote($h['dir']);
    return ['ok' => true, 'jack' => ($h['jack_desk'])() !== null,
            'schedule' => officeJobSchedule('moverelli', $h['cron_file'])['custom'],
            'unraid' => ['off' => jackMoverScheduleOff($own), 'back' => $note['was'] ?? null]];
}

/**
 * moverelli.letgo {confirm: true} — while she is still hired: refused while Jack Emby is hired; her schedule off;
 * Unraid's own schedule back (moverelliUnraidBack()). {ok, schedule_off, unraid: {back|kept|none} or {error}}
 */
function moverelliLetGo(array $r, ?array $h = null, ?array $moverHost = null): array
{
    if (($r['confirm'] ?? null) !== true) {
        throw new Problem('bad_request');
    }
    $h ??= moverelliHost();
    if (($h['jack_desk'])() !== null) {
        throw new Problem('moverelli_jack_hired');
    }
    $was = officeJobSchedule('moverelli', $h['cron_file'])['custom'];
    if ($was !== null) {
        moverelliSetSchedule(null, $h);
    }
    try {
        $unraid = moverelliUnraidBack($moverHost, $h['dir']);
    } catch (Problem $p) {
        $unraid = ['error' => $p->toArray()];
    }
    return ['ok' => true, 'schedule_off' => $was !== null, 'unraid' => $unraid];
}
