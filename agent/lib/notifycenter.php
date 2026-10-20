<?php
declare(strict_types=1);

/*
 * The Team Lead's notification centre («Meldungen», 1.68): the office's one place for what goes to Unraid's
 * notifications. ONE gate in front of sending — officeNotify() (the agent, its jobs, the night shift), the engine's
 * ub_notify() and scripts/agent.sh's tell() (both through backup/lib/notifygate.sh) ask the same decision: send now ·
 * hold for the summary · count only. The desks send as before (event, level, words); only the gate decides.
 *
 *   data/office/notify.json        the settings (the truth; the page writes them through the agent, tolerant)
 *   RUN_DIR/notify.cfg              the same as key=value for every sender — bash reads it, the night shift too (also
 *                                   on the flash for a night shift after a reboot); none = everything goes, as before
 *   RUN_DIR/notify-guard            the flood guard's lines «<time> <fate> <desk> <level> <key>» (sent, flood, resolved),
 *                                   read and written only under the flock of RUN_DIR/notify.second every sender holds
 *   RUN_DIR/notify-in/*.json        each sender's decision; the agent folds them once a minute into
 *   data/office/notify-held.json    what waits for a summary (its copy in RAM: RUN_DIR/notify-held.json, the night shift's)
 *   data/office/notify-log.json     the history the page shows (30 days)
 *
 * Alerts always go right away (never held, never off); the flood guard holds for them too, but only the same alert
 * until it is resolved, and past NOTIFY_ALERT_CAP an hour the rest as one alert every NOTIFY_ALERT_BUNDLE.
 * The numbers are the same as in backup/lib/notifygate.sh (tests/run.php compares them).
 */

const NOTIFY_SAME_FORGET  = 86400;    // an unresolved repeat may go out again after a day (a daily failure still reminds daily)
const NOTIFY_CAP_WINDOW   = 3600;     // the caps count the last hour
const NOTIFY_DESK_CAP     = 5;        // more than this from one character in an hour is a flood
const NOTIFY_OFFICE_CAP   = 20;       // more than this from the whole office in an hour is a flood
const NOTIFY_ALERT_CAP    = 10;       // distinct alerts in an hour before the rest are bundled
const NOTIFY_ALERT_BUNDLE = 900;      // a bundle of the rest at most every 15 minutes
const NOTIFY_HEART_FRESH  = 70;       // the agent's heartbeat at most this old: it will send the summary
const NOTIFY_DROPS_MAX    = 500;      // decisions waiting for the agent at most — past it nothing is held (it goes now)
const NOTIFY_GUARD_MAX    = 3000;     // lines of the guard before the old ones go
const NOTIFY_LOG_DAYS     = 30;       // the history keeps a month …
const NOTIFY_LOG_MAX      = 300;      // … at most so many entries
const NOTIFY_SUMMARY_LINES = 20;      // lines of a summary's long text, then «… and N more»
const NOTIFY_SILENT_MAX   = 60 * 86400;   // «Still bis …» at most two months ahead

// the characters who send, in the page's order — `office` the office itself (scripts/agent.sh: the agent away, back)
const OFFICE_NOTIFY_DESKS = ['caretaker', 'backup', 'snapshot', 'restore', 'watchman', 'moverelli', 'jack', 'logs', 'office'];
// what each of them sends (the page says it; a desk without alerts shows «Nur Alarme» as «Aus»)
const OFFICE_NOTIFY_SENDS = [
    'caretaker' => ['warning'], 'backup' => ['alert', 'warning', 'normal'], 'snapshot' => ['warning'], 'restore' => ['warning'],
    'watchman'  => ['alert', 'warning', 'normal'], 'moverelli' => ['warning', 'normal'], 'jack' => ['warning', 'normal'],
    'logs'      => ['warning'], 'office' => ['alert', 'normal'],
];
// successes: the desk's «auch Erfolge» (ung_is_success in notifygate.sh)
const OFFICE_NOTIFY_SUCCESS  = ['backup' => ['ok'], 'jack' => ['release_ok']];
// what resolves the desk's «the same» (ung_resolves): a success, the agent back
const OFFICE_NOTIFY_RESOLVES = ['backup' => ['ok'], 'jack' => ['release_ok'], 'office' => ['agent_back']];
const OFFICE_NOTIFY_LEVELS   = ['all', 'warning', 'alert'];
const OFFICE_NOTIFY_FATES    = ['sent', 'held', 'counted', 'collected', 'dropped', 'failed'];
const OFFICE_NOTIFY_CFG_FLASH = '/boot/config/plugins/' . OFFICE_PLUGIN . '/notify.cfg';
define('OFFICE_NOTIFY_FILE', OFFICE_PRIVATE . '/notify.json');
define('OFFICE_NOTIFY_HELD', OFFICE_PRIVATE . '/notify-held.json');
define('OFFICE_NOTIFY_LOG', OFFICE_PRIVATE . '/notify-log.json');

/**
 * The centre's folder in RAM: RUN_DIR — with a stand-in notify (the tests) only the folder OFFICE_NOTIFY_HOME names,
 * else none (then everything goes as before the centre)
 */
function officeNotifyHome(): ?string
{
    $home = getenv('OFFICE_NOTIFY_BIN') ? (string) getenv('OFFICE_NOTIFY_HOME') : RUN_DIR;
    return $home !== '' && is_dir($home) && !is_link($home) ? $home : null;
}

// ===================================================================== the settings

/** One desk's settings, each field checked (anything else: its default — the same as before the centre) */
function officeNotifyDeskSettings(mixed $d): array
{
    $d = is_array($d) ? $d : [];
    return [
        'level'   => in_array($d['level'] ?? null, OFFICE_NOTIFY_LEVELS, true) ? $d['level'] : 'all',
        'success' => is_bool($d['success'] ?? null) ? $d['success'] : true,
        'when'    => in_array($d['when'] ?? null, ['now', 'summary'], true) ? $d['when'] : 'now',
        'at'      => is_string($d['at'] ?? null) && officeNotifyHhmmOk($d['at']) ? $d['at'] : null,
    ] + $d;      // keys a newer office wrote stay
}

