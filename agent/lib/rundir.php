<?php
declare(strict_types=1);

/*
 * The office's RAM folder RUN_DIR (officeRunDir()) lies on Unraid's /run: a tmpfs of 128 MB that Docker,
 * libvirt and every other service share. 2026-11-19 a user's /run was full — the office's flash search had written every
 * /mnt mention of his flash into it, the agent died reading that back, and every restart under a new pid left one more
 * file of 50 MB and more; Docker could no longer update a container. It must never be the office that fills /run:
 *
 *   runDirEnsure()  at every start (agent and night shift), before anything is written there: the folder is a real folder
 *                   — or a link a user made to a folder of root's that no one else can write, outside /mnt and /boot (his
 *                   way out of a full /run: kept, said once); anything else (a link elsewhere, a file) is renamed aside —
 *                   rename() moves the link itself, never what it points to — and a real folder made.
 *   runDirSweep()   at the start and once a minute: what a process that is gone left there (RUNDIR_FILES with 'pid'), and
 *                   writeAtomic()'s temporary files older than RUNDIR_TMP_AGE. Only plain files and folders of our names,
 *                   found by lstat in the real folder (never followed: a link there is left as it is).
 *   runDirLook()    once a minute: the folder's size (lstat per entry, blocks really taken) and the free space of the
 *                   filesystem it lies on (statfs). Over RUNDIR_BUDGET, or that filesystem nearly full (RUNDIR_FS_FLOOR):
 *                   runDirRoom() says no — the office's optional writers wait (Ms. Dustdevil's background searches) —,
 *                   one line in the log when it starts and ends, the Team Lead's checks `run_dir` and `run_full`, and the
 *                   metrics uso_office_run_dir_bytes / uso_office_run_fs_free_bytes.
 *
 * RUNDIR_FILES is the list of everything the office writes there: what bounds it, who removes it. A new writer comes with
 * its line here (testRunDirAudit fails without it).
 */

const RUNDIR_BUDGET    = 8 << 20;      // the office's own small state there (without a report being made): normally a few hundred KB
const RUNDIR_FS_FLOOR  = 8 << 20;      // free space left on /run below which the office's optional writers wait
const RUNDIR_LOOK_EVERY = 60;          // seconds between looks (from the agent's minute)
const RUNDIR_TMP_AGE   = 600;          // a temporary file of writeAtomic() older than this was left by a process that died
const RUNDIR_WALK_MAX  = 5000;         // entries counted at most: more in the office's RAM folder is wrong by itself
const RUNDIR_TMP_NAME  = '/^\..+\.[0-9a-f]{12}\.tmp$/D';    // writeNewFile(): "<dir>/.<name>.<12 hex>.tmp"

/*
 * Everything the office writes into RUN_DIR (the audit of 2026-11-19), and the rule: **the RAM folder on /run is for small
 * state only** — pids, locks, heartbeats, status of a bounded size. Nothing whose size the server's content decides goes
 * there (that goes to officeTmpDir(), capped, or is read through a pipe). Each line: the path below RUN_DIR as a pattern (a
 * name, or «folder/name» for what one of its folders holds that the sweep looks after), 'max' the most it can hold in
 * bytes, 'bound' what keeps it there, 'gone' who removes it. 'max' over RUNDIR_SMALL only with 'large' (why it is there
 * all the same — testRunDirAudit names these, so no new one comes without a word). runDirSweep(): 'pid' (the first group is
 * the pid of the process that made it: removed once no process has it), 'age' (removed once older than that many seconds),
 * 'legacy' (written by an earlier version, by nobody now: removed whenever found). 'own' => false: what the user brings for a
 * report — not counted against the office's budget.
 */
const RUNDIR_SMALL = 1 << 20;
const RUNDIR_FILES = [
    // the agent, its supervisor and the night shift
    'agent\.pid|supervisor\.pid|nightshift-supervisor\.pid|nightshift\.lock|stopping|nightshift-stopping'
        => ['max' => 64, 'bound' => 'a pid or nothing', 'gone' => 'stays (locks) / agent.sh at a stop'],
    'agent\.json'                 => ['max' => 4096, 'bound' => 'the heartbeat: a few fields', 'gone' => 'rewritten every 20 s'],
    'doorbell'                    => ['max' => 0, 'bound' => 'a FIFO: holds nothing', 'gone' => 'doorbellClose()'],
    'nightshift'                  => ['max' => 1 << 20, 'bound' => 'his book and state: the mirror\'s caps (book 500, hosts 200, ips 500); nightshift.log 256 KB + .1', 'gone' => 'the handover at array start (WATCH_NIGHT_FILES); the rest is rewritten'],
    'watch-down|watch-told|array-events(?:\.tmp)?|job-[a-z-]+\.minute|backup-recover\.sh' => ['max' => 8192, 'bound' => 'a time, a line, 50 lines (array-events)', 'gone' => 'rewritten (agent.sh, job.sh)'],
    // schedules and jobs through atd
    'at-requeue\.sh|at-requeued\.json' => ['max' => 65536, 'bound' => 'one at job\'s script (≤ 64 KB read); the jobs queued anew', 'gone' => 'right after at; entries leave with their job'],
    '[a-z-]+\.sh'                 => ['max' => 16384, 'bound' => 'one job\'s script (hostLaunch(): a fixed set of names)', 'gone' => 'overwritten at its next launch'],
    // notifications (lib/house.php, lib/notifycenter.php, backup/lib/notifygate.sh)
    'notify\.second|notify\.lang|notify\.cfg|notify\.lock|notify-held\.json' => ['max' => 262144, 'bound' => 'a stamp, a language, the settings (≤ 16 KB), the held queue', 'gone' => 'rewritten'],
    'notify-guard'                => ['max' => 262144, 'bound' => 'NOTIFY_GUARD_MAX 3000 lines of ~45 B, the last 40 h kept at a rotation', 'gone' => 'rotated by its writers'],
    'notify-guard\.(\d+)\.tmp'     => ['max' => 262144, 'bound' => 'the guard', 'gone' => 'mv by the bash sender; runDirSweep() once it is gone', 'pid' => true],
    'notify-in'                   => ['max' => 500 * 9 * 1024, 'large' => 'the decisions waiting for the agent: NOTIFY_DROPS_MAX 500 (the writers stop there) × message and description 4 KB each; usually a few KB', 'bound' => 'NOTIFY_DROPS_MAX', 'gone' => 'officeNotifyTick() once a minute'],
    'notify-in/\.\d{16}-[0-9a-z]+\.tmp' => ['max' => 9216, 'bound' => 'one decision', 'gone' => 'mv by ung_drop; runDirSweep() after 10 min', 'age' => RUNDIR_TMP_AGE],
    // the web side's inbox, reports (src/api.php, lib/report.php)
    'inbox'                       => ['max' => 13 << 20, 'large' => 'the web side may write only into RUN_DIR (CLAUDE.md): secrets 8 × 4 KB, a report\'s pictures ≤ 6 × 2 MB for minutes — follow-up: the pictures elsewhere', 'bound' => 'API_IMAGES_WAITING × REPORT_IMG_IN_MAX', 'gone' => 'taken by the agent; pictures after 15 min', 'own' => false],
    'inbox/[0-9a-f]{32}\.image'   => ['max' => 2 << 20, 'large' => 'one picture of a report (see inbox)', 'bound' => 'REPORT_IMG_IN_MAX', 'gone' => 'reportImagesTake(); runDirSweep() after API_IMAGE_TTL', 'age' => 900, 'own' => false],
    'inbox/\.[0-9a-f]{16}\.tmp'   => ['max' => 4096, 'bound' => 'a home request', 'gone' => 'rename; runDirSweep() after 10 min', 'age' => RUNDIR_TMP_AGE],
    'report'                      => ['max' => 40 << 20, 'large' => 'a report being made: its pictures ≤ REPORT_IMG_RAM_MAX 16 MB, Unraid\'s diagnostics (their own size) and the clean copy ≤ 10 MB, for the minutes of a preview — follow-up: to officeTmpDir()', 'bound' => 'REPORT_IMG_RAM_MAX, REPORT_DIAG', 'gone' => 'the send, the close, reportTidy() once a minute after REPORT_TOKEN_TTL', 'own' => false],
    'report-inbox\.json|home\.json|caretaker\.rescan' => ['max' => 65536, 'bound' => 'a few fields; the welcome\'s list of shares', 'gone' => 'rewritten / taken'],
    'claim\.[0-9a-f]{12}\.curl'    => ['max' => 4096, 'bound' => 'curl\'s config with the supporter claim (a secret)', 'gone' => 'right after curl; runDirSweep() after 10 min', 'age' => RUNDIR_TMP_AGE],
    // locks and small state of the desks
    'look-[a-z0-9_.-]+\.lock|snapshot-plans\.lock|backup-wait\.lock|movers-start\.lock|jack-[a-z]+-wait\.(?:lock|json)|watchman-(?:round|book)-[0-9a-f]{8}\.lock'
        => ['max' => 4096, 'bound' => 'empty, or a pid', 'gone' => 'stay (locks); the wait record at its end'],
    'cleanup-tour\.due|logs-tour\.due' => ['max' => 64, 'bound' => 'a time', 'gone' => 'read and removed'],
    'backup-(?:silent|partner-rate|upload|rough|queued|abort)\.json|backup-queued\.cancel|moverelli-silent\.json'
        => ['max' => 262144, 'bound' => '≤ 80 samples, the looks of one run, one size per Kopia source', 'gone' => 'rewritten per run'],
    'watchman-mirror\.json|watchman-hold\.json|watchman-flow-[0-9a-f]{8}\.json' => ['max' => 1 << 20, 'bound' => 'the mirror\'s caps; ≤ 5 per thing; ≤ WATCH_FLOW_CONNS 5000 connections', 'gone' => 'rewritten'],
    'watchman-round\.err|watchman-hold\.err|logs-tour\.err' => ['max' => 65536, 'bound' => 'one run\'s stderr: a fatal error\'s lines (a child of the agent; warnings never reach stderr on Unraid\'s php.ini)', 'gone' => 'truncated at the next run'],
    'logs-flood\.json|logs-flood-catch\.json|logs-wakes\.json|logs-wake-names\.json' => ['max' => 1 << 20, 'bound' => '11 × 300 files; LOGS_WAKE_MAX 1000', 'gone' => 'rewritten / at its end'],
    'templates'                   => ['max' => 1 << 20, 'bound' => 'the catalog\'s templates the Consultant prepared (a few KB each)', 'gone' => 'rewritten'],
    'advisor-plugin(?:\.(?:json|out|done))?' => ['max' => 262144, 'bound' => 'one plugin install\'s output (Unraid\'s installer: its lines, read back to the last 32 KB)', 'gone' => 'at the next install'],
    // what 1.94 and earlier wrote there and nobody writes now: removed whenever found (a server in 1.94's crash loop comes out of it by itself)
    'cleanup-\d+-[0-9a-f]{32}\.out' => ['max' => 0, 'bound' => 'none in 1.94 (the cause of 2026-11-19); no longer written: her jobs read through a pipe', 'gone' => 'runDirSweep() at once', 'legacy' => true],
    'cleanup-icons-(?:check|\d+)'  => ['max' => 0, 'bound' => 'no longer written: the pictures are checked in officeTmpDir()', 'gone' => 'runDirSweep() at once', 'legacy' => true],
    'drill'                       => ['max' => 0, 'bound' => 'no longer written: the drill\'s dumps lie in officeTmpDir()', 'gone' => 'runDirSweep() at once', 'legacy' => true],
    // Jack Streamer (jack*.php, mediacache/)
    'jack-headers\.(\d+)'          => ['max' => 4096, 'bound' => 'one request\'s headers (his API key)', 'gone' => 'right after curl; runDirSweep() once its process is gone', 'pid' => true],
    'gather-[a-z]+\.ini\.[0-9a-f]{12}\.tmp' => ['max' => 16384, 'bound' => 'one gather\'s settings', 'gone' => 'after the run; runDirSweep() after 2 days', 'age' => 2 * 86400],
    'jack-import-trial\.[0-9a-f]{8}' => ['max' => 65536, 'bound' => 'one settings file', 'gone' => 'at once; runDirSweep() after 10 min', 'age' => RUNDIR_TMP_AGE],
    'jack-progress\.json|jack-progress-speed\.json|jack-watching\.json|jack-live-status\.(?:json|tmp)|jack-structure-run\.json'
        => ['max' => 1 << 20, 'bound' => 'the queue (JACK_RUN_QUEUE_MAX), ≤ 200 samples, ≤ 20 sessions', 'gone' => 'jackProgressClear() at a run\'s start and end'],
    'jack-progress\.json\.(\d+)\.tmp' => ['max' => 1 << 20, 'bound' => 'the progress', 'gone' => 'written by the Python run of 1.95.5 or earlier (os.replace); runDirSweep() once its process is gone', 'pid' => true],
    'jack-shadow'                 => ['max' => 0, 'bound' => 'no longer written: the comparison with Python is gone (1.95.6)', 'gone' => 'runDirSweep() at once', 'legacy' => true],
    // Mr. Restori (restore*.php)
    'restore-peek-[a-z0-9-]+|libvirt-restore-[0-9-]+' => ['max' => 0, 'bound' => 'a mount point (what it shows is a snapshot or an image on a pool: never walked)', 'gone' => 'after the look / the restore'],
    'restore-partner\.want|restore-partner-look\.pid|drill\.open|restore\.open|letgo\.open' => ['max' => 256, 'bound' => 'a line or nothing', 'gone' => 'at its end; agent.sh at an array stop'],
    // the partner door, the USO network, the fan desk
    'partner'                     => ['max' => 3 << 20, 'large' => 'the door\'s own log while the array is stopped (no data folder then): door.log 1 MB + .1; locks, records of a few fields', 'bound' => 'PARTNER_RECORD_MAX', 'gone' => 'at unpair; agent.sh at an array stop'],
    'partner/door-(\d+)\.json'     => ['max' => 4096, 'bound' => 'one record', 'gone' => 'the door at its end; runDirSweep() once it is gone', 'pid' => true],
    'usonet'                      => ['max' => 1 << 20, 'bound' => 'usonet.log and helper.log ≤ 256 KB each (usonet.sh trim_log every minute), status per tailnet peer', 'gone' => 'leave'],
    'fan[b]oy'                    => ['max' => 2 << 20, 'large' => 'the fan desk\'s history of every zone and each of its curves: 531 KB at the most its setup allows (8 zones, each with 8 kinds of heat source, 3 days — measured by its history test), and twice that for the moment writeAtomic() holds the new copy beside the old one, once a minute; a server with two zones holds under 100 KB', 'bound' => 'the fan desk\'s journal and state (its own audit; the desk keeps its name out of core files): its history at most 1460 points and 864 curve rows a zone, 8 zones', 'gone' => 'the fan desk'],
];