function officeNotifyHhmmOk(string $t): bool
{
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $t) === 1;
}

/**
 * The settings (data/office/notify.json) — tolerant: a field in a shape this version doesn't know reads as its default,
 * unknown keys and desks stay for the writer. None: today's behaviour (everything right away).
 */
function officeNotifySettings(?array $raw = null): array
{
    $raw ??= (is_file(OFFICE_NOTIFY_FILE) && !is_link(OFFICE_NOTIFY_FILE) ? readJson(OFFICE_NOTIFY_FILE) : null) ?? [];
    $q = is_array($raw['quiet'] ?? null) ? $raw['quiet'] : [];
    $desks = [];
    foreach ((is_array($raw['desks'] ?? null) ? $raw['desks'] : []) as $id => $d) {
        if (is_string($id) && preg_match('/^[a-z][a-z_]{0,30}$/D', $id)) {
            $desks[$id] = officeNotifyDeskSettings($d);
        }
    }
    foreach (OFFICE_NOTIFY_DESKS as $id) {
        $desks[$id] ??= officeNotifyDeskSettings([]);
    }
    return [
        'v'            => 1,
        'at'           => is_string($raw['at'] ?? null) && officeNotifyHhmmOk($raw['at']) ? $raw['at'] : '08:00',
        'desks'        => $desks,
        'quiet'        => [
            'on'   => ($q['on'] ?? false) === true,
            'from' => is_string($q['from'] ?? null) && officeNotifyHhmmOk($q['from']) ? $q['from'] : '22:00',
            'to'   => is_string($q['to'] ?? null) && officeNotifyHhmmOk($q['to']) ? $q['to'] : '07:00',
        ] + $q,
        'silent_until' => is_int($raw['silent_until'] ?? null) && $raw['silent_until'] > 0 ? $raw['silent_until'] : 0,
        'all_well'     => ($raw['all_well'] ?? false) === true,
    ] + $raw;
}

/** The settings as the senders read them: key=value, every value in a shape notifygate.sh accepts */
function officeNotifyCfgText(array $s): string
{
    $hhmm = fn (string $t) => str_replace(':', '', $t);
    $lines = ['v=1', 'at=' . $hhmm($s['at']), 'quiet=' . ($s['quiet']['on'] && $s['quiet']['from'] !== $s['quiet']['to']
        ? $hhmm($s['quiet']['from']) . '-' . $hhmm($s['quiet']['to']) : 'off'), 'silent_until=' . (int) $s['silent_until']];
    foreach ($s['desks'] as $id => $d) {
        $lines[] = "$id.level={$d['level']}";
        $lines[] = "$id.success=" . ($d['success'] ? '1' : '0');
        $lines[] = "$id.when={$d['when']}";
        $lines[] = "$id.at=" . $hhmm($d['at'] ?? $s['at']);
    }
    return "# Unraid Secretary Office — the Team Lead's notification settings, written by the agent from data/office/notify.json\n"
        . implode("\n", $lines) . "\n";
}

/** The key=value file read back (the same rules as ung_cfg_read): null — none, or not of this shape */
function officeNotifyCfgRead(?string $file): ?array
{
    if ($file === null || !is_file($file) || is_link($file) || (int) @filesize($file) > 16384) {
        return null;
    }
    $cfg = [];
    foreach (explode("\n", (string) @file_get_contents($file)) as $line) {
        if (preg_match('/^([a-z][a-z_]{0,30}(?:\.[a-z_]{1,20})?)=([A-Za-z0-9_-]{0,24})$/D', rtrim($line, "\r"), $m)) {
            $cfg[$m[1]] = $m[2];
        }
    }
    return ($cfg['v'] ?? '') === '1' ? $cfg : null;
}

/** The senders' file of the settings in RAM, else on the flash (a night shift after a reboot) */
function officeNotifyCfg(?string $home): ?array
{
    if ($home === null) {
        return null;
    }
    return officeNotifyCfgRead("$home/notify.cfg") ?? (getenv('OFFICE_NOTIFY_BIN') ? null : officeNotifyCfgRead(OFFICE_NOTIFY_CFG_FLASH));
}

/** The settings into the senders' file — RAM, and the flash (only when it changed: the stick is written rarely) */
function officeNotifyCfgKeep(array $s, ?string $home = null, ?string $flash = null): void
{
    $home ??= officeNotifyHome();
    $text = officeNotifyCfgText($s);
    if ($home !== null && @file_get_contents("$home/notify.cfg") !== $text) {
        writeAtomic("$home/notify.cfg", $text, 0600, 0, 0);
    }
    $flash ??= getenv('OFFICE_NOTIFY_BIN') ? null : OFFICE_NOTIFY_CFG_FLASH;
    if ($flash !== null && !NIGHT_MODE && is_dir(dirname($flash)) && @file_get_contents($flash) !== $text) {
        try {
            writeAtomic($flash, $text, 0600, 0, 0);
        } catch (Throwable $e) {
            logLine('Notifications: the copy of the settings on the flash could not be written: ' . $e->getMessage());
        }
    }
}

// ===================================================================== the decision

function officeNotifyRank(string $level): int
{
    return ['alert' => 2, 'warning' => 1][$level] ?? 0;
}

function officeNotifyIsSuccess(string $desk, string $event): bool
{
    return in_array($event, OFFICE_NOTIFY_SUCCESS[$desk] ?? [], true);
}

function officeNotifyResolves(string $desk, string $event): bool
{
    return in_array($event, OFFICE_NOTIFY_RESOLVES[$desk] ?? [], true);
}

/** «The same notification»: 16 hex over desk|event|subject (ung_key) */
function officeNotifyKey(string $desk, string $event, string $subject): string
{
    return substr(md5("$desk|$event|$subject"), 0, 16);
}

/** Inside the quiet hours (quiet=HHMM-HHMM from the senders' file; across midnight when from > to) */
function officeNotifyQuietNow(?array $cfg, int $now): bool
{
    if (!preg_match('/^([0-2]\d[0-5]\d)-([0-2]\d[0-5]\d)$/D', (string) ($cfg['quiet'] ?? 'off'), $m)) {
        return false;
    }
    [$from, $to, $t] = [(int) $m[1], (int) $m[2], (int) date('Hi', $now)];
    if ($from === $to) {
        return false;
    }
    return $from < $to ? $t >= $from && $t < $to : $t >= $from || $t < $to;
}

function officeNotifySilentNow(?array $cfg, int $now): bool
{
    $until = (string) ($cfg['silent_until'] ?? '0');
    return preg_match('/^\d{1,12}$/D', $until) === 1 && (int) $until > $now;
}

/**
 * The decision — the same as ung_decide() in notifygate.sh. $cfg the senders' file (null: none, everything as before),
 * $lines the guard's, $hold: someone will send the summary and there is room to keep it, $room: room to keep it.
 *
 * @return array{fate: string, why: string, due: int, notice: string}
 */
function officeNotifyDecide(?array $cfg, array $lines, string $desk, string $event, string $level, string $key, int $now, bool $hold, bool $room): array
{
    $d = ['fate' => 'sent', 'why' => '', 'due' => 0, 'notice' => ''];
    if ($level !== 'alert' && $cfg !== null) {
        $need = ['warning' => 1, 'alert' => 2][$cfg["$desk.level"] ?? 'all'] ?? 0;
        if (officeNotifyRank($level) < $need) {
            return ['fate' => 'dropped', 'why' => 'threshold'] + $d;
        }
        if (officeNotifyIsSuccess($desk, $event) && ($cfg["$desk.success"] ?? '1') === '0') {
            return ['fate' => 'dropped', 'why' => 'success'] + $d;
        }
        $why = officeNotifySilentNow($cfg, $now) ? 'silent' : (officeNotifyQuietNow($cfg, $now) ? 'quiet'
             : (($cfg["$desk.when"] ?? 'now') === 'summary' ? 'summary' : ''));
        if ($why !== '') {
            if ($hold) {
                return ['fate' => 'held', 'why' => $why] + $d;
            }
            $d['why'] = 'no_sender';         // nobody would send the summary: it goes now
        }
    }
    return officeNotifyGuardDecide($lines, $desk, $level, $key, $now, $hold, $room, $d);
}

/** The flood guard for what would go now (ung_guard) */
function officeNotifyGuardDecide(array $lines, string $desk, string $level, string $key, int $now, bool $hold, bool $room, array $d): array
{
    if (!$lines) {
        return $d;
    }
    $same = $dk = $off = $al = $fd = $fo = 0;
    foreach ($lines as [$t, $fate, $who, $lvl, $k]) {      // in the order they were written: a resolve after it resolves it
        if ($fate === 'sent' && $k === $key && $now - $t < NOTIFY_SAME_FORGET) {
            $same = $t;
        }
        if ($fate === 'resolved' && $who === $desk) {
            $same = 0;
        }
        if ($fate === 'sent' && $now - $t < NOTIFY_CAP_WINDOW) {
            if ($lvl === 'alert') {
                $al++;
            } else {
                $off++;
                $dk += $who === $desk ? 1 : 0;
            }
        }
        if ($fate === 'flood' && $now - $t < NOTIFY_CAP_WINDOW) {
            $fd = $who === $desk ? $t : $fd;
            $fo = $who === '*' ? $t : $fo;
        }
    }
    if ($same > 0) {
        return ['fate' => 'counted', 'why' => 'same'] + $d;
    }
    if ($level === 'alert') {
        return $al >= NOTIFY_ALERT_CAP && $hold ? ['fate' => 'collected', 'why' => 'alert_cap'] + $d : $d;
    }
    if (!$room) {
        return $d;
    }
    foreach ([[$dk >= NOTIFY_DESK_CAP, $fd, $desk], [$off >= NOTIFY_OFFICE_CAP, $fo, '*']] as [$over, $since, $who]) {
        if ($over) {
            return ['fate' => 'collected', 'why' => 'flood', 'due' => ($since ?: $now) + NOTIFY_CAP_WINDOW, 'notice' => $since ? '' : $who] + $d;
        }
    }
    return $d;
}

/** The guard's lines: [time, fate, desk, level, key] */
function officeNotifyGuardRead(?string $home): array
{
    $file = $home === null ? '' : "$home/notify-guard";
    if ($file === '' || !is_file($file) || is_link($file)) {
        return [];
    }
    $out = [];
    foreach (explode("\n", (string) @file_get_contents($file, false, null, 0, 1 << 20)) as $line) {
        $p = explode(' ', $line);
        if (count($p) === 5 && preg_match('/^\d{1,12}$/D', $p[0])) {
            $out[] = [(int) $p[0], $p[1], $p[2], $p[3], $p[4]];
        }
    }
    return $out;
}

/** One line of the guard (ung_line); past NOTIFY_GUARD_MAX lines the ones older than two days go */
function officeNotifyGuardLine(string $home, string $fate, string $desk, string $level, string $key, ?int $now = null): void
{
    $file = "$home/notify-guard";
    if (is_link($file)) {
        return;
    }
    $now ??= time();
    $old = umask(0077);
    @file_put_contents($file, "$now $fate $desk $level " . ($key !== '' ? $key : '-') . "\n", FILE_APPEND);
    umask($old);
    $lines = officeNotifyGuardRead($home);
    if (count($lines) > NOTIFY_GUARD_MAX) {
        $keep = array_filter($lines, fn ($l) => $l[0] > $now - 2 * NOTIFY_SAME_FORGET);
        writeAtomic($file, implode('', array_map(fn ($l) => implode(' ', $l) . "\n", $keep)), 0600, 0, 0);
    }
}

/** Will someone send the summary? The agent or its night shift is the sender; else the heartbeat fresh */
function officeNotifyCanHold(string $home): bool
{
    if (!empty($GLOBALS['officeNotifySender'])) {
        return true;
    }
    clearstatcache(true, "$home/agent.json");
    $beat = (int) @filemtime("$home/agent.json");
    return $beat > 0 && time() - $beat <= NOTIFY_HEART_FRESH;
}