/*
 * Where a job that really needs a file of a size the server decides puts it — never the RAM folder on /run (small state
 * only): a private folder under /tmp (rootfs: RAM too, but not shared with Docker's runtime state), 0700, the office's own,
 * emptied at every agent start, never through a link (officeTmpEnsure()). Ms. Dustdevil's picture checks, Mr. Restori's
 * dumps from Kopia during a drill. Tests: OFFICE_TMP_DIR.
 */
function officeTmpDir(): string
{
    return rtrim(getenv('OFFICE_TMP_DIR') ?: '/tmp/unraid-secretary-office', '/');
}

/**
 * The private folder made sure: a real folder of ours (root) that no one else may read or write — anything else in its
 * place (a link, a file, someone else's folder) renamed aside, never followed. $clean: emptied (the agent's start: what an
 * agent before left) — but for $keep, the first-level names a job that outlives the agent still uses (officeTmpInUse()).
 * The folder, or null when it can't be had.
 *
 * @param list<string> $keep
 */
function officeTmpEnsure(bool $clean = false, ?string $dir = null, array $keep = []): ?string
{
    $dir ??= officeTmpDir();
    clearstatcache(true, $dir);
    $st = @lstat($dir);
    $mine = function_exists('posix_geteuid') ? posix_geteuid() : 0;
    if ($st !== false && (($st['mode'] & 0170000) !== 0040000 || (int) $st['uid'] !== $mine || ($st['mode'] & 0077))) {
        runDirAside($dir);
        $st = false;
    }
    if ($st === false) {
        $old = umask(0077);
        $made = @mkdir($dir, 0700);
        umask($old);
        $st = $made ? @lstat($dir) : false;
        if ($st === false || ($st['mode'] & 0170000) !== 0040000) {
            return null;
        }
    }
    if ($clean) {
        officeTmpEmpty($dir, 0, array_flip($keep));
    }
    return $dir;
}

/**
 * What in the private folder a job that outlives the agent still uses: Mr. Restori's drill (through atd) keeps the dump it
 * fetched from Kopia in drill/ until it is played — kept while his own rule says a drill is going on (drillRunning(): its
 * job file, its process alive); a drill that is gone leaves nothing kept
 *
 * @return list<string>
 */
function officeTmpInUse(): array
{
    return function_exists('drillRunning') && drillRunning() !== null ? ['drill'] : [];
}

/**
 * Everything in the private folder removed, depth first — links themselves only, never what they point to; at the top
 * not the names in $keep (name => true)
 *
 * @param array<string, int> $keep
 */
function officeTmpEmpty(string $dir, int $depth = 0, array $keep = []): void
{
    foreach (@scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..' || ($depth === 0 && isset($keep[$name]))) {
            continue;
        }
        $path = "$dir/$name";
        $st = @lstat($path);
        if ($st !== false && ($st['mode'] & 0170000) === 0040000 && $depth < 6) {
            officeTmpEmpty($path, $depth + 1);
            @rmdir($path);
        } elseif ($st !== false) {
            @unlink($path);
        }
    }
}

/** The folder's real place once runDirEnsure() looked (a link a user made: where it points), else RUN_DIR */
function runDirReal(): string
{
    return $GLOBALS['runDirReal'] ?? RUN_DIR;
}