function officeNotifyDropsRoom(string $home): bool
{
    return count(glob("$home/notify-in/*.json") ?: []) < NOTIFY_DROPS_MAX;
}

/** A decision for the Team Lead's queue and history (ung_drop): a JSON of its own in notify-in/ */
function officeNotifyDrop(string $home, array $item): void
{
    $dir = "$home/notify-in";
    if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0700))) {
        return;
    }
    [$us, $sec] = explode(' ', microtime());
    $item += ['v' => 1, 'id' => bin2hex(random_bytes(6)), 'time' => (int) $sec];
    $item['message'] = mb_strcut((string) ($item['message'] ?? ''), 0, 4096);
    try {
        // the name sorts by the microsecond it was decided in (as notifygate.sh names them)
        writeAtomic(sprintf('%s/%d%06d-%s.json', $dir, (int) $sec, (int) round((float) $us * 1e6) % 1000000, $item['id']), jsonEncode($item), 0600, 0, 0);
    } catch (Throwable $e) {
        // nothing to keep it in: the history misses it, the notification itself was decided
    }
}

/**
 * officeNotify()'s gate, called while it holds the shared stamp's flock: the decision, the flood guard's line before
 * the call is known (the caller writes «sent» after it went) — null: no centre here (everything as before).
 */
function officeNotifyGate(string $desk, string $event, string $level, string $subject): ?array
{
    $home = officeNotifyHome();
    if ($home === null) {
        return null;
    }
    $now = time();
    $key = officeNotifyKey($desk, $event, $subject);
    if (officeNotifyResolves($desk, $event)) {
        officeNotifyGuardLine($home, 'resolved', $desk, '-', '-', $now);
    }
    $room = officeNotifyDropsRoom($home);
    return ['home' => $home, 'key' => $key, 'now' => $now]
        + officeNotifyDecide(officeNotifyCfg($home), officeNotifyGuardRead($home), $desk, $event, $level, $key, $now, $room && officeNotifyCanHold($home), $room);
}

/** The «reporting a lot» notice past a cap — its words (the desk's name, or the whole office's) */
function officeNotifyFloodText(string $who, int $due, string $lang): array
{
    $time = date('H:i', $due);
    return $who === '*'
        ? [officeNotifyText('', 'notifycenter.flood_all.subject', [], $lang), officeNotifyText('', 'notifycenter.flood', ['time' => $time], $lang)]
        : [officeNotifyText('', 'notifycenter.flood.subject', ['name' => officeNotifyName($who, $lang)], $lang),
           officeNotifyText('', 'notifycenter.flood', ['time' => $time], $lang)];
}

/** A character's name in the notifications (the office itself: «Das Sekretariat») */
function officeNotifyName(string $desk, string $lang): string
{
    return ($desk === 'office' || $desk === '*' ? officeNotifyText('', 'notifycenter.office', [], $lang) : officeNotifyText($desk, 'name', [], $lang)) ?: $desk;
}

// ===================================================================== the queue, the history, the summaries

/** A decision as the senders wrote it — only in exactly that shape */
function officeNotifyItemOk(mixed $i): bool
{
    return is_array($i) && ($i['v'] ?? null) === 1 && is_string($i['id'] ?? null) && preg_match('/^[0-9a-z]{1,24}$/D', $i['id'])
        && is_int($i['time'] ?? null) && is_string($i['desk'] ?? null) && preg_match('/^[a-z][a-z_]{0,30}$/D', $i['desk'])
        && is_string($i['event'] ?? null) && preg_match('/^[a-z0-9_]{0,40}$/D', $i['event'])
        && in_array($i['level'] ?? null, ['normal', 'warning', 'alert'], true) && in_array($i['fate'] ?? null, OFFICE_NOTIFY_FATES, true)
        && is_string($i['why'] ?? null) && is_int($i['due'] ?? null) && is_string($i['subject'] ?? null) && is_string($i['description'] ?? null)
        && is_string($i['message'] ?? '') && is_string($i['link'] ?? '') && is_string($i['key'] ?? '')
        && (!isset($i['covers']) || (is_array($i['covers']) && array_is_list($i['covers'])));
}

/** The decisions waiting in RAM, oldest first (their names: <epoch microseconds>-<id>.json): [file => item] */
function officeNotifyDropsRead(string $home): array
{
    $out = [];
    foreach (glob("$home/notify-in/*.json") ?: [] as $file) {
        if (!is_link($file) && (int) @filesize($file) <= 65536 && officeNotifyItemOk($i = readJson($file))) {
            $out[$file] = $i;
        } elseif (filemtime($file) < time() - 86400) {
            @unlink($file);         // not of this shape and a day old: nobody reads it
        }
    }
    return $out;            // glob() sorts by name: by the microsecond each was decided in
}

/** The queue: {v, items, bundle (when the last alert bundle went), all_well (the day the last sign of life went)} */
function officeNotifyQueueRead(string $file): array
{
    $q = (!is_link($file) ? readJson($file) : null) ?? [];
    return ['v' => 1, 'items' => array_values(array_filter(is_array($q['items'] ?? null) ? $q['items'] : [], 'officeNotifyItemOk')),
            'bundle' => is_int($q['bundle'] ?? null) ? $q['bundle'] : 0, 'all_well' => is_string($q['all_well'] ?? null) ? $q['all_well'] : ''] + $q;
}

/** The first time HH:MM comes after $after */
function officeNotifyNextAt(int $after, string $hhmm): int
{
    [$h, $m] = array_map('intval', explode(':', $hhmm) + [0, 0]);
    $t = mktime($h, $m, 0, (int) date('n', $after), (int) date('j', $after), (int) date('Y', $after));
    return $t > $after ? $t : (int) strtotime('+1 day', $t);
}