/**
 * Makes sure RUN_DIR is a folder the office may write into (see the top). Returns what it found worth a line in the
 * log (null: a real folder, as always); the agent logs it once its data folder is there. $dir: the tests'.
 */
function runDirEnsure(?string $dir = null): ?string
{
    $dir ??= RUN_DIR;
    clearstatcache(true, $dir);
    $st = @lstat($dir);
    $GLOBALS['runDirReal'] = $dir;
    if ($st === false) {
        @mkdir($dir, 0700, true);
        return null;
    }
    $type = $st['mode'] & 0170000;
    if ($type === 0040000) {
        return null;
    }
    if ($type === 0120000) {
        $target = @realpath($dir);
        $why = runDirLinkWrong($target);
        if ($why === null) {
            $GLOBALS['runDirReal'] = (string) $target;
            return "its RAM folder $dir is a link to $target (a folder of root's): kept — the office writes there; "
                 . 'its files are small now, the link may go at the next reboot';
        }
        $aside = runDirAside($dir);
        @mkdir($dir, 0700, true);
        return "its RAM folder $dir was a link to " . ($target ?: (string) @readlink($dir)) . " ($why): "
             . ($aside !== null ? "the link itself is now $aside" : 'it could not be put aside') . ', a folder of its own made — nothing written through the link';
    }
    $aside = runDirAside($dir);
    @mkdir($dir, 0700, true);
    return "its RAM folder $dir was no folder: " . ($aside !== null ? "put aside as $aside" : 'it could not be put aside') . ', a folder of its own made';
}

/** Why a link's target can't be the office's RAM folder, or null: a real folder of ours (root), no one else may write, not under /mnt or /boot */
function runDirLinkWrong(string|false $target): ?string
{
    if ($target === false || $target === '') {
        return 'it points nowhere';
    }
    $st = @stat($target);
    if ($st === false || ($st['mode'] & 0170000) !== 0040000) {
        return 'not a folder';
    }
    if (under($target, '/mnt') || under($target, '/boot')) {
        return 'on a disk or the flash: the array could not stop';
    }
    if ((int) $st['uid'] !== (function_exists('posix_geteuid') ? posix_geteuid() : 0)) {
        return 'not root\'s';
    }
    if ($st['mode'] & 0022) {
        return 'others may write there';
    }
    return null;
}

/** The link or file itself renamed beside it (<dir>.aside-<time>): never deleted, never followed */
function runDirAside(string $dir): ?string
{
    for ($i = 0; $i < 10; $i++) {
        $aside = $dir . '.aside-' . date('Ymd-His') . ($i ? "-$i" : '');
        if (@lstat($aside) === false) {
            return @rename($dir, $aside) ? $aside : null;
        }
    }
    return null;
}

/** Is a process $pid running? (its folder in /proc — a pid used again keeps a leftover a while longer, never removes a live one) */
function runDirPidAlive(int $pid): bool
{
    return $pid > 0 && is_dir("/proc/$pid");
}

/** The RAM files this agent's own live work holds under a per-pid name (none since her jobs read through a pipe) */
function runDirLive(): array
{
    return [];
}

/**
 * Removes what processes that are gone left in the RAM folder (RUNDIR_FILES with 'pid' or 'age'; this agent's own only
 * when no live job of it holds the name — $live: the files of its live jobs; at the start none: a restart in place keeps
 * the pid) and writeAtomic()'s temporary files older than RUNDIR_TMP_AGE, in the folder and one folder down. Only plain
 * files, and folders of our names holding plain files; a link is never followed, a folder on another device (a mounted
 * snapshot or image) never entered. The paths removed (below the folder).
 *
 * @param list<string>|null $live
 * @return list<string>
 */
function runDirSweep(?array $live = null, ?string $dir = null, ?int $now = null): array
{
    $dir ??= runDirReal();
    $live = array_flip(array_map('basename', $live ?? runDirLive()));
    $now ??= time();
    $root = @lstat($dir);
    if ($root === false || ($root['mode'] & 0170000) !== 0040000) {
        return [];
    }
    $me = getmypid();
    $lists = ['' => @scandir($dir) ?: []];
    foreach ($lists[''] as $name) {
        $st = $name[0] === '.' ? false : @lstat("$dir/$name");
        if ($st && ($st['mode'] & 0170000) === 0040000 && $st['dev'] === $root['dev']) {
            $lists[$name] = @scandir("$dir/$name") ?: [];
        }
    }
    $gone = [];
    foreach ($lists as $sub => $names) {
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $rel = $sub === '' ? $name : "$sub/$name";
            $st = @lstat("$dir/$rel");
            if ($st === false || $st['dev'] !== $root['dev']) {
                continue;
            }
            $type = $st['mode'] & 0170000;
            if ($type === 0100000 && preg_match(RUNDIR_TMP_NAME, $name) && $now - (int) $st['mtime'] > RUNDIR_TMP_AGE) {
                if (@unlink("$dir/$rel")) {
                    $gone[] = $rel;
                }
                continue;
            }
            foreach (RUNDIR_FILES as $pattern => $what) {
                if (empty($what['pid']) && empty($what['age']) && empty($what['legacy'])) {
                    continue;
                }
                if (!preg_match('#^(?:' . $pattern . ')$#D', $rel, $m)) {
                    continue;
                }
                $left = match (true) {
                    !empty($what['legacy']) => true,
                    !empty($what['pid'])    => (int) $m[1] === $me ? !isset($live[$name]) : !runDirPidAlive((int) $m[1]),
                    default                 => $now - (int) $st['mtime'] > (int) $what['age'],
                };
                if ($left && runDirRemove("$dir/$rel", $type)) {
                    $gone[] = $rel;
                }
                break;
            }
        }
    }
    return $gone;
}

/** A plain file, or a real folder with only plain files in it, removed — anything else (a link, a deeper folder) left */
function runDirRemove(string $path, int $type): bool
{
    if ($type === 0100000) {
        return @unlink($path);
    }
    if ($type !== 0040000) {
        return false;
    }
    foreach (@scandir($path) ?: [] as $inner) {
        if ($inner === '.' || $inner === '..') {
            continue;
        }
        $st = @lstat("$path/$inner");
        if ($st === false || ($st['mode'] & 0170000) !== 0100000 || !@unlink("$path/$inner")) {
            return false;
        }
    }
    return @rmdir($path);
}

/**
 * The folder's size (blocks really taken, every entry by lstat, at most RUNDIR_WALK_MAX; never into a folder on another
 * device — a mounted snapshot is not the office's RAM and is never walked): [bytes, entries, biggest first-level names
 * => bytes (3), bytes of the office's own (without what the user brings for a report: 'own' => false)]
 */
function runDirSize(string $dir): array
{
    $root = @lstat($dir);
    if ($root === false) {
        return [0, 0, [], 0];
    }
    $bytes = 0;
    $count = 0;
    $top = [];
    $walk = function (string $path, string $first, int $depth) use (&$walk, &$bytes, &$count, &$top, $root): void {
        foreach (@scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $count >= RUNDIR_WALK_MAX) {
                continue;
            }
            $st = @lstat("$path/$name");
            if ($st === false || $st['dev'] !== $root['dev']) {
                continue;
            }
            $count++;
            $b = (int) $st['blocks'] * 512;
            $key = $first === '' ? $name : $first;
            $bytes += $b;
            $top[$key] = ($top[$key] ?? 0) + $b;
            if (($st['mode'] & 0170000) === 0040000 && $depth < 4) {
                $walk("$path/$name", $key, $depth + 1);
            }
        }
    };
    $walk($dir, '', 0);
    arsort($top);
    $theirs = 0;
    foreach ($top as $name => $b) {
        foreach (RUNDIR_FILES as $pattern => $what) {
            if (($what['own'] ?? true) === false && !str_contains($pattern, '/') && preg_match('#^(?:' . $pattern . ')$#D', (string) $name)) {
                $theirs += $b;
                break;
            }
        }
    }
    return [$bytes, $count, array_slice($top, 0, 3, true), $bytes - $theirs];
}