/** When a held item goes (0: now). The summary waits while «Still bis …» or the quiet hours last. */
function officeNotifyItemDue(array $i, ?array $cfg, int $now): int
{
    if ($i['why'] === 'alert_cap') {
        return 0;
    }
    $hhmm = fn (string $v) => preg_match('/^(\d\d)(\d\d)$/D', $v, $m) ? "$m[1]:$m[2]" : '08:00';
    $due = match ($i['why']) {
        'flood'   => $i['due'],
        'summary' => ($cfg[$i['desk'] . '.when'] ?? 'now') === 'summary'
            ? officeNotifyNextAt($i['time'], $hhmm((string) ($cfg[$i['desk'] . '.at'] ?? $cfg['at'] ?? '0800'))) : 0,
        default   => 0,
    };
    if (officeNotifySilentNow($cfg, $now)) {
        return max($due, (int) $cfg['silent_until']);
    }
    return officeNotifyQuietNow($cfg, $now) ? max($due, $now + 60) : $due;
}

/**
 * What goes now: ['summary' => items due, 'bundle' => alerts past the cap — every NOTIFY_ALERT_BUNDLE]
 */
function officeNotifyDueNow(array $queue, ?array $cfg, int $now): array
{
    $sum = $bundle = [];
    foreach ($queue['items'] as $i) {
        if ($i['why'] === 'alert_cap') {
            if ($now - $queue['bundle'] >= NOTIFY_ALERT_BUNDLE) {
                $bundle[] = $i;
            }
        } elseif (officeNotifyItemDue($i, $cfg, $now) <= $now) {
            $sum[] = $i;
        }
    }
    return ['summary' => $sum, 'bundle' => $bundle];
}

/**
 * A summary's words: the same notification (its key) once with how often, newest last; the subject «Zusammenfassung:
 * N Meldungen», the description the first subjects, the long text one line each «• 07:12 Name — subject: description»
 *
 * @return array{0: string, 1: string, 2: string, 3: string} subject, description, message, level
 */
function officeNotifySummaryText(array $items, string $lang, bool $alerts = false): array
{
    $groups = [];
    foreach ($items as $i) {
        $k = $i['key'] !== '' ? $i['key'] : $i['id'];
        $groups[$k] ??= ['first' => $i, 'last' => $i, 'n' => 0];
        $groups[$k]['last'] = $i;
        $groups[$k]['n'] += max(1, (int) ($i['n'] ?? 1));
    }
    $n = array_sum(array_column($groups, 'n'));
    $lines = $subjects = [];
    foreach ($groups as $g) {
        $i = $g['last'];
        $subjects[] = $i['subject'];
        if (count($lines) < NOTIFY_SUMMARY_LINES) {
            $when = date('H:i', $g['first']['time']) . ($g['n'] > 1 && $g['first']['time'] !== $i['time'] ? '–' . date('H:i', $i['time']) : '');
            $lines[] = "• $when " . trim($i['subject'] . ($i['description'] !== '' ? ': ' . $i['description'] : ''))
                . ($g['n'] > 1 ? ' — ' . officeNotifyText('', 'notifycenter.times', ['n' => $g['n']], $lang) : '');
        }
    }
    if (count($groups) > count($lines)) {
        $lines[] = officeNotifyText('', 'notifycenter.more', ['n' => count($groups) - count($lines)], $lang);
    }
    $list = implode('; ', array_slice($subjects, 0, 3)) . (count($subjects) > 3 ? ' ' . officeNotifyText('', 'notifycenter.more', ['n' => count($subjects) - 3], $lang) : '');
    $level = $alerts ? 'alert' : (array_filter($items, fn ($i) => $i['level'] !== 'normal') ? 'warning' : 'normal');
    return [officeNotifyText('', $alerts ? 'notifycenter.bundle.subject' : 'notifycenter.summary.subject', ['n' => $n], $lang), $list,
            implode("\n", $lines) . "\n\n" . officeNotifyText('', 'notifycenter.footer', [], $lang), $level];
}

/** Sends a summary (or an alert bundle) past the gate — true when Unraid took it */
function officeNotifySummarySend(array $items, string $lang, bool $alerts = false): bool
{
    [$subject, $desc, $message, $level] = officeNotifySummaryText($items, $lang, $alerts);
    return officeNotify($subject, $desc, $level, $message, officeNotifyLink('#/caretaker/notify'), 'caretaker', $alerts ? 'bundle' : 'summary', false);
}

/** The history (data/office/notify-log.json): newest last, a month, at most NOTIFY_LOG_MAX */
function officeNotifyLogRead(string $file = OFFICE_NOTIFY_LOG): array
{
    $l = (!is_link($file) ? readJson($file) : null) ?? [];
    return array_values(array_filter(is_array($l['entries'] ?? null) ? $l['entries'] : [], fn ($e) => is_array($e) && is_int($e['time'] ?? null)));
}

function officeNotifyLogEntry(array $i): array
{
    return array_intersect_key($i, array_flip(['id', 'time', 'desk', 'event', 'level', 'fate', 'why', 'subject', 'description', 'n', 'covers']));
}

/**
 * The agent's minute (serve(): once a minute): the settings into the senders' file, the decisions folded into the queue
 * and the history, what is due sent as one summary, the alert bundle, the sign of life. $now and the files for the tests.
 */