/**
 * Once a minute (or $force): what gone processes left removed (runDirSweep()), the folder's size and the free space
 * where it lies — remembered for runDirRoom(), the checks and the metrics; over the budget or with /run nearly full it
 * says so once (and again when that is over).
 */
function runDirLook(bool $force = false, ?int $now = null, ?callable $statfs = null): array
{
    $now ??= time();
    $last = $GLOBALS['runDir'] ?? null;
    if (!$force && $last && $now - $last['at'] < RUNDIR_LOOK_EVERY) {
        return $last;
    }
    $dir = runDirReal();
    $swept = runDirSweep(null, $dir, $now);
    if ($swept) {
        logLine('Office: removed from the RAM folder what was left: ' . implode(', ', array_slice($swept, 0, 10)) . (count($swept) > 10 ? ' …' : ''));
    }
    if (function_exists('reportTidy') && is_dir("$dir/report") && !is_link("$dir/report")) {
        reportTidy("$dir/report", $now, ['run_dir' => "$dir/report"]);      // a report's pictures and diagnostics nobody sends any more
    }
    [$bytes, $count, $top, $own] = runDirSize($dir);
    [$free, $total] = $statfs ? $statfs($dir) : [@disk_free_space($dir), @disk_total_space($dir)];      // statfs (tests: their own)
    $look = [
        'at' => $now, 'dir' => $dir, 'bytes' => $bytes, 'own' => $own, 'entries' => $count, 'top' => $top,
        'free' => $free === false ? null : (int) $free, 'total' => $total === false ? null : (int) $total,
    ];
    $look['over'] = $own > RUNDIR_BUDGET || $count >= RUNDIR_WALK_MAX;
    $look['tight'] = $look['free'] !== null && $look['free'] < RUNDIR_FS_FLOOR;
    foreach (['over' => fn () => sprintf('Office: its RAM folder %s holds %.1f MB (budget %d MB) — its optional work there waits; biggest: %s',
                                         $dir, $own / 1048576, RUNDIR_BUDGET >> 20, runDirTopText($top)),
              'tight' => fn () => sprintf('Office: only %.1f MB free where its RAM folder lies (%s, of %.0f MB; the office holds %.1f MB) — its optional work there waits',
                                          $look['free'] / 1048576, $dir, (int) $look['total'] / 1048576, $bytes / 1048576)] as $what => $say) {
        if ($look[$what] && empty($last[$what])) {
            logLine($say());
        } elseif (!$look[$what] && !empty($last[$what])) {
            logLine($what === 'over' ? 'Office: its RAM folder is small again (' . sprintf('%.1f MB', $own / 1048576) . ')' : 'Office: there is room again where its RAM folder lies');
        }
    }
    return $GLOBALS['runDir'] = $look;
}

function runDirTopText(array $top): string
{
    return implode(', ', array_map(fn ($n, $b) => sprintf('%s %.1f MB', $n, $b / 1048576), array_keys($top), $top)) ?: '–';
}

/** May the office's optional writers put something into the RAM folder now? (the last look; one is made when there is none) */
function runDirRoom(): bool
{
    $look = $GLOBALS['runDir'] ?? runDirLook(true);
    return !$look['over'] && !$look['tight'];
}

/** Is RUN_DIR (or the folder a user's link points to, runDirEnsure()) a real folder to write into? (an lstat, no more) */
function runDirOk(): bool
{
    $real = runDirReal();
    clearstatcache(true, $real);
    $st = @lstat($real);
    if ($st === false) {
        @mkdir($real, 0700, true);
        $st = @lstat($real);
    }
    return $st !== false && ($st['mode'] & 0170000) === 0040000;
}

/** The Team Lead's two checks: the office's RAM folder small, and room left where it lies (never asleep: RAM) */
function runDirChecks(): array
{
    $look = $GLOBALS['runDir'] ?? runDirLook(true);
    $mb = fn (int $b): int => (int) ceil($b / 1048576);
    return [
        finding('run_dir', 'recommended', !$look['over'], ['size' => $mb($look['own'] ?? $look['bytes']), 'budget' => RUNDIR_BUDGET >> 20,
                'path' => $look['dir'], 'names' => runDirTopText($look['top'])]),
        finding('run_full', 'recommended', $look['free'] === null ? null : !$look['tight'], ['free' => intdiv((int) $look['free'], 1048576),
                'total' => intdiv((int) $look['total'], 1048576), 'office' => $mb($look['bytes']), 'path' => $look['dir']]),
    ];
}

/** Its numbers for Prometheus (metricsOffice()): the last look's */
function runDirMetrics(): array
{
    $look = $GLOBALS['runDir'] ?? null;
    if (!$look) {
        return [];
    }
    $out = [metricsGauge('uso_office_run_dir_bytes', 'What the office keeps in its RAM folder (on /run), in bytes', $look['bytes'])];
    if ($look['free'] !== null) {
        $out[] = metricsGauge('uso_office_run_fs_free_bytes', 'Free space where the office\'s RAM folder lies (/run: shared with Docker), in bytes', $look['free']);
    }
    return $out;
}

/**
 * A name in the RAM folder as a report may say it: a fixed name of RUNDIR_FILES as it is (agent.json), one with a part the
 * server or a process decides by its pattern (cleanup-*.out, jack-headers.*), anything else «other» — never a name the
 * office didn't choose
 */
function runDirLabel(string $name): string
{
    foreach (RUNDIR_FILES as $pattern => $what) {
        if (str_contains($pattern, '/') || !preg_match('#^(?:' . $pattern . ')$#D', $name)) {
            continue;
        }
        if (preg_match('/^[A-Za-z0-9_.-]+$/D', str_replace('\.', '.', $pattern))) {
            return $name;                                   // a plain name
        }
        foreach (explode('|', $pattern) as $alt) {
            if (preg_match('#^(?:' . $alt . ')$#D', $name)) {
                $glob = (string) preg_replace(['/\(\?:[^)]*\)\??/', '/\(\\\\d\+\)|\\\\d\+|\\\\d\{\d+\}/', '/\[[^\]]*\](?:\{\d+(?:,\d*)?\}|\+|\*)?/'], '*', $alt);
                return preg_match('/^[A-Za-z0-9_.*-]+$/D', $g = str_replace('\.', '.', $glob)) ? (string) preg_replace('/\*+/', '*', $g) : 'other';
            }
        }
    }
    return 'other';
}

/**
 * The numbers of this fault class for a report's diagnostics (2026-11-20): the RAM folder's size, the room on /run, its
 * largest entries by pattern, how often the agent started in the last hour (its log). Cheap: one walk (≤ RUNDIR_WALK_MAX),
 * statfs, the log's last 256 KB.
 */
function runDirReportText(?int $now = null, ?string $log = null): string
{
    $now ??= time();
    $dir = runDirReal();
    $root = @lstat($dir);
    $groups = [];
    foreach ($root ? (@scandir($dir) ?: []) : [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $st = @lstat("$dir/$name");
        if ($st === false) {
            continue;
        }
        $b = (int) $st['blocks'] * 512;
        if (($st['mode'] & 0170000) === 0040000 && $st['dev'] === $root['dev']) {
            $b = runDirSize("$dir/$name")[0] + $b;
        }
        $label = runDirLabel($name);
        $groups[$label] = [($groups[$label][0] ?? 0) + 1, ($groups[$label][1] ?? 0) + $b];
    }
    uasort($groups, fn ($a, $b) => $b[1] <=> $a[1]);
    [$bytes, $count] = runDirSize($dir);
    $free = @disk_free_space($dir);
    $total = @disk_total_space($dir);
    $starts = 0;
    $log ??= AGENT_LOG;
    $tail = (string) @file_get_contents($log, false, null, max(0, (int) @filesize($log) - (256 << 10)));
    foreach (explode("\n", $tail) as $line) {
        if (str_contains($line, '  Agent started (v') && ($t = strtotime(substr($line, 0, 19))) !== false && $t >= $now - 3600) {
            $starts++;
        }
    }
    $out = sprintf("The office's RAM folder: %.1f MB in %d entries%s\n", $bytes / 1048576, $count, $dir !== RUN_DIR ? ' (a link to a folder elsewhere)' : '');
    $out .= $free !== false && $total !== false ? sprintf("Room where it lies: %.1f MB free of %.0f MB\n", $free / 1048576, $total / 1048576) : "Room where it lies: not known\n";
    foreach (array_slice($groups, 0, 6, true) as $label => [$n, $b]) {
        $out .= sprintf("  %s: %d %s, %.1f MB\n", $label, $n, $n === 1 ? 'entry' : 'entries', $b / 1048576);
    }
    return $out . sprintf("Agent starts in the last hour: %d\n", $starts);
}