function officeNotifyTick(?int $now = null, ?string $home = null, ?string $held = null, ?string $log = null, ?string $settingsFile = null): void
{
    $home ??= officeNotifyHome();
    if ($home === null || !is_dir(dirname($held ?? OFFICE_NOTIFY_HELD))) {
        return;
    }
    $now ??= time();
    $held ??= OFFICE_NOTIFY_HELD;
    $log ??= OFFICE_NOTIFY_LOG;
    $s = officeNotifySettings($settingsFile !== null ? (readJson($settingsFile) ?? []) : null);
    officeNotifyCfgKeep($s, $home, $settingsFile !== null ? null : (getenv('OFFICE_NOTIFY_BIN') ? null : OFFICE_NOTIFY_CFG_FLASH));
    $lock = @fopen("$home/notify.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return;
    }
    try {
        $queue = officeNotifyQueueRead($held);
        $before = $queue;
        $entries = officeNotifyLogRead($log);
        $added = false;
        // the decisions: into the history; what waits into the queue — unless a summary of the night shift covers it
        $drops = officeNotifyDropsRead($home);
        $covered = [];
        foreach ($drops as $i) {
            foreach ((array) ($i['covers'] ?? []) as $id) {
                $covered[(string) $id] = true;
            }
        }
        foreach ($drops as $file => $i) {
            $entries[] = officeNotifyLogEntry($i);
            $added = true;
            if (in_array($i['fate'], ['held', 'collected'], true) && !isset($covered[$i['id']])) {
                $queue['items'][] = $i;
            }
            @unlink($file);
        }
        $queue['items'] = array_values(array_filter($queue['items'], fn ($i) => !isset($covered[$i['id']])));
        $sent = officeNotifySendDue($queue, officeNotifyCfgRead("$home/notify.cfg"), $now, $entries);
        $added = $added || $sent;
        // the sign of life: once a day at the office's time, when nothing went out for a day
        if ($s['all_well'] && $queue['all_well'] !== date('Y-m-d', $now) && $now >= officeNotifyNextAt($now - 86400, $s['at'])
            && !array_filter($entries, fn ($e) => in_array($e['fate'] ?? '', ['sent', 'failed'], true) && $e['time'] > $now - 86400)) {
            $lang = officeNotifyLang();
            $ok = officeNotify(officeNotifyText('', 'notifycenter.all_well.subject', [], $lang), officeNotifyText('', 'notifycenter.all_well', [], $lang),
                'normal', '', officeNotifyLink('#/caretaker/notify'), 'caretaker', 'all_well', false);
            $queue['all_well'] = date('Y-m-d', $now);
            $entries[] = ['id' => bin2hex(random_bytes(6)), 'time' => $now, 'desk' => 'caretaker', 'event' => 'all_well', 'level' => 'normal',
                          'fate' => $ok ? 'sent' : 'failed', 'why' => '', 'subject' => officeNotifyText('', 'notifycenter.all_well.subject', [], $lang), 'description' => ''];
            $added = true;
        }
        if ($added) {
            $entries = array_values(array_filter($entries, fn ($e) => $e['time'] > $now - NOTIFY_LOG_DAYS * 86400));
            writeAtomic($log, jsonEncode(['v' => 1, 'entries' => array_slice($entries, -NOTIFY_LOG_MAX)]), 0600, 0, 0);
        }
        if ($queue !== $before || !is_file("$home/notify-held.json")) {
            writeAtomic($held, jsonEncode($queue), 0600, 0, 0);
            writeAtomic("$home/notify-held.json", jsonEncode($queue), 0600, 0, 0);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * What is due goes: one summary for everything due (in the office's last language), one alert bundle — the queue loses
 * what went, the history gets each summary (how many it covered). True when something went (or failed).
 */
function officeNotifySendDue(array &$queue, ?array $cfg, int $now, array &$entries): bool
{
    $due = officeNotifyDueNow($queue, $cfg, $now);
    if (!$due['summary'] && !$due['bundle']) {
        return false;
    }
    $lang = officeNotifyLang();
    foreach ([[$due['bundle'], true], [$due['summary'], false]] as [$items, $alerts]) {
        if (!$items) {
            continue;
        }
        $ok = officeNotifySummarySend($items, $lang, $alerts);
        [$subject] = officeNotifySummaryText($items, $lang, $alerts);
        $ids = array_column($items, 'id');
        $queue['items'] = array_values(array_filter($queue['items'], fn ($i) => !in_array($i['id'], $ids, true)));
        if ($alerts) {
            $queue['bundle'] = $now;
        }
        $entries[] = ['id' => bin2hex(random_bytes(6)), 'time' => $now, 'desk' => 'caretaker', 'event' => $alerts ? 'bundle' : 'summary',
                      'level' => $alerts ? 'alert' : 'warning', 'fate' => $ok ? 'sent' : 'failed', 'why' => '', 'subject' => $subject,
                      'description' => '', 'n' => count($items), 'covers' => $ids];
        // the night shift has no data folder: its own log in RAM
        (NIGHT_MODE && function_exists('nightLog') ? 'nightLog' : 'logLine')('Notifications: ' . ($alerts ? 'the alert bundle' : 'the summary') . ' of '
            . count($items) . ' ' . ($ok ? 'went' : 'could not go') . " to Unraid's notifications");
    }
    return true;
}

/**
 * The night shift's minute (nothing under /mnt): what waits — the agent's queue in RAM and the decisions since — goes
 * when due; its summary is a decision of its own that covers them (the agent folds it once it is back)
 */
function officeNotifyNightTick(?int $now = null, ?string $home = null): void
{
    $home ??= officeNotifyHome();
    if ($home === null) {
        return;
    }
    $now ??= time();
    $lock = @fopen("$home/notify.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return;
    }
    try {
        $queue = officeNotifyQueueRead("$home/notify-held.json");
        $drops = officeNotifyDropsRead($home);
        $covered = [];
        foreach ($drops as $i) {
            foreach ((array) ($i['covers'] ?? []) as $id) {
                $covered[(string) $id] = true;
            }
        }
        $inQueue = array_flip(array_column($queue['items'], 'id'));
        foreach ($drops as $i) {
            if (in_array($i['fate'], ['held', 'collected'], true) && !isset($covered[$i['id']]) && !isset($inQueue[$i['id']])) {
                $queue['items'][] = $i;
            }
        }
        $before = $queue;
        $entries = [];
        if (officeNotifySendDue($queue, officeNotifyCfg($home), $now, $entries)) {
            foreach ($entries as $e) {
                officeNotifyDrop($home, ['desk' => 'caretaker', 'event' => $e['event'], 'level' => $e['level'], 'fate' => $e['fate'], 'why' => '',
                    'due' => 0, 'key' => '', 'subject' => $e['subject'], 'description' => '', 'message' => '', 'link' => '', 'n' => $e['n'], 'covers' => $e['covers']]);
            }
        }
        if ($queue !== $before) {
            // the RAM copy keeps only the agent's items (the night's own stay decisions, covered by its summary's)
            $queue['items'] = array_values(array_filter($queue['items'], fn ($i) => isset($inQueue[$i['id']])));
            writeAtomic("$home/notify-held.json", jsonEncode($queue), 0600, 0, 0);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// ===================================================================== the page

const OFFICE_NOTIFY_CFG_DEFAULT = '/usr/local/emhttp/plugins/dynamix/default.cfg';     // Unraid's defaults under the user's

/**
 * Unraid's channels for a level (⟦Settings⟧ → ⟦Notification Settings⟧: 1 browser, 2 mail, 4 agents) — the user's file
 * over Unraid's defaults, as its notify script reads them (parse_plugin_cfg): a server never set up has no [notify] there
 */
function officeNotifyChannels(string $level, string $cfgFile = OFFICE_NOTIFY_CFG, string $defaults = OFFICE_NOTIFY_CFG_DEFAULT): array
{
    $cfg = readCfg($cfgFile, true);
    $base = readCfg($defaults, true);
    foreach ($base as $section => $keys) {
        $cfg[$section] = (array) ($cfg[$section] ?? []) + (array) $keys;
    }
    $bits = (int) ($cfg['notify'][$level] ?? 0);
    return ['browser' => (bool) ($bits & 1),
            'mail'    => (bool) ($bits & 2) && !empty($cfg['ssmtp']['server']) && !empty($cfg['ssmtp']['RcptTo']),
            'agents'  => (bool) ($bits & 4) && (bool) (glob('/boot/config/plugins/dynamix/notifications/agents/*.sh') ?: [])];
}

/**
 * The page's part «notify» (caretaker, desk.json parts): the settings, what each character sends, what waits (and how
 * the summary would read), the flood guard now, the history — from the files only
 */
function officeNotifyPublic(?int $now = null): array
{
    $now ??= time();
    $home = officeNotifyHome();
    $s = officeNotifySettings();
    $queue = officeNotifyQueueRead(OFFICE_NOTIFY_HELD);
    if ($home !== null) {
        $inQueue = array_flip(array_column($queue['items'], 'id'));
        foreach (officeNotifyDropsRead($home) as $i) {      // decided within the last minute, not folded yet
            if (in_array($i['fate'], ['held', 'collected'], true) && !isset($inQueue[$i['id']])) {
                $queue['items'][] = $i;
            }
        }
    }
    $cfg = $home !== null ? officeNotifyCfgRead("$home/notify.cfg") : null;
    $lang = officeNotifyLang();
    $waiting = [];
    foreach ($queue['items'] as $i) {
        $waiting[] = officeNotifyLogEntry($i) + ['due' => $i['why'] === 'alert_cap' ? max($now, $queue['bundle'] + NOTIFY_ALERT_BUNDLE) : officeNotifyItemDue($i, $cfg, $now)];
    }
    $next = $waiting ? min(array_column($waiting, 'due')) : null;
    $preview = null;
    if ($waiting) {
        $soon = array_values(array_filter($queue['items'], fn ($i) => $i['why'] !== 'alert_cap'));
        if ($soon) {
            [$subject, $desc, $message] = officeNotifySummaryText($soon, $lang);
            $preview = ['subject' => $subject, 'description' => $desc, 'message' => $message];
        }
    }
    // the flood guard now: who is being collected since when
    $collecting = [];
    foreach ($queue['items'] as $i) {
        if ($i['why'] === 'flood') {
            $collecting[$i['desk']] ??= ['since' => $i['time'], 'n' => 0, 'due' => $i['due']];
            $collecting[$i['desk']]['n']++;
        }
    }
    return [
        'settings'   => array_intersect_key($s, array_flip(['at', 'desks', 'quiet', 'silent_until', 'all_well'])),
        'desks'      => OFFICE_NOTIFY_DESKS,
        'sends'      => OFFICE_NOTIFY_SENDS,
        'success'    => array_keys(OFFICE_NOTIFY_SUCCESS),
        'available'  => is_executable(OFFICE_NOTIFY_BIN),
        'waiting'    => $waiting,
        'next'       => $next,
        'preview'    => $preview,
        'collecting' => $collecting,
        'log'        => array_reverse(officeNotifyLogRead()),
        'limits'     => ['desk' => NOTIFY_DESK_CAP, 'office' => NOTIFY_OFFICE_CAP, 'window' => NOTIFY_CAP_WINDOW, 'alerts' => NOTIFY_ALERT_CAP,
                         'bundle' => NOTIFY_ALERT_BUNDLE, 'forget' => NOTIFY_SAME_FORGET],
        'time'       => $now,
    ];
}

/** His tiles' lines (in his state, from the files only): the last that went, the last day's counts, how many wait, collecting since, silent until */
function officeNotifyBrief(): array
{
    $log = officeNotifyLogRead();
    $went = array_values(array_filter($log, fn ($e) => in_array($e['fate'] ?? '', ['sent', 'failed'], true)));
    $last = $went ? $went[count($went) - 1] : null;
    $queue = officeNotifyQueueRead(OFFICE_NOTIFY_HELD);
    $flood = array_filter($queue['items'], fn ($i) => $i['why'] === 'flood');
    $s = officeNotifySettings();
    $day = array_filter($log, fn ($e) => $e['time'] > time() - 86400);
    $count = fn (array $fates) => count(array_filter($day, fn ($e) => in_array($e['fate'] ?? '', $fates, true)));
    return [
        'last'         => $last ? ['time' => $last['time'], 'sent' => $last['fate'] === 'sent', 'subject' => (string) ($last['subject'] ?? '')] : null,
        'day'          => ['sent' => $count(['sent']), 'held' => $count(['held', 'collected', 'counted', 'dropped']), 'failed' => $count(['failed'])],
        'waiting'      => count($queue['items']),
        'collecting'   => $flood ? min(array_column($flood, 'time')) : null,
        'collected'    => count($flood),
        'silent_until' => $s['silent_until'] > time() ? $s['silent_until'] : 0,
        'summaries'    => count(array_filter($s['desks'], fn ($d) => $d['when'] === 'summary')),
    ];
}

/**
 * The settings from the page (caretaker.notify_save): every field in exactly its shape, desks only those who send —
 * a field missing or of another type is refused, never read as «off». Unknown keys already there stay.
 */
function officeNotifySave(array $r, ?string $file = null): array
{
    $file ??= OFFICE_NOTIFY_FILE;
    $hhmm = function (mixed $v): string {
        if (!is_string($v) || !officeNotifyHhmmOk($v)) {
            throw new Problem('bad_request');
        }
        return $v;
    };
    $desks = $r['desks'] ?? null;
    $quiet = $r['quiet'] ?? null;
    if (!is_array($desks) || !is_array($quiet)) {
        throw new Problem('bad_request');
    }
    $old = (!is_link($file) ? readJson($file) : null) ?? [];
    $new = officeNotifySettings($old);
    foreach ($desks as $id => $d) {
        if (!in_array($id, OFFICE_NOTIFY_DESKS, true) || !is_array($d) || !in_array($d['level'] ?? null, OFFICE_NOTIFY_LEVELS, true)
            || !is_bool($d['success'] ?? null) || !in_array($d['when'] ?? null, ['now', 'summary'], true)) {
            throw new Problem('bad_request');
        }
        $new['desks'][$id] = ['level' => $d['level'], 'success' => $d['success'], 'when' => $d['when'],
                              'at' => ($d['at'] ?? null) === null ? null : $hhmm($d['at'])] + $new['desks'][$id];
    }
    $new['at'] = $hhmm($r['at'] ?? null);
    $new['quiet'] = ['on' => boolField($quiet, 'on'), 'from' => $hhmm($quiet['from'] ?? null), 'to' => $hhmm($quiet['to'] ?? null)] + $new['quiet'];
    $new['all_well'] = boolField($r, 'all_well');
    officeNotifyWrite($new, $file);
    return $new;
}

/** «Still bis …» (caretaker.notify_silent): until an epoch ahead (at most two months), or 0 — talk again */
function officeNotifySilent(mixed $until, ?string $file = null, ?int $now = null): array
{
    $now ??= time();
    if (!is_int($until) || ($until !== 0 && ($until <= $now || $until > $now + NOTIFY_SILENT_MAX))) {
        throw new Problem('bad_request');
    }
    $file ??= OFFICE_NOTIFY_FILE;
    $s = officeNotifySettings((!is_link($file) ? readJson($file) : null) ?? []);
    $s['silent_until'] = $until;
    officeNotifyWrite($s, $file);
    return $s;
}

/**
 * The settings written (and at once into the senders' file); the old switches the centre took over get the same
 * choice for an older version of the office (caretaker/notify.json `on`, the watchman's `notify`)
 */
function officeNotifyWrite(array $s, string $file): void
{
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0755, true);
    }
    writeAtomic($file, jsonEncode($s));
    if ($file === OFFICE_NOTIFY_FILE) {
        officeNotifyCfgKeep(officeNotifySettings($s));
        foreach (['caretaker' => 'officeNotifyOldCaretaker', 'watchman' => 'officeNotifyOldWatchman'] as $desk => $fn) {
            try {
                $fn($s['desks'][$desk]['level'] !== 'alert');
            } catch (Throwable $e) {
                logLine("Notifications: the old switch of $desk could not be kept: " . $e->getMessage());
            }
        }
    }
}

function officeNotifyOldCaretaker(bool $on): void
{
    $f = DATA_DIR . '/caretaker/notify.json';
    $d = readJson($f) ?? [];
    if (($d['on'] ?? true) !== $on) {
        $d['on'] = $on;
        caretakerWrite($f, $d);
    }
}

function officeNotifyOldWatchman(bool $on): void
{
    if (function_exists('watchmanNotifySet') && watchmanHiredSince() !== null) {
        watchmanNotifySet($on, null, false);
    }
}

/** «Testmeldung senden» (caretaker.notify_test): one notification at that level past the settings — and where Unraid sends it */
function officeNotifyTest(mixed $level): array
{
    if (!in_array($level, ['normal', 'warning', 'alert'], true)) {
        throw new Problem('bad_request');
    }
    $lang = officeNotifyLang();
    $word = officeNotifyText('', "notifycenter.level.$level", [], $lang);
    $sent = officeNotify(officeNotifyText('', 'notifycenter.test.subject', ['level' => $word], $lang), officeNotifyText('', 'notifycenter.test', [], $lang),
        $level, '', officeNotifyLink('#/caretaker/notify'), 'caretaker', 'test', false);
    logLine("Notifications: a test ($level) " . ($sent ? 'went' : 'could not go') . " to Unraid's notifications");
    return ['ok' => true, 'sent' => $sent, 'channels' => officeNotifyChannels($level)];
}

/**
 * The migration (officeMigrateSteps(): notify-center, never on a fresh folder): the switches the centre takes over keep
 * what they said — the Team Lead's and the Night Watchman's off → «Nur Alarme», Mr. Backupsy's notify_success no →
 * no successes; everything on: nothing written (the defaults are exactly that). The old keys stay where they are.
 */
function officeMigrateNotifyCenter(string $dir): string
{
    $file = "$dir/office/notify.json";
    if (is_file($file)) {
        return 'nothing to change — the notification settings are there';
    }
    $s = officeNotifySettings([]);
    $said = [];
    if (((readJson("$dir/caretaker/notify.json") ?? [])['on'] ?? true) === false) {
        $s['desks']['caretaker']['level'] = 'alert';
        $said[] = 'the Team Lead\'s reports were off';
    }
    foreach (glob("$dir/watchman/state.json") ?: [] as $w) {
        if (((readJson($w) ?? [])['notify'] ?? true) === false) {
            $s['desks']['watchman']['level'] = 'alert';
            $said[] = 'the Night Watchman\'s reports were off';
        }
    }
    $ini = "$dir/unraid-backup/settings.ini";
    if (is_file($ini) && strtolower(trim((string) (readCfg($ini, true)['general']['notify_success'] ?? 'yes'), '"')) === 'no') {
        $s['desks']['backup']['success'] = false;
        $said[] = 'Mr. Backupsy\'s successes were off';
    }
    if (!$said) {
        return 'nothing to change — every notification switch was on: the defaults are today\'s behaviour';
    }
    @mkdir("$dir/office", 0755, true);
    writeAtomic($file, jsonEncode($s));
    return 'the notification settings written from the switches before (' . implode(', ', $said) . ')';
}
