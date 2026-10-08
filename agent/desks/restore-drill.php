<?php
declare(strict_types=1);

/*
 * Mr. Restori's drill (stage 1 of the restore drill concept, 2026-10): now and then — monthly after the first
 * nightly backup run that went well, or when asked («Practise now…») — he proves that what Mr. Backupsy keeps
 * really comes back, on throwaway objects only, and writes a certificate per app and VM: which level is proven
 * (L0 there · L1 readable and complete · L2 restorable), from which copy (the package now, the local snapshot of
 * a run, Kopia), when, and what didn't work and why.
 *
 *   packages   L1  every app, VM, server/ and flash/: each file of the manifest there with its size, .gz intact,
 *                  dumps with their closing line, XML readable, NVRAM and TPM there, the archives listed — and
 *                  how old the newest good dump really is
 *   dumps      L2  Postgres, MariaDB, MongoDB from the local snapshot of the package's run, played into a
 *                  throwaway container of the same image id (--network none, data on tmpfs, its own random or
 *                  trust login, limits) with Mr. Restori's own play and check (rsDoReady/rsDoPlay/rsDoVerify)
 *   SQLite     L1  a media server's copies from the local snapshot: integrity_check, user_version and the main
 *                  table against the live database (read only, switchable)
 *   VM disks   L1  from the run's snapshot: the file there, a qcow2 chain complete inside the snapshot (the header
 *                  read here, never qemu-img following paths into live files), a raw disk not all zero
 *   Kopia      L0  the newest snapshot per source (age, complete, failed files), its structure (snapshot verify)
 *              L1  a sample streamed back through `kopia show` (a fresh cache: from the bucket, not the local cache)
 *                  hashed here and compared with the same file in the local snapshot it was read from
 *
 * Never: a port, a network, a bind of anything of the app, a Docker volume, the app's labels or passwords, a pull,
 * --privileged, devices; never a write to the app, its folders or Kopia's repository; never a sleeping disk woken
 * ("asleep" is no failure). Every docker run argument list comes from drillContainerArgs() (the tests assert the
 * «never» list against nostromo-shaped manifests).
 *
 * The job: atd (`php agent.php job restore-drill <id>`), the engine's lock as {holder: restore, mode: drill},
 * a journal like his restores (data/restore-drill/<id>/: plan.json, journal.json, log.txt, root only) with a
 * list "made" — each throwaway written there before it is created — and a sweeper (drillSweep) at its start and
 * end, at every agent start and hourly in the tick. It ends itself ≥ 15 min before the next scheduled backup, at
 * once when the array stops (rsBeat looks every 2 s; agent.sh's drill_release on top) and on SIGTERM.
 * The certificate: data/restore-drill.json (interface 1) — Mr. Backupsy's line, the Dashboard's row, the Team
 * Lead's checks and the metrics read only this file. data/restore-drill/record.json lists what the drill created
 * for the night watchman (his adoption follows in stage 2).
 *
 * Functions only — loaded by restore.php (and by the agent's desk glob, which comes first: nothing at the top
 * level may use restore.php's constants).
 */

const DRILL_ID_PATTERN     = '/^\d{8}-\d{6}-[0-9a-f]{4}$/D';          // like a restore's: <time>-<random>
const DRILL_PREFIX         = 'uso-drill-';                            // every throwaway: uso-drill-<id>-<n>
const DRILL_CONTAINER      = '/^uso-drill-(\d{8}-\d{6}-[0-9a-f]{4})-(\d{1,3})$/D';
const DRILL_LABEL          = 'uso.drill';                             // = <id>
const DRILL_LABEL_BY       = 'uso.installed-by';
const DRILL_BY             = 'restori-drill';
const DRILL_SCHEDULES      = ['monthly', 'weekly', 'off'];
const DRILL_RESULTS        = ['ok', 'warning', 'failed', 'not_checked', 'asleep'];
const DRILL_KEEP           = 12;              // drills in the certificate's history and on his page
const DRILL_RECORD_KEEP    = 50;              // record.json: the newest 50 …
const DRILL_RECORD_DAYS    = 7;               // … within 7 days
const DRILL_DEADLINE_GAP   = 900;             // ends ≥ 15 min before the next scheduled backup run
const DRILL_BUDGET_TOTAL   = 5400;            // a whole drill: 90 min at most
const DRILL_BUDGET_PLAY    = 1800;            // one dump played
const DRILL_BUDGET_READY   = 180;             // a throwaway answering
const DRILL_BUDGET_SQLITE  = 600;             // one integrity_check
const DRILL_BUDGET_KOPIA   = 1800;            // all Kopia steps together
const DRILL_KOPIA_MB       = 1024;            // what Kopia may download per drill (setting)
const DRILL_KOPIA_SAMPLES  = 3;               // files sampled per share source
const DRILL_KOPIA_LOOKS    = 40;              // directories looked into per share source while sampling
const DRILL_RAM_SHARE      = 0.25;            // of MemAvailable …
const DRILL_RAM_MAX        = 8 << 30;         // … at most 8 GB
const DRILL_SERVER_RAM     = 512 << 20;       // what a database server takes besides its data
// the data dir's tmpfs: the dump's uncompressed size × 4 plus 512 MB. Measured on Tower (2026-10-08) with the lean
// server (drillServerArgs) — tables and indexes took 1.3–2.7× the uncompressed dump (Immich's trigram indexes the
// most), the fixed part (a fresh cluster, Postgres' WAL up to max_wal_size, InnoDB's redo, undo and its 60 MB
// extents) up to ≈ 300 MB: Immich-shaped 302 MiB → 647 MB, Nextcloud-shaped 162 MiB → 515 MB
const DRILL_DUMP_FACTOR    = 4;
const DRILL_DUMP_FLOOR     = 512 << 20;
// the throwaway server's own options after the image's command (never the live one's): nothing kept for a crash,
// Postgres' WAL recycled at 256 MB instead of piling up (Immich's config allows 5 GB), InnoDB's redo small, no binlog,
// MariaDB's room for the dump's biggest rows. drillArgsSafe() lets exactly these through and nothing else.
const DRILL_LEAN = [
    'postgres' => ['fsync=off', 'synchronous_commit=off', 'full_page_writes=off', 'wal_level=minimal', 'max_wal_senders=0', 'archive_mode=off',
                   'max_wal_size=256MB', 'min_wal_size=64MB', 'checkpoint_timeout=30s', 'shared_buffers=128MB'],
    'mariadb'  => ['--max-allowed-packet=1G', '--innodb-log-file-size=64M', '--innodb-flush-log-at-trx-commit=0', '--skip-log-bin', '--innodb-doublewrite=0'],
];
const DRILL_WINDOW         = [0, 7];          // the automatic drill starts between 00:00 and 07:00
const DRILL_AFTER_RUN      = 6 * 3600;        // … within 6 h after a nightly run ended ok or with warnings
const DRILL_OVERDUE_DAYS   = 60;              // the Team Lead: no passed drill for 60 days …
const DRILL_CHECK_DAYS     = 30;              // … once packages exist for 30 days
const DRILL_AUTO_DAYS      = 7;               // the automatic drill once packages exist for 7 days
const DRILL_SWEEP_EVERY    = 3600;
const DRILL_TICK_EVERY     = 60;
const DRILL_LOG_LINE       = 200;             // a client's line in the log: cut (it may quote row data)
// what a throwaway takes from the app's container environment: only what shapes the server, never a login
const DRILL_ENV_KEEP       = ['PGDATA', 'POSTGRES_INITDB_ARGS', 'DB_STORAGE_TYPE', 'LANG', 'LC_ALL'];
// the main table of a media server's library (rows against the live database)
const DRILL_SQLITE_TABLES  = ['MediaItems', 'TypedBaseItems', 'BaseItems', 'metadata_items'];
const DRILL_STEP_KINDS     = ['package', 'dump', 'sqlite', 'vmdisk', 'kopia'];

define('DRILL_DATA', DATA_DIR . '/restore-drill');
define('DRILL_CERT', DATA_DIR . '/restore-drill.json');           // the certificate (api part "drill")
define('DRILL_JOB', DATA_DIR . '/restore-drill-job.json');        // the drill going on, polled by the page (api part "drill-job")

$GLOBALS['drill'] ??= [];

// ===================================================================== places (tests point them elsewhere)

function drillData(): string
{
    return $GLOBALS['drill']['data'] ?? DRILL_DATA;
}

function drillCertFile(): string
{
    return $GLOBALS['drill']['cert'] ?? DRILL_CERT;
}

function drillJobFile(): string
{
    return $GLOBALS['drill']['job_file'] ?? DRILL_JOB;
}

function drillDir(string $id): string
{
    if (!preg_match(DRILL_ID_PATTERN, $id)) {
        throw new Problem('unknown_target', ['target' => $id]);
    }
    return drillData() . "/$id";
}

/** The marker in RAM agent.sh's drill_release looks at during the array stop: "<pid> <id>" ($kind drill or restore) */
function drillMarkerFile(string $kind = 'drill'): string
{
    return ($GLOBALS['drill']['run_dir'] ?? RUN_DIR) . "/$kind.open";
}

function drillMarker(string $kind, ?string $id): void
{
    $file = drillMarkerFile($kind);
    if ($id === null) {
        @unlink($file);
        return;
    }
    if (is_dir(dirname($file))) {
        try {
            writeAtomic($file, getmypid() . " $id\n", 0600, 0, 0);
        } catch (Throwable) {
        }
    }
}

/** docker, or the tests' stand-in */
function drillCmd(array $cmd): array
{
    if (($cmd[0] ?? '') === 'docker' && isset($GLOBALS['drill']['docker'])) {
        $cmd[0] = $GLOBALS['drill']['docker'];
    }
    return $cmd;
}

function drillNow(): int
{
    return $GLOBALS['drill']['now'] ?? time();
}

// ===================================================================== settings

/** His drill settings (Benj may change the defaults — the coordinator's decisions 1, 3 and 4) */
function drillSettings(): array
{
    $def = ['schedule' => 'monthly', 'kopia_mb' => DRILL_KOPIA_MB, 'live_catalog' => true, 'live_sqlite' => true];
    $f = readJson(drillData() . '/settings.json') ?? [];
    return [
        'schedule'     => in_array($f['schedule'] ?? null, DRILL_SCHEDULES, true) ? $f['schedule'] : $def['schedule'],
        'kopia_mb'     => is_int($f['kopia_mb'] ?? null) && $f['kopia_mb'] >= 0 && $f['kopia_mb'] <= 102400 ? $f['kopia_mb'] : $def['kopia_mb'],
        'live_catalog' => is_bool($f['live_catalog'] ?? null) ? $f['live_catalog'] : $def['live_catalog'],
        'live_sqlite'  => is_bool($f['live_sqlite'] ?? null) ? $f['live_sqlite'] : $def['live_sqlite'],
    ];
}

/** «Drill» settings from his page: each field only when sent */
function drillSet(array $r): array
{
    if (!array_intersect_key($r, array_flip(['schedule', 'kopia_mb', 'live_catalog', 'live_sqlite']))) {
        throw new Problem('bad_request');      // nothing it knows: refused, never an «ok» that changed nothing (QA 2026-10-08)
    }
    $s = drillSettings();
    if (array_key_exists('schedule', $r)) {
        if (!in_array($r['schedule'], DRILL_SCHEDULES, true)) {
            throw new Problem('unknown_target', ['target' => (string) (is_scalar($r['schedule']) ? $r['schedule'] : '?')]);
        }
        $s['schedule'] = $r['schedule'];
    }
    if (array_key_exists('kopia_mb', $r)) {
        $mb = $r['kopia_mb'];
        if (!is_int($mb) || $mb < 0 || $mb > 102400) {
            throw new Problem('unknown_target', ['target' => 'kopia_mb']);
        }
        $s['kopia_mb'] = $mb;
    }
    foreach (['live_catalog', 'live_sqlite'] as $k) {
        if (array_key_exists($k, $r)) {
            if (!is_bool($r[$k])) {
                throw new Problem('unknown_target', ['target' => $k]);
            }
            $s[$k] = $r[$k];
        }
    }
    rsPrivateDir(drillData());
    writeAtomic(drillData() . '/settings.json', jsonEncode($s), 0600, 0, 0);
    return drillState([]);
}

// ===================================================================== the server, cheaply

function drillVarIni(): array
{
    return readCfg($GLOBALS['drill']['var_ini'] ?? '/var/local/emhttp/var.ini');
}

/**
 * A parity check or rebuild RUNNING (var.ini): `mdResyncPos` > 0 and not paused. Paused (Benj, 2026-10-08 — a paused
 * sync blocked the drill for days) = `mdResync` 0 and `mdResyncDt` 0 while the position stays (Unraid keeps mdResyncPos;
 * lib/where.php waBuilding(): «mdResyncDt 0 = paused») — the drill's reads don't race a sync that waits. Either key
 * missing: running, as before (never a guess towards «go»). Done: mdResyncPos 0.
 */
function drillParity(?array $var = null): bool
{
    $var ??= drillVarIni();
    if ((int) ($var['mdResyncPos'] ?? 0) <= 0) {
        return false;
    }
    $paused = isset($var['mdResync'], $var['mdResyncDt']) && (int) $var['mdResync'] === 0 && (int) $var['mdResyncDt'] === 0;
    return !$paused;
}

/** RAM the drill may use: 25 % of MemAvailable, at most 8 GB */
function drillRamBudget(?string $meminfo = null): int
{
    if (isset($GLOBALS['drill']['ram'])) {
        return (int) $GLOBALS['drill']['ram'];
    }
    $text = $meminfo ?? (string) @file_get_contents('/proc/meminfo');
    $avail = preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $text, $m) ? (int) $m[1] * 1024 : 0;
    return (int) min(DRILL_RAM_MAX, floor($avail * DRILL_RAM_SHARE));
}

/** The next scheduled backup run (the plugin's cron file), or null */
function drillNextBackup(?int $now = null): ?int
{
    if (array_key_exists('next_backup', $GLOBALS['drill'])) {
        return $GLOBALS['drill']['next_backup'];
    }
    $cron = officeJobSchedule('backup')['custom'] ?? null;
    return is_string($cron) && $cron !== '' ? cronNext($cron, $now ?? drillNow()) : null;
}

/**
 * When a drill starting now must end: its whole budget, and ≥ 15 min before the next scheduled backup (the
 * backup comes first: a run meeting the drill's lock would be skipped)
 */
function drillDeadline(int $now, ?int $nextBackup): int
{
    $end = $now + DRILL_BUDGET_TOTAL;
    return $nextBackup !== null ? min($end, $nextBackup - DRILL_DEADLINE_GAP) : $end;
}

/** Since when packages exist: the engine's oldest history line (cheap: its first line) */
function drillPackagesSince(): ?int
{
    $h = @fopen(rsUbData() . '/state/history.jsonl', 'r');
    if (!$h) {
        return null;
    }
    $since = null;
    for ($i = 0; $i < 50 && ($line = fgets($h, 1 << 20)) !== false; $i++) {
        $j = json_decode($line, true);
        if (is_array($j) && is_int($j['started'] ?? null) && $j['started'] > 0) {
            $since = $j['started'];
            break;
        }
    }
    fclose($h);
    return $since;
}

// ===================================================================== the throwaway: one gate

/**
 * A container of the manifest in exactly the shape the engine writes it (docker inspect, trusted only where it is
 * what we expect): image id, entrypoint, command, user, the volumes of the image, the shaping environment.
 */
function drillContainerOf(array $manifest, string $name): ?array
{
    foreach ((array) ($manifest['containers'] ?? []) as $c) {
        if (!is_array($c) || ($c['name'] ?? null) !== $name) {
            continue;
        }
        $cfg = is_array($c['inspect']['Config'] ?? null) ? $c['inspect']['Config'] : [];
        $list = fn (mixed $v): array => is_array($v) && array_is_list($v) && !array_filter($v, fn ($x) => !is_string($x)) ? $v : [];
        $env = [];
        foreach ($list($cfg['Env'] ?? null) as $e) {
            [$k, $v] = array_pad(explode('=', $e, 2), 2, '');
            if (in_array($k, DRILL_ENV_KEEP, true) && !preg_match('/[\x00-\x1f]/', $v)) {
                $env[$k] = $v;
            }
        }
        $vols = [];
        foreach (is_array($cfg['Volumes'] ?? null) ? array_keys($cfg['Volumes']) : [] as $v) {
            if (is_string($v) && preg_match('#^/[A-Za-z0-9_./-]{1,200}$#D', $v) && !str_contains("/$v/", '/../')) {
                $vols[] = rtrim($v, '/');
            }
        }
        $id = is_string($c['image_id'] ?? null) ? strtolower($c['image_id']) : '';
        $shm = $c['inspect']['HostConfig']['ShmSize'] ?? null;
        return [
            'name'       => $name,
            'image'      => is_string($c['image'] ?? null) ? $c['image'] : '',
            'image_id'   => preg_match('/^sha256:[0-9a-f]{64}$/D', $id) ? $id : '',
            'entrypoint' => $list($cfg['Entrypoint'] ?? null),
            'cmd'        => $list($cfg['Cmd'] ?? null),
            'user'       => is_string($cfg['User'] ?? null) && preg_match('/^\d{1,10}(:\d{1,10})?$/D', $cfg['User']) ? $cfg['User'] : '',
            'env'        => $env,
            'volumes'    => array_values(array_unique($vols)),
            'shm'        => is_int($shm) && $shm > 0 ? min($shm, 1 << 30) : 0,
        ];
    }
    return null;
}

/** Where a database image keeps its data (each gets a tmpfs; so does every volume the image declares) */
function drillDataDir(string $type, array $c): string
{
    return match ($type) {
        'postgres' => rtrim($c['env']['PGDATA'] ?? '', '/') ?: '/var/lib/postgresql/data',
        'mariadb'  => '/var/lib/mysql',
        'mongodb'  => '/data/db',
        default    => '/data',
    };
}

/**
 * Every argument of a throwaway's `docker run` — the one gate. In: the app's container as drillContainerOf() read
 * it, its database type, the throwaway's name (uso-drill-<id>-<n>) and id, its memory (bytes), its own password.
 * Out: never a port, a network but none, a bind, a volume, a device, a capability, --privileged, an app label or
 * an app login; always the label uso.drill=<id>, limits, no restart, small logs, no new privileges, the image by id.
 */
function drillContainerArgs(array $c, string $type, string $name, string $id, int $memory, string $password): array
{
    if (!preg_match(DRILL_CONTAINER, $name, $m) || $m[1] !== $id || $c['image_id'] === '' || !in_array($type, ['postgres', 'mariadb', 'mongodb'], true)) {
        throw new Problem('command_failed', ['detail' => 'throwaway']);
    }
    $mb = max(256, intdiv($memory, 1 << 20));
    $tmpfs = max(128, $mb - intdiv(DRILL_SERVER_RAM, 1 << 20));
    [$uid, $gid] = $c['user'] !== '' ? array_map('intval', array_pad(explode(':', $c['user']), 2, explode(':', $c['user'])[0])) : [null, null];
    $owner = $uid !== null ? ",uid=$uid,gid=$gid" : '';
    $args = ['docker', 'run', '-d', '--name', $name,
        '--label', DRILL_LABEL . "=$id", '--label', DRILL_LABEL_BY . '=' . DRILL_BY,
        '--label', 'net.unraid.docker.icon=file://' . OFFICE_DIR . '/images/' . OFFICE_PLUGIN . '.png',
        '--network', 'none', '--restart', 'no',
        '--memory', "{$mb}m", '--memory-swap', "{$mb}m", '--cpus', '2', '--pids-limit', '512',
        '--log-driver', 'json-file', '--log-opt', 'max-size=10m', '--log-opt', 'max-file=1',
        '--security-opt', 'no-new-privileges'];
    if ($c['shm'] > 0) {
        $args = [...$args, '--shm-size', (string) $c['shm']];
    }
    // the data on tmpfs (in RAM, counted in --memory) — and every volume the image declares, or Docker would make an
    // anonymous volume of its own for it
    $dirs = array_values(array_unique([drillDataDir($type, $c), ...$c['volumes']]));
    foreach ($dirs as $i => $dir) {
        $args = [...$args, '--tmpfs', $dir . ':rw,size=' . ($i === 0 ? $tmpfs : 64) . "m$owner"];
    }
    if ($uid !== null) {
        $args = [...$args, '--user', "$uid:$gid"];
    }
    foreach ($c['env'] as $k => $v) {
        $args = [...$args, '-e', "$k=$v"];
    }
    $args = [...$args, ...match ($type) {
        'postgres' => ['-e', 'POSTGRES_HOST_AUTH_METHOD=trust'],
        'mariadb'  => ['-e', "MARIADB_ROOT_PASSWORD=$password", '-e', "MYSQL_ROOT_PASSWORD=$password"],
        'mongodb'  => [],
    }];
    $ep = $c['entrypoint'];
    if ($ep) {
        $args = [...$args, '--entrypoint', $ep[0]];
    }
    $args[] = $c['image_id'];
    // the image's command, then the lean server's options (drillServerArgs) when it starts the server itself
    return [...$args, ...array_slice($ep, 1), ...$c['cmd'], ...drillServerArgs($c, $type)];
}

/**
 * The throwaway server's own options (DRILL_LEAN), when the command starts the server: `postgres` / `mariadbd` /
 * `mysqld`, or options only (the image's entrypoint puts the server in front and passes them on — also to the server
 * it runs for its init). Any other command (a script of the app's own) gets none.
 */
function drillServerArgs(array $c, string $type): array
{
    $cmd = $c['cmd'];
    $server = ['postgres' => ['postgres'], 'mariadb' => ['mariadbd', 'mysqld']][$type] ?? null;
    if ($server === null || !($cmd ? in_array(basename($cmd[0]), $server, true) || ($c['entrypoint'] && str_starts_with($cmd[0], '-')) : (bool) $c['entrypoint'])) {
        return [];
    }
    return $type === 'postgres' ? array_merge(...array_map(fn ($o) => ['-c', $o], DRILL_LEAN['postgres'])) : DRILL_LEAN['mariadb'];
}

/**
 * Does an argument list keep to the «never» list? (the tests, and the job before every run as a second look) — and
 * after the image: the app's own command as the manifest has it, then nothing but the lean server's options.
 */
function drillArgsSafe(array $args, array $c, string $type): bool
{
    $at = array_keys($args, $c['image_id'], true);
    if ($c['image_id'] === '' || count($at) !== 1
        || array_slice($args, $at[0] + 1) !== [...array_slice($c['entrypoint'], 1), ...$c['cmd'], ...drillServerArgs($c, $type)]) {
        return false;
    }
    $s = implode("\x1f", $args);
    if (preg_match('/\x1f(-p|--publish|--publish-all|-P|-v|--volume|--mount|--device|--cap-add|--privileged|--volumes-from|--pid|--ipc|--userns|--add-host|--link|--pull)(=|\x1f)/', "\x1f$s\x1f")) {
        return false;
    }
    if (preg_match('/com\.docker\.compose\.|net\.unraid\.docker\.managed/', $s)) {
        return false;
    }
    $net = array_keys($args, '--network', true);
    if (count($net) !== 1 || ($args[$net[0] + 1] ?? '') !== 'none') {
        return false;
    }
    foreach ($args as $i => $a) {
        if ($a === '-e' && preg_match('/^(\w+)=/', (string) ($args[$i + 1] ?? ''), $m)
            && !in_array($m[1], [...DRILL_ENV_KEEP, 'POSTGRES_HOST_AUTH_METHOD', 'MARIADB_ROOT_PASSWORD', 'MYSQL_ROOT_PASSWORD'], true)) {
            return false;
        }
    }
    if (preg_grep('/^--net(work)?=|^--net$/D', $args)) {
        return false;
    }
    return (bool) preg_grep('/^' . preg_quote(DRILL_LABEL, '/') . '=\d{8}-\d{6}-[0-9a-f]{4}$/D', $args)
        && in_array('no-new-privileges', $args, true) && (bool) preg_grep('/^sha256:[0-9a-f]{64}$/D', $args);
}

// ===================================================================== the plan and its preview

/** The engine's lock, the array, a parity check, the deadline: why no drill can start now (or null) */
function drillBlocker(int $estimate, ?int $nextBackup, int $now): ?array
{
    $var = drillVarIni();
    if ($var && !in_array((string) ($var['fsState'] ?? ''), ARRAY_RUNNING, true)) {
        return ['key' => 'drill_array', 'params' => []];
    }
    if (drillParity($var)) {
        return ['key' => 'drill_parity', 'params' => []];
    }
    $run = drillRunning();
    if ($run) {
        return ['key' => 'drill_running', 'params' => []];
    }
    $busy = rsBusy();
    if ($busy) {
        return $busy;
    }
    if ($nextBackup !== null && $now + min($estimate, DRILL_BUDGET_TOTAL) > $nextBackup - DRILL_DEADLINE_GAP) {
        return ['key' => 'drill_deadline', 'params' => ['time' => $nextBackup]];
    }
    return null;
}

/**
 * What a drill would prove, from a fresh look at the server (the backup place's packages, the engine's settings,
 * Kopia's container): one step per package, dump, SQLite copy, VM disk and Kopia source — and the preview's facts.
 */
function drillPlanBuild(string $scope): array
{
    [$settings, $ctx, $place] = rsPlanPlace();
    $set = drillSettings();
    $pk = $place['found'] ? rsPackages($place['base']) : ['apps' => [], 'vms' => [], 'server' => null, 'flash' => null, 'run' => null];
    $engine = rsEngine($settings);
    $steps = [];
    $live = [];
    foreach ($pk['apps'] as $a) {
        $steps[] = ['do' => 'package', 'kind' => 'app', 'id' => $a['id'], 'name' => $a['name'], 'run' => $a['run'], 'stale' => $a['stale']];
        foreach ($a['dumps'] as $d) {
            $file = "{$a['path']}/{$d['file']}";
            $steps[] = ['do' => 'dump', 'kind' => 'app', 'id' => $a['id'], 'name' => $a['name'], 'container' => $d['container'], 'type' => $d['type'],
                        'file' => $d['file'], 'run' => $a['run'], 'dump_run' => $d['run'], 'db' => $d['db'], 'isize' => rsGzSize($file),
                        'bytes' => $d['bytes'], 'immich' => (bool) array_filter($a['containers'], fn ($c) => stripos($c['image'], 'immich') !== false)] + rsLogin($d);
            if ($set['live_catalog'] && $d['type'] !== 'mongodb') {
                $live[] = ['what' => 'catalog', 'name' => $d['container']];
            }
        }
        foreach ($a['sqlite'] as $q) {
            $steps[] = ['do' => 'sqlite', 'kind' => 'app', 'id' => $a['id'], 'name' => $a['name'], 'container' => $q['container'], 'file' => $q['file'],
                        'source' => $q['source'], 'run' => $a['run'], 'bytes' => $q['bytes']];
            if ($set['live_sqlite'] && $q['source'] !== '') {
                $live[] = ['what' => 'sqlite', 'name' => $q['source']];
            }
        }
    }
    foreach ($pk['vms'] as $v) {
        $steps[] = ['do' => 'package', 'kind' => 'vm', 'id' => $v['id'], 'name' => $v['name'], 'run' => $v['run'], 'stale' => $v['stale']];
        foreach ($v['disks'] as $d) {
            if ($d['source'] !== '') {
                $steps[] = ['do' => 'vmdisk', 'kind' => 'vm', 'id' => $v['id'], 'name' => $v['name'], 'target' => $d['target'], 'source' => $d['source'],
                            'snapshot' => $d['snapshot'], 'run' => $v['run'], 'bytes' => $d['bytes']];
            }
        }
    }
    if ($pk['server']) {
        $steps[] = ['do' => 'package', 'kind' => 'server', 'id' => 'server', 'name' => 'server', 'run' => (string) ($pk['run'] ?? ''), 'stale' => false];
    }
    if ($pk['flash']) {
        $steps[] = ['do' => 'package', 'kind' => 'flash', 'id' => 'flash', 'name' => 'flash', 'run' => (string) ($pk['run'] ?? ''), 'stale' => false];
    }
    // Kopia: the apps' and VMs' own sources first (their whole package is the sample), then the shares
    $kopia = null;
    if ($engine['kopia']) {
        $k = rsKopia($engine, $settings);
        $kopia = ['container' => $k['container'], 'running' => $k['running'], 'root' => $k['root']];
        foreach (['app' => $pk['apps'], 'vm' => $pk['vms']] as $kind => $list) {
            foreach ($list as $x) {
                if (backupSetting($settings, "$kind|{$x['name']}", 'kopia', 'no') === 'yes') {
                    $steps[] = ['do' => 'kopia', 'kind' => $kind, 'id' => $x['id'], 'name' => $x['name'], 'source' => ".{$kind}s/{$x['id']}"];
                }
            }
        }
        foreach ($settings as $key => $_) {
            if (str_starts_with((string) $key, 'share|') && backupSetting($settings, (string) $key, 'mode', 'off') === 'kopia') {
                $share = substr((string) $key, 6);
                if (preg_match('/^[\w .-]+$/D', $share)) {
                    $steps[] = ['do' => 'kopia', 'kind' => 'share', 'id' => $share, 'name' => $share, 'source' => $share];
                }
            }
        }
    }
    $ram = drillRamBudget();
    // dumps from Kopia (L2 from the offsite copy), after the Kopia sources: one per app — its newest — whose package goes to
    // Kopia (its own source, else the backup place's share), within what Kopia may download and the RAM it is played in
    $kopiaDumps = ['n' => 0, 'bytes' => 0, 'names' => []];
    if ($kopia) {
        $left = $set['kopia_mb'] << 20;
        foreach (drillKopiaDumpSteps($pk['apps'], $settings, (string) ($place['share'] ?? '')) as $st) {
            if ($st['bytes'] <= 0 || $st['bytes'] > $left || ($st['type'] !== 'mongodb' && drillDumpNeed($st) + $st['bytes'] > $ram)) {
                continue;                // over the download budget, or too big for RAM with the dump itself in it: not this time
            }
            $left -= $st['bytes'];
            $steps[] = $st;
            $kopiaDumps['n']++;
            $kopiaDumps['bytes'] += $st['bytes'];
            $kopiaDumps['names'][] = $st['container'];
        }
    }
    // a follow-up: what the last drill couldn't check (no room, out of time, Kopia not there …) comes first this time
    [$steps, $followUp] = drillFollowUp($steps, drillCertificate());
    $estimate = 0;
    $asleep = [];
    foreach ($steps as $s) {
        $estimate += drillEstimate($s, $set);
    }
    foreach ($place['places'] as $p) {
        if ($p['asleep']) {
            $asleep[] = $p['base'];
        }
    }
    foreach ($pk['vms'] as $v) {
        foreach ($v['disks'] as $d) {
            foreach (rsLocate($d['source'], $ctx) as $p) {
                if ($p['asleep']) {
                    $asleep[] = $p['base'];
                }
            }
        }
    }
    $now = drillNow();
    $next = drillNextBackup($now);
    $plan = [
        'kind'     => 'drill',
        'scope'    => $scope,
        'what'     => $scope,
        'steps'    => $steps,
        'counts'   => array_count_values(array_column($steps, 'do')) + array_fill_keys(DRILL_STEP_KINDS, 0),
        'estimate' => min($estimate, DRILL_BUDGET_TOTAL),
        'ram'      => $ram,
        'kopia'    => $kopia,
        'kopia_mb' => $set['kopia_mb'],
        'kopia_dumps' => $kopiaDumps,
        'follow_up' => $followUp,
        'live'     => $live,
        'asleep'   => array_values(array_unique($asleep)),
        'too_big'  => array_values(array_map(fn ($s) => ['name' => $s['container'], 'need' => drillDumpNeed($s)],
                          array_filter($steps, fn ($s) => $s['do'] === 'dump' && ($s['copy'] ?? '') !== 'kopia' && $s['type'] !== 'mongodb' && drillDumpNeed($s) > $ram))),
        'next_backup' => $next,
        'deadline' => drillDeadline($now, $next),
        'blockers' => [],
    ];
    if (!$steps) {
        $plan['blockers'][] = ['key' => 'drill_nothing', 'params' => []];
    }
    $b = drillBlocker($plan['estimate'], $next, $now);
    if ($b) {
        array_unshift($plan['blockers'], $b);
    }
    return $plan;
}

/**
 * Follow-up drills: the steps whose item the last drill left «not checked» (dump_no_room, budget, the deadline, Kopia
 * not there …) first, in plan order, then the rest — so drill after drill eventually covers everything. Asleep stays
 * where it is (never woken anyway). The steps and how many came first.
 *
 * @return array{0: list<array>, 1: int}
 */
function drillFollowUp(array $steps, ?array $cert): array
{
    $want = [];
    foreach ((array) ($cert['items'] ?? []) as $it) {
        if (is_array($it) && ($it['result'] ?? '') === 'not_checked') {
            $want[drillStepKey((string) ($it['kind'] ?? ''), (string) ($it['of'] ?? ''), (string) ($it['id'] ?? ''), (string) ($it['what'] ?? ''),
                ($it['kind'] ?? '') === 'dump' && ($it['copy'] ?? '') === 'kopia')] = true;
        }
    }
    if (!$want) {
        return [$steps, 0];
    }
    $first = $rest = [];
    foreach ($steps as $st) {
        $key = drillStepKey($st['do'], $st['kind'], (string) $st['id'], (string) ($st['container'] ?? $st['target'] ?? $st['source'] ?? (isset($st['file']) ? basename((string) $st['file']) : '')),
            $st['do'] === 'dump' && ($st['copy'] ?? '') === 'kopia');
        if (isset($want[$key])) {
            $first[] = $st + ['follow_up' => true];
        } else {
            $rest[] = $st;
        }
    }
    return [[...$first, ...$rest], count($first)];
}

/** A step's identity across drills (the certificate's items say the same): what, of what, which part — a dump from Kopia apart */
function drillStepKey(string $do, string $of, string $id, string $what, bool $kopiaDump): string
{
    return implode("\x1f", [$do, $of, $id, $what, $kopiaDump ? 'kopia' : '']);
}

/** Seconds a step takes, roughly (the preview, the deadline) */
function drillEstimate(array $s, array $set): int
{
    return match ($s['do']) {
        'package' => 3,
        'dump'    => 60 + intdiv((int) ($s['isize'] ?: $s['bytes'] * 8), 1 << 20)             // ~1 MB/s with indexes
                     + (($s['copy'] ?? '') === 'kopia' ? 30 + intdiv((int) $s['bytes'], 2 << 20) : 0),   // from Kopia: ~2 MB/s down
        'sqlite'  => 5 + intdiv((int) $s['bytes'], 200 << 20),
        'vmdisk'  => 2,
        'kopia'   => 120 + ($s['kind'] === 'share' ? 30 : intdiv($set['kopia_mb'], 20)),
        default   => 5,
    };
}

/**
 * The dumps a drill plays from Kopia: per app whose package goes to Kopia — through a source of its own (its package is
 * then left out of the share's), else with the backup place's share — its newest dump (a dump kept from an earlier
 * night is older), Postgres, MariaDB or MongoDB. Steps like the local ones, with copy «kopia» and the Kopia source.
 */
function drillKopiaDumpSteps(array $apps, array $settings, string $placeShare): array
{
    $placeKopia = $placeShare !== '' && backupSetting($settings, "share|$placeShare", 'mode', 'off') === 'kopia';
    $out = [];
    foreach ($apps as $a) {
        $own = backupSetting($settings, "app|{$a['name']}", 'kopia', 'no') === 'yes';
        $dumps = array_values(array_filter($a['dumps'], fn ($d) => in_array($d['type'], ['postgres', 'mariadb', 'mongodb'], true)));
        if ((!$own && !$placeKopia) || !$dumps) {
            continue;
        }
        usort($dumps, fn ($x, $y) => strcmp((string) $y['run'], (string) $x['run']));          // the newest first, else manifest order
        $d = $dumps[0];
        $file = "{$a['path']}/{$d['file']}";
        $out[] = ['do' => 'dump', 'copy' => 'kopia', 'kind' => 'app', 'id' => $a['id'], 'name' => $a['name'], 'container' => $d['container'], 'type' => $d['type'],
                  'file' => $d['file'], 'run' => $a['run'], 'dump_run' => $d['run'], 'db' => $d['db'], 'isize' => rsGzSize($file), 'bytes' => (int) $d['bytes'],
                  'immich' => (bool) array_filter($a['containers'], fn ($c) => stripos($c['image'], 'immich') !== false),
                  'source' => $own ? ".apps/{$a['id']}" : $placeShare] + rsLogin($d);
    }
    return $out;
}

/** RAM a dump needs in its throwaway: its tmpfs (uncompressed size × 4 plus the fixed part) and the server */
function drillDumpNeed(array $s): int
{
    $size = (int) ($s['isize'] ?? 0) ?: (int) ($s['bytes'] ?? 0) * 8;
    return $size * DRILL_DUMP_FACTOR + DRILL_DUMP_FLOOR + DRILL_SERVER_RAM;
}

/** The preview: the plan sealed with a stamp and a token over what will be done */
function drillPlan(array $r): array
{
    $plan = drillPlanBuild('now');
    $stamp = rsStampOf($r);
    $plan['stamp'] = $stamp;
    $plan['token'] = sha1(jsonEncode([$stamp, $plan['steps']]));
    return ['ok' => true, 'preview' => $plan];
}

/** «Practise now…» confirmed: the plan again, the same token, then the job */
function drillStart(array $r): array
{
    $plan = drillPlan($r)['preview'];
    if (!hash_equals($plan['token'], textField($r, 'token'))) {
        throw new Problem('restore_changed');
    }
    $id = drillLaunch($plan);
    return ['ok' => true, 'id' => $id, 'state' => drillState([])];
}

/** Writes the plan and its journal (root only) and hands the job to atd — the id; $run false: only written (tests) */
function drillLaunch(array $plan, bool $run = true): string
{
    if ($plan['blockers']) {
        throw new Problem($plan['blockers'][0]['key'], $plan['blockers'][0]['params'] ?? []);
    }
    $id = ($plan['stamp'] ?? date('Ymd-His', drillNow())) . '-' . bin2hex(random_bytes(2));
    rsPrivateDir(drillData());
    rsPrivateDir(drillDir($id));
    writeAtomic(drillDir($id) . '/plan.json', jsonEncode($plan), 0600, 0, 0);
    $j = drillJournalNew($id, $plan);
    drillJournalWrite($j);
    if (!$run) {
        return $id;
    }
    try {
        hostLaunch('restore-drill', [PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'restore-drill', $id]);
    } catch (Problem $p) {
        $j['result'] = 'refused';
        $j['reason'] = $p->key;
        $j['finished'] = drillNow();
        drillJournalWrite($j);
        throw $p;
    }
    logLine("Mr. Restori: drill $id ({$plan['scope']}, " . count($plan['steps']) . ' steps) started via at');
    return $id;
}

function drillJournalNew(string $id, array $plan): array
{
    return [
        'id'       => $id,
        'kind'     => 'drill',
        'what'     => $plan['scope'],
        'scope'    => $plan['scope'],
        'created'  => drillNow(),
        'started'  => null,
        'finished' => null,
        'pid'      => null,
        'result'   => 'queued',
        'reason'   => null,
        'deadline' => $plan['deadline'],
        'steps'    => array_map(fn ($s) => $s + ['state' => 'pending'], $plan['steps']),
        'made'     => [],
        'egress'   => 0,
        'aside'    => [], 'stopped' => [], 'safety' => [],          // rs* step helpers expect them
    ];
}

/** A drill's journal (root only) and, while it is the newest, the job file the page polls */
function drillJournalWrite(array &$j, bool $public = true): void
{
    $j['heartbeat'] = time();
    writeAtomic(drillDir($j['id']) . '/journal.json', jsonEncode($j), 0600, 0, 0);
    if ($public) {
        writeAtomic(drillJobFile(), jsonEncode(drillJobView($j)));
    }
}

/** The job file: the journal without what the page doesn't need (paths of the made objects stay in the journal) */
function drillJobView(array $j): array
{
    $v = array_intersect_key($j, array_flip(['id', 'kind', 'what', 'scope', 'created', 'started', 'finished', 'pid', 'result', 'reason', 'deadline', 'egress', 'heartbeat']));
    $v['steps'] = array_map(fn ($s) => array_intersect_key($s, array_flip(['do', 'kind', 'id', 'name', 'container', 'type', 'file', 'source', 'target',
        'state', 'level', 'copy', 'code', 'params', 'seconds', 'state_time', 'progress', 'started', 'finished'])), (array) ($j['steps'] ?? []));
    return $v;
}

function drillJournal(string $id): ?array
{
    return readJson(drillDir($id) . '/journal.json');
}

/** The drill going on now — its job lives — or null */
function drillRunning(): ?array
{
    $j = readJson(drillJobFile());
    if (!$j || !is_string($j['id'] ?? null) || !in_array($j['result'] ?? '', ['queued', 'running'], true)) {
        return null;
    }
    if ($j['result'] === 'queued') {
        return time() - (int) ($j['created'] ?? 0) < 120 ? $j : null;
    }
    return rsJobAlive($j) ? $j : null;
}

/** His drills for the page, newest first (a journal "running" whose job is gone was interrupted) */
function drillJournals(): array
{
    $ids = array_values(array_filter(@scandir(drillData(), SCANDIR_SORT_DESCENDING) ?: [], fn ($n) => (bool) preg_match(DRILL_ID_PATTERN, $n)));
    $out = [];
    foreach (array_slice($ids, 0, DRILL_KEEP) as $id) {
        $j = drillJournal($id);
        if (!$j) {
            continue;
        }
        $counts = array_fill_keys([...DRILL_RESULTS, 'skipped', 'pending'], 0);
        foreach ((array) ($j['steps'] ?? []) as $s) {
            $st = (string) ($s['state'] ?? 'pending');
            $counts[$st] = ($counts[$st] ?? 0) + 1;
        }
        $result = (string) ($j['result'] ?? '');
        if (($result === 'running' && !rsJobAlive($j)) || ($result === 'queued' && time() - (int) ($j['created'] ?? 0) > 120)) {
            $result = 'interrupted';            // its job is gone (the sweeper writes it down within the hour)
        }
        $out[] = ['id' => $id, 'scope' => (string) ($j['scope'] ?? ''), 'created' => (int) ($j['created'] ?? 0), 'started' => $j['started'] ?? null,
                  'finished' => $j['finished'] ?? null, 'result' => $result, 'reason' => $j['reason'] ?? null, 'counts' => $counts,
                  'egress' => (int) ($j['egress'] ?? 0)];
    }
    return $out;
}

/** What his page shows of the drill: settings, the certificate, his drills, the one going on, when the next comes */
function drillState(array $r): array
{
    $out = ['ok' => true, 'settings' => drillSettings(), 'certificate' => drillCertificate(), 'running' => drillRunning() ? drillJobView(drillRunning()) : null,
            'drills' => drillJournals(), 'ram' => drillRamBudget(), 'next_backup' => drillNextBackup(), 'packages_since' => drillPackagesSince(),
            'auto' => readJson(drillData() . '/auto.json')];
    $id = $r['id'] ?? null;
    if (is_string($id) && $id !== '') {
        $j = drillJournal($id);
        if (!$j) {
            throw new Problem('unknown_target', ['target' => $id]);
        }
        $log = @file(drillDir($id) . '/log.txt', FILE_IGNORE_NEW_LINES) ?: [];
        $out['journal'] = drillJobView($j);
        $out['log'] = array_slice($log, -300);
    }
    return $out;
}

// ===================================================================== the job

/**
 * "php agent.php job restore-drill <id>" — run by the host's atd (drillLaunch), never as a child of the agent.
 * Takes the engine's lock as {holder: restore, mode: drill} or is refused (busy — exit 75, nothing touched), sweeps
 * what an earlier drill may have left, proves step by step, cleans up after itself whatever happens, writes the
 * certificate and — on a failure — one warning to Unraid's notifications.
 */
function drillJob(array $args): int
{
    $id = (string) ($args[0] ?? '');
    if (!preg_match(DRILL_ID_PATTERN, $id)) {
        fwrite(STDERR, "restore-drill: no such drill\n");
        return 2;
    }
    // his restore helpers (journal, log, heartbeat) write into the drill's folder in this process
    $GLOBALS['rs']['data'] = drillData();
    $GLOBALS['rs']['job_file'] = drillJobFile();
    $j = drillJournal($id);
    $plan = readJson(drillDir($id) . '/plan.json');
    if (!$j || !$plan || ($j['result'] ?? '') !== 'queued') {
        fwrite(STDERR, "restore-drill $id: nothing to do\n");
        return 1;
    }
    $refuse = function (string $reason, array $params = []) use (&$j, $id): int {
        $j['result'] = 'refused';
        $j['reason'] = $reason;
        $j['reason_params'] = $params;
        $j['finished'] = time();
        drillJournalWrite($j);
        rsLog($id, "Refused: $reason");
        drillCertHistory($j);
        return 75;
    };
    $var = drillVarIni();
    if ($var && !in_array((string) ($var['fsState'] ?? ''), ARRAY_RUNNING, true)) {
        return $refuse('drill_array');
    }
    if (drillParity($var)) {
        return $refuse('drill_parity');
    }
    if (time() >= (int) $j['deadline'] - 60) {
        return $refuse('drill_deadline', ['time' => drillNextBackup()]);
    }
    $lock = rsLockTake('drill', 'drill');
    if (!is_resource($lock)) {
        return $refuse('restore_busy_' . (in_array($lock['holder'] ?? '', BACKUP_HOLDERS, true) ? $lock['holder'] : 'other'),
            ['what' => (string) ($lock['what'] ?? ''), 'run' => (string) ($lock['run'] ?? '')]);
    }
    $GLOBALS['rsStop'] = false;
    $GLOBALS['rsStopWhy'] = null;
    $GLOBALS['rsDeadline'] = (int) $j['deadline'];
    $GLOBALS['rsDeadlineWhy'] = 'deadline';
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGHUP] as $sig) {
            pcntl_signal($sig, function (): void {
                $GLOBALS['rsStop'] = true;
                $GLOBALS['rsStopWhy'] ??= 'stopped';
            });
        }
    }
    drillMarker('drill', $id);
    $j['result'] = 'running';
    $j['started'] = time();
    $j['pid'] = getmypid();
    drillJournalWrite($j);
    rsLog($id, "Drill $id ({$j['scope']}), " . count($j['steps']) . ' steps, until ' . date('H:i', (int) $j['deadline']));
    $env = null;
    try {
        drillSweep($id, true);
        $env = drillEnv($j, $plan);
    } catch (Throwable $e) {
        // nothing proven, nothing to blame on the backups: the drill itself couldn't look — ended, said so
        $GLOBALS['rsStop'] = true;
        $GLOBALS['rsStopWhy'] = 'drill_error';
        rsLog($id, 'The drill could not look at the server: ' . mb_substr($e->getMessage(), 0, 400));
    }
    try {
        foreach (array_keys($j['steps']) as $i) {
            if (!empty($GLOBALS['rsStop'])) {
                $j['steps'][$i]['state'] = 'skipped';
                continue;
            }
            $j['steps'][$i]['state'] = 'running';
            $j['steps'][$i]['started'] = time();
            drillJournalWrite($j);
            rsLog($id, sprintf('Step %d/%d: %s %s', $i + 1, count($j['steps']), $j['steps'][$i]['do'], drillStepName($j['steps'][$i])));
            $t0 = microtime(true);
            try {
                $res = drillStep($j, $i, $env);
            } catch (Throwable $e) {
                $res = ['state' => 'failed', 'code' => 'drill_error', 'detail' => mb_substr($e->getMessage(), 0, 400)];
            }
            // stopped from outside (the array, the deadline, SIGTERM) while the step ran: it proved nothing
            if (!empty($GLOBALS['rsStop']) && in_array($res['state'], ['failed', 'not_checked'], true)) {
                $res = ['state' => 'skipped', 'code' => (string) $GLOBALS['rsStopWhy']];
            }
            $res['seconds'] ??= (int) round(microtime(true) - $t0);
            $j['steps'][$i] = array_merge($j['steps'][$i], $res, ['finished' => time()]);
            rsLog($id, "  -> {$res['state']}" . (($res['code'] ?? '') !== '' ? " ({$res['code']})" : '')
                . (($res['detail'] ?? '') !== '' ? ': ' . mb_substr((string) $res['detail'], 0, 600) : ''));
            drillJournalWrite($j);
        }
    } finally {
        // the array is being stopped: the lock file (on the pool) let go first, only the throwaways removed — the rest
        // (Kopia's temporary folder, the sweeper's look) waits for the next agent start
        $fast = ($GLOBALS['rsStopWhy'] ?? '') === 'array_stopping';
        if ($fast) {
            rsLockRelease($lock);
        }
        drillCleanup($j, $env, true, $fast);
        $stopped = !empty($GLOBALS['rsStop']);
        $failed = (bool) array_filter($j['steps'], fn ($s) => ($s['state'] ?? '') === 'failed');
        $j['result'] = $stopped ? 'aborted' : ($failed ? 'failed' : 'passed');
        $j['reason'] = $stopped ? (string) ($GLOBALS['rsStopWhy'] ?? 'stopped') : null;
        $j['finished'] = time();
        drillJournalWrite($j);
        if (!$fast) {
            try {
                drillSweep($id, true);
            } catch (Throwable) {
            }
            rsLockRelease($lock);
        }
        drillMarker('drill', null);
        rsLog($id, "Done: {$j['result']}" . ($j['reason'] ? " ({$j['reason']})" : ''));
        drillCertWrite($j, $env);
        if ($j['result'] === 'failed') {
            drillNotify($j);
        }
    }
    logLine("Mr. Restori: drill $id - {$j['result']}" . ($j['reason'] ? " ({$j['reason']})" : ''));
    return $j['result'] === 'failed' ? 1 : 0;
}

/** A step's subject, for the log */
function drillStepName(array $s): string
{
    return trim($s['name'] . ' ' . ($s['container'] ?? $s['file'] ?? $s['target'] ?? $s['source'] ?? ''));
}

/** What every step needs, read once: the engine's settings, the server's look, the packages, Kopia */
function drillEnv(array $j, array $plan): array
{
    $settings = backupReadSettings(rsUbData() . '/settings.ini');
    $ctx = rsContext($settings);
    $place = rsPlace($settings, $ctx);
    $base = $GLOBALS['drill']['base'] ?? $place['base'];          // tests: a backup place of their own
    $pk = $base !== null && ($place['found'] || isset($GLOBALS['drill']['base'])) ? rsPackages($base)
        : ['apps' => [], 'vms' => [], 'server' => null, 'flash' => null, 'run' => null];
    $engine = rsEngine($settings);
    $kopia = $engine['kopia'] ? rsKopia($engine, $settings) : null;
    $kopiaDumps = array_values(array_filter($plan['steps'], fn ($s) => $s['do'] === 'dump' && ($s['copy'] ?? '') === 'kopia'));
    return ['settings' => $settings, 'ctx' => $ctx, 'place' => $place, 'base' => $base, 'pk' => $pk, 'kopia' => $kopia, 'uid' => null,
            'set' => drillSettings(), 'ram' => drillRamBudget(), 'kopia_left' => drillSettings()['kopia_mb'] << 20,
            'kopia_until' => null, 'shares' => (int) count(array_filter($plan['steps'], fn ($s) => $s['do'] === 'kopia' && $s['kind'] === 'share')),
            'kopia_times' => [], 'kopia_reserved' => array_sum(array_map(fn ($s) => (int) $s['bytes'], $kopiaDumps)),
            'kopia_dumps' => array_map(fn ($l) => array_column($l, 'file'), array_reduce($kopiaDumps, function ($by, $s) {
                $by[(string) $s['source']][] = $s;
                return $by;
            }, []))];
}

function drillStep(array &$j, int $i, array &$env): array
{
    $s = $j['steps'][$i];
    // the backup place fell asleep since the plan: its packages are not looked at (never woken), never «failed»
    if (!empty($env['place']['asleep']) && !isset($GLOBALS['drill']['base']) && in_array($s['do'], ['package', 'dump', 'sqlite'], true)) {
        return ['state' => 'asleep', 'code' => 'asleep', 'level' => 0, 'copy' => 'package'];
    }
    return match ($s['do']) {
        'package' => drillDoPackage($s, $env),
        'dump'    => drillDoDump($j, $i, $env),
        'sqlite'  => drillDoSqlite($j, $s, $env),
        'vmdisk'  => drillDoVmDisk($s, $env),
        'kopia'   => drillDoKopia($j, $i, $env),
        default   => ['state' => 'failed', 'code' => 'drill_error', 'detail' => "unknown step {$s['do']}"],
    };
}

/** The worst of several results: failed > warning > not_checked > asleep > ok */
function drillWorst(array $results): string
{
    foreach (['failed', 'warning', 'not_checked', 'asleep'] as $r) {
        if (in_array($r, $results, true)) {
            return $r;
        }
    }
    return 'ok';
}

// --------------------------------------------------------------------- where a file lies in the run's snapshot

/**
 * The same file in the local snapshot of a run (the engine's, all pools and disks of a share at once): found (its
 * path), asleep (a part of the share sleeps — never woken, never «missing»), or missing.
 *
 * @return array{state: string, path?: string, snap?: string}
 */
function drillLocal(string $path, string $run, array &$ctx): array
{
    if (!preg_match('/^\d{8}-\d{4}$/D', $run)) {
        return ['state' => 'missing'];
    }
    $asleep = false;
    foreach (rsLocate($path, $ctx) as $p) {
        if ($p['asleep']) {
            $asleep = true;
            continue;
        }
        foreach ($p['snaps'] as $s) {
            if (rsMomentKey($s) === "run:$run") {
                clearstatcache(true, $s['path']);
                if (is_file($s['path']) && !is_link($s['path'])) {
                    return ['state' => 'found', 'path' => $s['path'], 'snap' => (string) $s['id']];
                }
                break;
            }
        }
    }
    return ['state' => $asleep ? 'asleep' : 'missing'];
}

// --------------------------------------------------------------------- packages (L1)

/** A package: every file of its manifest there with its size, .gz intact, dumps with their closing line, XML readable */
function drillDoPackage(array $s, array &$env): array
{
    $pk = $env['pk'];
    $base = (string) $env['base'];
    $dir = match ($s['kind']) {
        'app'    => "$base/apps/{$s['id']}",
        'vm'     => "$base/vms/{$s['id']}",
        'server' => "$base/server",
        'flash'  => "$base/flash",
        default  => '',
    };
    $mfile = $s['kind'] === 'server' ? "$dir/run.json" : "$dir/manifest.json";
    $m = readJson($mfile);
    if (!$m) {
        return ['state' => 'failed', 'code' => 'manifest_unreadable', 'level' => 0, 'copy' => 'package', 'params' => ['package' => $s['name']]];
    }
    $r = drillPackageFiles($dir, rsFiles($m));
    $results = [$r['state']];
    $params = ['package' => $s['name'], 'files' => $r['files'], 'bytes' => $r['bytes']] + $r['params'];
    $code = $r['code'];
    // what it found: the first failure names the item, a warning never hides a failure
    $found = function (string $state, string $c) use (&$results, &$code): bool {
        $take = $code === 'package_ok' || ($state === 'failed' && drillWorst($results) !== 'failed');
        if ($take) {
            $code = $c;
        }
        $results[] = $state;
        return $take;                 // its details go with it only when it names the item
    };
    $run = rsStr($m['run'] ?? '');
    $stateTime = rsRunTime($run);
    if ($s['kind'] === 'app') {
        $p = array_values(array_filter($pk['apps'], fn ($a) => $a['id'] === $s['id']))[0] ?? null;
        foreach ($p['dumps'] ?? [] as $d) {
            if ($d['kept']) {           // the newest dump failed: the one kept is older than the package
                $found('warning', 'dump_old');
                $params['dump_run'] = $d['run'];
                $params['dump_time'] = $d['time'];
                $stateTime = min($stateTime ?? PHP_INT_MAX, (int) $d['time']) ?: $stateTime;
            }
        }
        foreach ($p['templates'] ?? [] as $t) {
            if (!drillXmlOk("$dir/$t")) {
                if ($found('failed', 'xml_unreadable')) {
                    $params['file'] = $t;
                }
            }
        }
    } elseif ($s['kind'] === 'vm') {
        $p = array_values(array_filter($pk['vms'], fn ($v) => $v['id'] === $s['id']))[0] ?? null;
        $x = $p && $p['xml'] !== '' ? drillXmlOk("$dir/{$p['xml']}") : null;
        if (!$x) {
            if ($found('failed', 'xml_unreadable')) {
                $params['file'] = (string) ($p['xml'] ?? '') ?: 'xml';
            }
        } elseif ((string) $x->getElementsByTagName('name')->item(0)?->textContent !== $s['name']
            || ($p['uuid'] !== '' && strtolower(trim((string) $x->getElementsByTagName('uuid')->item(0)?->textContent)) !== $p['uuid'])) {
            $found('warning', 'xml_differs');
        } elseif ($x->getElementsByTagName('tpm')->length && !$p['tpm']) {
            $found('warning', 'tpm_missing');
        }
        foreach ($p['nvram'] ?? [] as $n) {
            if ((int) @filesize("$dir/nvram/$n") === 0) {
                if ($found('failed', 'nvram_empty')) {
                    $params['file'] = "nvram/$n";
                }
            }
        }
    } elseif ($s['kind'] === 'server' && is_file("$dir/libvirt.tar.gz")) {
        $list = drillTarList("$dir/libvirt.tar.gz");
        if ($list === null) {
            if ($found('failed', 'archive_unreadable')) {
                $params['file'] = 'libvirt.tar.gz';
            }
        } else {
            $missing = [];
            foreach ($pk['vms'] as $v) {
                if (!$v['stale'] && !preg_grep('#(^|/)qemu/' . preg_quote($v['name'], '#') . '\.xml$#', $list)) {
                    $missing[] = $v['name'];
                }
            }
            if ($missing) {
                if ($found('warning', 'libvirt_vm_missing')) {
                    $params['names'] = implode(', ', $missing);
                }
            }
            $params['entries'] = count($list);
        }
    } elseif ($s['kind'] === 'flash' && is_file("$dir/flash.tar.gz")) {
        $list = drillTarList("$dir/flash.tar.gz");
        if ($list === null) {
            if ($found('failed', 'archive_unreadable')) {
                $params['file'] = 'flash.tar.gz';
            }
        } elseif (!preg_grep('#(^|/)config/ident\.cfg$#', $list)) {
            $found('warning', 'flash_incomplete');
        } else {
            $params['entries'] = count($list);
        }
    }
    $state = drillWorst($results);
    return ['state' => $state, 'level' => $state === 'failed' ? 0 : 1, 'copy' => 'package', 'code' => $state === 'ok' ? 'package_ok' : $code,
            'params' => $params + ['run' => $run, 'stale' => !empty($s['stale'])], 'run' => $run, 'state_time' => $stateTime];
}

/**
 * The files of a manifest against the folder: there, the size the manifest says, a .gz intact (a dump: its closing
 * line too). Paths come from rsFiles() (no "..").
 */
function drillPackageFiles(string $dir, array $files): array
{
    $out = ['state' => 'ok', 'code' => 'package_ok', 'params' => [], 'files' => 0, 'bytes' => 0];
    foreach ($files as $f) {
        if ($f['what'] === 'error') {
            continue;
        }
        $path = "$dir/{$f['path']}";
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path)) {
            return ['state' => 'failed', 'code' => 'package_file_missing', 'params' => ['file' => $f['path']]] + $out;
        }
        $size = (int) filesize($path);
        if ($size !== $f['bytes']) {
            return ['state' => 'failed', 'code' => 'package_size_differs', 'params' => ['file' => $f['path'], 'have' => $size, 'want' => $f['bytes']]] + $out;
        }
        $out['files']++;
        $out['bytes'] += $size;
        if (str_ends_with($f['path'], '.gz')) {
            if ($f['what'] === 'dump' && preg_match('#^db/(postgres|mariadb)_#', $f['path'], $x)) {
                $tail = drillGzTail($path);
                if ($tail === null) {
                    return ['state' => 'failed', 'code' => 'gzip_broken', 'params' => ['file' => $f['path']]] + $out;
                }
                if (!str_contains($tail, $x[1] === 'postgres' ? 'PostgreSQL database cluster dump complete' : 'Dump completed')) {
                    return ['state' => 'failed', 'code' => 'dump_incomplete', 'params' => ['file' => $f['path']]] + $out;
                }
            } elseif (drillRunStoppable(['gzip', '-t', $path], 1800)[0] !== 0) {
                return ['state' => 'failed', 'code' => 'gzip_broken', 'params' => ['file' => $f['path']]] + $out;
            }
        }
    }
    return $out;
}

/**
 * A command that may take long (gzip -t, tar -tzf, an integrity_check) — ended at once when the job is asked to stop
 * (rsWatch(): SIGTERM, the array stopping, the deadline): the job must never hold the pool busy.
 *
 * @return array{0:int, 1:string, 2:string}  exit code (124: ended), stdout, stderr
 */
function drillRunStoppable(array $cmd, int $timeout): array
{
    $p = proc_open(drillCmd($cmd), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', rsEnv());
    if (!is_resource($p)) {
        return [127, '', 'could not start ' . ($cmd[0] ?? '?')];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = $err = '';
    $until = microtime(true) + $timeout;
    $ended = false;
    while (!feof($pipes[1]) || !feof($pipes[2])) {
        $read = array_values(array_filter([$pipes[1], $pipes[2]], fn ($x) => !feof($x)));
        $w = $e = null;
        @stream_select($read, $w, $e, 0, 200000);
        $out .= (string) @fread($pipes[1], 1 << 20);
        $err .= (string) @fread($pipes[2], 65536);
        if (strlen($out) > (32 << 20)) {
            $out = substr($out, -(32 << 20));
        }
        if (microtime(true) > $until || rsWatch()) {
            $ended = true;
            break;
        }
    }
    if ($ended) {
        proc_terminate($p);
        usleep(100000);
        proc_terminate($p, 9);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    return [$ended ? 124 : $code, $out, $err];
}

/** The end of a .gz after gzip -t — both stoppable — for a dump's closing line; null when damaged or stopped */
function drillGzTail(string $file, int $bytes = 8192): ?string
{
    if (drillRunStoppable(['gzip', '-t', $file], 7200)[0] !== 0) {
        return null;
    }
    $h = @gzopen($file, 'rb');
    if (!$h) {
        return null;
    }
    $tail = '';
    while (!gzeof($h)) {
        $chunk = gzread($h, 1 << 20);
        if ($chunk === false || $chunk === '' || rsWatch()) {
            break;
        }
        $tail = substr($tail . $chunk, -$bytes);
    }
    $whole = gzeof($h);
    gzclose($h);
    return $whole ? $tail : null;
}

/** An XML file (≤ 1 MB) read without the network or entities — the document, or null */
function drillXmlOk(string $file): ?DOMDocument
{
    $size = @filesize($file);
    if (!is_file($file) || is_link($file) || !$size || $size > (1 << 20)) {
        return null;
    }
    $doc = new DOMDocument();
    $old = libxml_use_internal_errors(true);
    $ok = $doc->loadXML((string) file_get_contents($file), LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($old);
    return $ok && $doc->documentElement ? $doc : null;
}

/** The names in a .tar.gz, or null when it can't be read */
function drillTarList(string $file): ?array
{
    [$exit, $out] = drillRunStoppable(['tar', '-tzf', $file], 300);
    return $exit === 0 ? array_values(array_filter(explode("\n", $out), 'strlen')) : null;
}

// --------------------------------------------------------------------- dumps (L2)

/**
 * A dump played into a throwaway of the same image: from the local snapshot of the package's run (else the
 * package now), ready over 127.0.0.1, played and checked with his restore's own functions, extensions counted,
 * the live database's list of databases against the dump's (read only, switchable) — and the throwaway gone again.
 */
function drillDoDump(array &$j, int $i, array &$env): array
{
    $s = $j['steps'][$i];
    if (($s['copy'] ?? '') === 'kopia') {
        return drillDoKopiaDump($j, $i, $env);
    }
    $out = ['level' => 0, 'copy' => 'package', 'run' => (string) $s['run'], 'state_time' => rsRunTime((string) $s['dump_run']), 'params' => ['container' => $s['container']]];
    $pkgFile = "{$env['base']}/apps/{$s['id']}/{$s['file']}";
    $local = drillLocal($pkgFile, (string) $s['run'], $env['ctx']);
    if ($local['state'] === 'asleep') {
        return ['state' => 'asleep', 'code' => 'asleep'] + $out;
    }
    $file = $local['state'] === 'found' ? $local['path'] : $pkgFile;
    if ($local['state'] === 'found') {
        $out['copy'] = 'snapshot';
    }
    if (!is_file($file)) {
        return ['state' => 'failed', 'code' => 'package_file_missing', 'params' => ['file' => $s['file']]] + $out;
    }
    $isize = rsGzSize($file);
    $pre = drillDumpCheck($s, $env, $isize, (int) @filesize($file), $out);
    return isset($pre['state']) ? $pre : drillDumpThrowaway($j, $i, $env, $s, $pre, $file, $isize, $out);
}

/**
 * Before a throwaway is made: the app's container in its manifest (the image id), that image on this server, room in
 * the RAM the drill may use — for the server and its data, less what else the dump takes there ($more: a dump from
 * Kopia lies in RAM itself). A result «not checked», or what the throwaway needs: {c, need, ram}.
 */
function drillDumpCheck(array $s, array &$env, int $isize, int $bytes, array $out, int $more = 0): array
{
    $m = readJson("{$env['base']}/apps/{$s['id']}/manifest.json") ?? [];
    $c = drillContainerOf($m, (string) $s['container']);
    if (!$c || $c['image_id'] === '') {
        return ['state' => 'not_checked', 'code' => 'image_unknown'] + $out;
    }
    [$exit] = run(drillCmd(['docker', 'image', 'inspect', '--format', '{{.Id}}', $c['image_id']]), 30);
    if ($exit !== 0) {
        return ['state' => 'not_checked', 'code' => 'image_missing', 'params' => ['container' => $s['container'], 'image' => $c['image']]] + $out;
    }
    $need = drillDumpNeed(['isize' => $isize, 'bytes' => $bytes]);
    $ram = min($env['ram'], drillRamBudget());
    if ($s['type'] !== 'mongodb' && $need + $more > $ram) {
        return ['state' => 'not_checked', 'code' => 'too_big_for_ram', 'params' => ['container' => $s['container'], 'need' => $need + $more, 'budget' => $ram]] + $out;
    }
    return ['c' => $c, 'need' => $need, 'ram' => max(1 << 28, $ram - $more)];
}

/** The throwaway of the dump's image made, the dump played and checked in it — and the throwaway gone again */
function drillDumpThrowaway(array &$j, int $i, array &$env, array $s, array $pre, string $file, int $isize, array $out): array
{
    if (time() + drillEstimate(['copy' => ''] + $s + ['isize' => $isize], $env['set']) > (int) $j['deadline']) {
        return ['state' => 'not_checked', 'code' => 'budget', 'params' => ['container' => $s['container']]] + $out;
    }
    $c = $pre['c'];
    $name = DRILL_PREFIX . "{$j['id']}-$i";
    $mem = min($pre['ram'], max($pre['need'], 1 << 30));
    $args = drillContainerArgs($c, (string) $s['type'], $name, $j['id'], $mem, bin2hex(random_bytes(16)));
    if (!drillArgsSafe($args, $c, (string) $s['type'])) {
        return ['state' => 'failed', 'code' => 'drill_error', 'detail' => 'throwaway arguments refused'] + $out;
    }
    // written down before it exists: the sweeper finds it whatever happens next
    drillMade($j, ['kind' => 'container', 'name' => $name, 'image' => $c['image_id']]);
    try {
        [$exit, , $err] = run(drillCmd($args), 120);
        if ($exit !== 0) {
            return ['state' => 'failed', 'code' => 'throwaway_failed', 'detail' => drillCut($err)] + $out;
        }
        return drillPlayCheck($j, $i, $env, $s + ['need' => $pre['need'], 'mem' => $mem, 'datadir' => drillDataDir((string) $s['type'], $c)], $name, $file, $out);
    } finally {
        // gone right after its step: its RAM is free for the next one
        foreach ($j['made'] as $n => $x) {
            if (($x['name'] ?? '') === $name && empty($x['gone'])) {
                $j['made'][$n]['gone'] = drillRemoveContainer($name, $j['id']);
            }
        }
        $j['steps'][$i] = array_merge($j['steps'][$i], array_intersect_key($s, $j['steps'][$i]));     // its own fields again, not the throwaway's
        unset($j['steps'][$i]['progress'], $j['steps'][$i]['tcp'], $j['steps'][$i]['timeout'], $j['steps'][$i]['method']);
    }
}

/**
 * A dump from Kopia (L2 from the offsite copy): the newest complete snapshot of the source that holds the package, the
 * dump read back through `kopia show` with the drill's fresh cache — into a file of the drill's own in RAM (never a
 * disk; his restore's play reads a file), hashed on the way and compared with the same file in the local snapshot of
 * the run Kopia read it from —, then played into a throwaway like a local dump. Its download counts in the egress and
 * in what Kopia may download; the file is gone right after its step.
 */
function drillDoKopiaDump(array &$j, int $i, array &$env): array
{
    $s = $j['steps'][$i];
    $out = ['level' => 0, 'copy' => 'kopia', 'run' => '', 'state_time' => null, 'params' => ['container' => $s['container'], 'source' => $s['source']]];
    $k = $env['kopia'];
    $bytes = (int) $s['bytes'];
    // what was kept for this dump from the start (the samples leave it alone) is its own now
    $env['kopia_reserved'] = max(0, (int) ($env['kopia_reserved'] ?? 0) - $bytes);
    if (!$k || !$k['container'] || !$k['running'] || !$k['root']) {
        return ['state' => 'not_checked', 'code' => 'kopia_unavailable', 'params' => $out['params'] + ['name' => (string) ($k['container'] ?? '')]] + $out;
    }
    $env['kopia_until'] ??= min((int) $j['deadline'], time() + DRILL_BUDGET_KOPIA);
    if (time() >= $env['kopia_until'] || $bytes > $env['kopia_left']) {
        return ['state' => 'not_checked', 'code' => 'budget'] + $out;
    }
    // the throwaway can be made at all (its image here, room for it and the dump in RAM) — before anything is downloaded
    $pre = drillDumpCheck($s, $env, (int) $s['isize'], $bytes, $out, $bytes);
    if (isset($pre['state'])) {
        return $pre;
    }
    $env['uid'] ??= rsKopiaUid($k['container']);
    if (!array_filter($j['made'], fn ($x) => ($x['kind'] ?? '') === 'kopia_tmp')) {
        drillMade($j, ['kind' => 'kopia_tmp', 'name' => "/tmp/uso-drill-{$j['id']}", 'container' => $k['container'], 'uid' => $env['uid']]);
    }
    [$exit, $json, $err] = drillExec($j, drillKopiaCmd($k['container'], $env['uid'], $j['id'], ['--no-progress', 'snapshot', 'list', "{$k['root']}/{$s['source']}", '--json']),
        max(30, min(300, $env['kopia_until'] - time())));
    if ($exit !== 0) {
        return ['state' => !empty($GLOBALS['rsStop']) ? 'skipped' : 'failed', 'code' => 'kopia_list_failed', 'detail' => drillCut($err)] + $out;
    }
    $snaps = drillKopiaParse($json);
    $snap = array_values(array_filter($snaps, fn ($x) => $x['incomplete'] === '' && $x['obj'] !== null))[0] ?? null;
    if (!$snap) {
        return ['state' => 'warning', 'code' => $snaps ? 'kopia_incomplete' : 'kopia_none'] + $out;
    }
    $out['state_time'] = $snap['time'];
    $out['run'] = (string) $snap['run'];
    $out['params']['time'] = $snap['time'];
    // the package's folder in the snapshot, the dump's entry in it (and the manifest Kopia keeps: which run wrote the dump)
    $pkg = drillKopiaPackageDir($j, $env, $snap['obj'], (string) $s['source'], 'app', (string) $s['id']);
    $entry = null;
    if ($pkg) {
        $obj = $pkg['obj'];
        $parts = explode('/', (string) $s['file']);
        $leaf = array_pop($parts);
        foreach ($parts as $name) {
            $obj = array_values(array_filter(drillKopiaDir($j, $env, $obj) ?? [], fn ($e) => $e['name'] === $name && $e['type'] === 'd'))[0]['obj'] ?? null;
            if ($obj === null) {
                break;
            }
        }
        $entry = $obj !== null ? (array_values(array_filter(drillKopiaDir($j, $env, $obj) ?? [], fn ($e) => $e['name'] === $leaf && $e['type'] === 'f'))[0] ?? null) : null;
        $m = array_values(array_filter(drillKopiaDir($j, $env, $pkg['obj']) ?? [], fn ($e) => $e['name'] === 'manifest.json' && $e['type'] === 'f'))[0] ?? null;
        if ($entry && $m && ($m['size'] ?? PHP_INT_MAX) <= (1 << 20) && $m['size'] <= $env['kopia_left'] - $bytes) {
            [$exit, $mjson] = drillExec($j, drillKopiaCmd($k['container'], $env['uid'], $j['id'], ['show', $m['obj']]), 60, 1 << 20);
            $j['egress'] = (int) ($j['egress'] ?? 0) + strlen($mjson);
            $env['kopia_left'] -= strlen($mjson);
            $f = $exit === 0 ? (array_values(array_filter(rsFiles((array) json_decode($mjson, true)), fn ($x) => $x['path'] === $s['file']))[0] ?? null) : null;
            if ($f && $f['time'] !== null) {
                $out['state_time'] = min((int) $snap['time'], $f['time']);          // a dump kept from an earlier night is older
                $out['params']['dump_run'] = $f['run'];
            }
        }
    }
    if (!$entry) {
        return ['state' => 'warning', 'code' => 'kopia_dump_missing', 'params' => $out['params'] + ['file' => $s['file']]] + $out;
    }
    $size = $entry['size'];
    if ($size !== null && $size > $env['kopia_left']) {
        return ['state' => 'not_checked', 'code' => 'budget'] + $out;
    }
    $file = drillRamFile($j['id'], $i, (string) $s['file']);
    if ($file === null) {
        return ['state' => 'not_checked', 'code' => 'kopia_unavailable', 'params' => $out['params'] + ['name' => (string) $k['container']]] + $out;
    }
    drillMade($j, ['kind' => 'kopia_dump', 'name' => $file]);
    try {
        $old = umask(0077);
        $h = @fopen($file, 'xb');
        umask($old);
        if (!$h) {
            return ['state' => 'not_checked', 'code' => 'kopia_unavailable', 'params' => $out['params'] + ['name' => (string) $k['container']]] + $out;
        }
        $hash = hash_init('sha256');
        $got = 0;
        $full = false;
        [$exit, , $err] = drillExec($j, drillKopiaCmd($k['container'], $env['uid'], $j['id'], ['show', $entry['obj']]), max(30, $env['kopia_until'] - time()),
            $size !== null ? $size + 1 : $env['kopia_left'], function (string $data) use ($h, $hash, &$got, &$full): void {
                hash_update($hash, $data);
                $got += strlen($data);
                $full = $full || @fwrite($h, $data) !== strlen($data);
            });
        fclose($h);
        $j['egress'] = (int) ($j['egress'] ?? 0) + $got;
        $env['kopia_left'] -= $got;
        $out['params']['bytes'] = $got;
        if ($full) {
            // RAM ran out under the file itself: the drill's room, not the backup
            return ['state' => 'not_checked', 'code' => 'dump_no_room', 'level' => 1, 'params' => ['container' => $s['container'],
                    'need_mb' => intdiv($pre['need'] + $bytes, 1 << 20), 'ram_mb' => intdiv($pre['ram'] + $bytes, 1 << 20)]] + $out;
        }
        if ($exit !== 0 || ($size !== null && $got !== $size)) {
            rsLog($j['id'], "  kopia show {$s['file']}: exit $exit, $got bytes" . (trim($err) !== '' ? ': ' . drillCut($err) : ''));
            return ['state' => !empty($GLOBALS['rsStop']) ? 'skipped' : 'failed', 'code' => 'kopia_read_failed', 'params' => $out['params'] + ['file' => $s['file']]] + $out;
        }
        // what went up against the same file in the local snapshot of the run Kopia read it from (asleep: not compared, never woken)
        $local = $snap['run'] !== null ? drillKopiaLocal("{$pkg['rel']}/{$s['file']}", (string) $snap['run'], $env['ctx']) : null;
        $out['params']['compared'] = $local && $local['state'] === 'found' ? 1 : 0;
        $out['params']['local_asleep'] = $local && $local['state'] === 'asleep' ? 1 : 0;
        if ($local && $local['state'] === 'found' && !hash_equals(hash_final($hash), (string) hash_file('sha256', $local['path']))) {
            rsLog($j['id'], "  {$s['file']}: Kopia's copy differs from the local snapshot ({$local['snap']})");
            return ['state' => 'failed', 'code' => 'kopia_differs', 'params' => $out['params'] + ['path' => "{$pkg['rel']}/{$s['file']}"]] + $out;
        }
        $out['level'] = 1;                  // read back whole from the repository
        if ($s['type'] !== 'mongodb') {
            $tail = drillGzTail($file);
            if ($tail === null) {
                return ['state' => !empty($GLOBALS['rsStop']) ? 'skipped' : 'failed', 'code' => 'gzip_broken', 'level' => 0, 'params' => $out['params'] + ['file' => $s['file']]] + $out;
            }
            if (!str_contains($tail, $s['type'] === 'postgres' ? 'PostgreSQL database cluster dump complete' : 'Dump completed')) {
                return ['state' => 'failed', 'code' => 'dump_incomplete', 'level' => 0, 'params' => $out['params'] + ['file' => $s['file']]] + $out;
            }
        }
        $res = drillDumpThrowaway($j, $i, $env, $s, $pre, $file, rsGzSize($file), $out);
        $res['params'] = ($res['params'] ?? []) + array_intersect_key($out['params'], array_flip(['source', 'time', 'dump_run', 'bytes', 'compared', 'local_asleep']));
        return $res;
    } finally {
        foreach ($j['made'] as $n => $x) {
            if (($x['name'] ?? '') === $file && empty($x['gone'])) {
                $j['made'][$n]['gone'] = !file_exists($file) || @unlink($file);
            }
        }
    }
}

/** A Kopia directory object's entries, read back (null: Kopia didn't give it) */
function drillKopiaDir(array &$j, array &$env, string $obj): ?array
{
    [$exit, $json] = drillExec($j, drillKopiaCmd($env['kopia']['container'], $env['uid'], $j['id'], ['show', $obj]), 120, 32 << 20);
    return $exit === 0 ? drillKopiaEntries($json) : null;
}

/**
 * A package's folder in a Kopia snapshot: an app's or VM's own source holds it as <share>/<folder>/apps|vms/<id>, the
 * backup place's share as <folder>/apps|vms/<id> (or <part>/<folder>/… where the engine mounted the share split over
 * its pools and disks). Its object and its path as the local compare reads it (<share>/…), or null.
 *
 * @return array{obj: string, rel: string}|null
 */
function drillKopiaPackageDir(array &$j, array &$env, string $root, string $source, string $kind, string $id): ?array
{
    if (!preg_match('#^/mnt/user/([^/]+)/(.+)$#', (string) ($env['base_user'] ?? $env['base']), $m)) {
        return null;
    }
    $tail = [...explode('/', $m[2]), "{$kind}s", $id];
    $tries = [];
    if ($source !== $m[1]) {
        $tries[] = [[$m[1], ...$tail], $m[1] . '/' . implode('/', $tail)];
    } else {
        $tries[] = [$tail, $m[1] . '/' . implode('/', $tail)];
        foreach (drillKopiaDir($j, $env, $root) ?? [] as $e) {
            if ($e['type'] === 'd' && isset($env['ctx']['fs'][$e['name']])) {
                $tries[] = [[$e['name'], ...$tail], "{$m[1]}/{$e['name']}/" . implode('/', $tail)];
            }
        }
    }
    foreach ($tries as [$walk, $rel]) {
        $obj = $root;
        foreach ($walk as $name) {
            $obj = array_values(array_filter(drillKopiaDir($j, $env, $obj) ?? [], fn ($e) => $e['name'] === $name && $e['type'] === 'd'))[0]['obj'] ?? null;
            if ($obj === null) {
                break;
            }
        }
        if ($obj !== null) {
            return ['obj' => $obj, 'rel' => $rel];
        }
    }
    return null;
}

/** The drill's own folder in RAM (a dump from Kopia lies there while it is played): root only */
function drillRamDir(): string
{
    return ($GLOBALS['drill']['run_dir'] ?? RUN_DIR) . '/drill';
}

/** Where a dump from Kopia lies while it is played: <RAM>/drill/<id>-<step>-<name> — null when that folder can't be had */
function drillRamFile(string $id, int $i, string $name): ?string
{
    try {
        rsPrivateDir(drillRamDir());
    } catch (Throwable) {
        return null;
    }
    return drillRamDir() . "/$id-$i-" . substr((string) preg_replace('/[^\w.@-]/', '_', basename($name)), 0, 120);
}

/**
 * The throwaway answers, the dump is played and checked — his restore's own functions (rsDoReady over 127.0.0.1,
 * rsDoPlay streamed with its own budget: over it «not checked», never failed; rsDoVerify) — then the extensions and
 * the live database's list of databases.
 */
function drillPlayCheck(array &$j, int $i, array &$env, array $s, string $name, string $file, array $out): array
{
    $login = match ($s['type']) {
        'postgres' => ['login' => 'user', 'user_var' => 'POSTGRES_USER', 'password_var' => 'POSTGRES_PASSWORD'],
        'mariadb'  => ['login' => 'root', 'user_var' => '', 'password_var' => 'MARIADB_ROOT_PASSWORD'],
        default    => ['login' => 'none', 'user_var' => '', 'password_var' => ''],
    };
    $step = ['container' => $name, 'type' => $s['type'], 'file' => $file, 'db' => $s['db'], 'immich' => !empty($s['immich']), 'method' => 'fresh',
             'tcp' => true, 'timeout' => DRILL_BUDGET_READY] + $login;
    $ready = $s['type'] === 'mongodb' ? drillMongoReady($j, $name) : rsDoReady($j, $step);
    if ($ready['state'] !== 'ok') {
        return ['state' => 'failed', 'code' => 'not_ready', 'detail' => drillContainerTail($name)] + $out;
    }
    $j['steps'][$i] = array_merge($j['steps'][$i], $step);
    $before = [$GLOBALS['rsDeadline'] ?? null, $GLOBALS['rsDeadlineWhy'] ?? null];
    $GLOBALS['rsDeadline'] = min((int) $j['deadline'], time() + DRILL_BUDGET_PLAY);
    $GLOBALS['rsDeadlineWhy'] = $GLOBALS['rsDeadline'] < (int) $j['deadline'] ? 'budget' : 'deadline';
    $t0 = time();
    $log = rsDir($j['id']) . '/log.txt';
    clearstatcache(true, $log);
    $from = (int) @filesize($log);
    try {
        $play = rsDoPlay($j, $i);
    } finally {
        [$GLOBALS['rsDeadline'], $GLOBALS['rsDeadlineWhy']] = $before;
    }
    $seconds = time() - $t0;
    if (!empty($GLOBALS['rsStop']) && ($GLOBALS['rsStopWhy'] ?? '') === 'budget') {
        $GLOBALS['rsStop'] = false;
        $GLOBALS['rsStopWhy'] = null;
        return ['state' => 'not_checked', 'code' => 'budget', 'params' => ['container' => $s['container']]] + $out;
    }
    $room = drillPlayRoom($play, $name, $s, drillLogSince($log, $from), ['seconds' => $seconds] + $out);
    if ($room) {
        return $room;
    }
    $detail = drillCut((string) ($play['detail'] ?? ''));
    $results = [$play['state']];
    $params = ['container' => $s['container'], 'seconds' => $seconds];
    $code = $play['state'] === 'ok' ? 'verify_ok' : (string) ($play['note'] ?? 'play_failed');
    if ($play['state'] !== 'failed') {
        $verify = rsDoVerify($j, $step);
        $results[] = $verify['state'];
        if ($verify['state'] !== 'ok') {
            $code = (string) ($verify['note'] ?? 'verify_empty');
        }
        $params += (array) ($verify['params'] ?? []);
        if ($s['type'] === 'postgres') {
            $ext = drillExtensions($name, $file);
            $params['extensions'] = $ext['have'];
            if ($ext['missing']) {
                $results[] = 'warning';
                $code = $code === 'verify_ok' ? 'extensions_missing' : $code;
                $params['missing'] = implode(', ', $ext['missing']);
            }
        }
        if ($env['set']['live_catalog'] && in_array($s['type'], ['postgres', 'mariadb'], true)) {
            $lost = drillLiveCatalog($s, $file);
            if ($lost) {
                $results[] = 'warning';
                $code = $code === 'verify_ok' ? 'db_missing_in_dump' : $code;
                $params['dbs'] = implode(', ', $lost);
            }
        }
    }
    $state = drillWorst($results);
    return ['state' => $state, 'code' => $code, 'params' => $params, 'detail' => $detail, 'seconds' => $seconds,
            'level' => $state === 'failed' ? 1 : 2] + $out;
}


/**
 * A play that didn't go through because the throwaway ran out of room: the drill's RAM, not the backup — «not checked»
 * (the dump is intact: L1), never «failed», no warning sent for it. Null when the play went through or failed otherwise.
 */
function drillPlayRoom(array $play, string $name, array $s, string $said, array $out): ?array
{
    if ($play['state'] === 'ok' || !drillNoRoom($name, (string) ($s['datadir'] ?? ''), $said)) {
        return null;
    }
    return ['state' => 'not_checked', 'code' => 'dump_no_room', 'level' => 1, 'detail' => drillCut((string) ($play['detail'] ?? '')),
            'params' => ['container' => $s['container'], 'need_mb' => intdiv((int) ($s['need'] ?? 0), 1 << 20), 'ram_mb' => intdiv((int) ($s['mem'] ?? 0), 1 << 20)]] + $out;
}

/**
 * Did the play run out of room? The client's or the server's own words (ENOSPC — Postgres' PANIC on its WAL, InnoDB's
 * «error 28» behind «The table … is full»), the data dir's tmpfs full (df inside the throwaway, while it still runs), or
 * a lost connection with the server killed for memory. Anything else is the play's own failure.
 */
function drillNoRoom(string $name, string $dir, string $said): bool
{
    $enospc = '/No space left on device|\berror 28\b|Errcode: 28\b|The table \S+ is full/i';
    if (preg_match($enospc, $said)) {
        return true;
    }
    [, $out, $err] = run(drillCmd(['docker', 'logs', '--tail', '50', $name]), 20);
    if (preg_match($enospc, $out . "\n" . $err)) {
        return true;
    }
    if ($dir !== '') {
        [$exit, $out] = run(drillCmd(['docker', 'exec', $name, 'df', '-P', '-k', $dir]), 20);
        if ($exit === 0 && preg_match('/^\S+\s+(\d+)\s+\d+\s+(\d+)\s+\d+%/m', $out, $m) && (int) $m[1] > 0
            && (int) $m[2] < max(8192, intdiv((int) $m[1], 50))) {
            return true;                              // < 2 % or < 8 MB free
        }
    }
    if (str_contains($said, 'connection to server was lost') || str_contains($said, 'Lost connection') || str_contains($said, 'server has gone away')) {
        [$exit, $out] = run(drillCmd(['docker', 'inspect', '--format', '{{.State.OOMKilled}}', $name]), 20);
        return $exit === 0 && trim($out) === 'true';
    }
    return false;
}

/** What the clients wrote into the drill's log since an offset (the end only: what the classification needs) */
function drillLogSince(string $log, int $from): string
{
    clearstatcache(true, $log);
    $size = (int) @filesize($log);
    $from = max($from, $size - 65536);
    return $size > $from ? (string) @file_get_contents($log, false, null, $from) : '';
}

/** MongoDB answers (the throwaway has no login: mongorestore needs none) */
function drillMongoReady(array &$j, string $name): array
{
    $until = time() + DRILL_BUDGET_READY;
    $script = 'B=mongosh; command -v mongosh >/dev/null 2>&1 || B=mongo; exec "$B" --quiet --host 127.0.0.1 --eval "db.adminCommand({ping: 1}).ok"';
    do {
        [$exit, $out] = run(drillCmd(['docker', 'exec', $name, 'sh', '-c', $script]), 30);
        if ($exit === 0 && trim($out) === '1') {
            return ['state' => 'ok'];
        }
        if (rsBeat($j)) {
            break;
        }
        sleep(2);
    } while (time() < $until);
    return ['state' => 'failed'];
}

/** The end of a throwaway's own log (why it didn't come up), cut */
function drillContainerTail(string $name): string
{
    [, $out, $err] = run(drillCmd(['docker', 'logs', '--tail', '15', $name]), 20);
    return drillCut(trim($out . "\n" . $err));
}

/** A client's words for the log and the journal: lines cut (they may quote row data), at most 12 */
function drillCut(string $text): string
{
    $lines = array_slice(array_filter(explode("\n", str_replace("\r", '', $text)), fn ($l) => trim($l) !== ''), -12);
    return implode("\n", array_map(fn ($l) => mb_substr($l, 0, DRILL_LOG_LINE), $lines));
}

/**
 * The dump's extensions per database (CREATE EXTENSION lines, \connect for the database) against what the
 * throwaway has now; plpgsql is every cluster's own.
 *
 * @return array{have: int, missing: list<string>}
 */
function drillExtensions(string $container, string $file): array
{
    $want = drillDumpFacts($file)['ext'];
    $missing = [];
    $have = 0;
    foreach ($want as $db => $exts) {
        if (preg_match('#[=\x00-\x1f]|://#', (string) $db) || $db === '') {
            continue;
        }
        [$exit, $out] = run(drillCmd(['docker', 'exec', $container, 'sh', '-c', 'PGDATABASE="$1" exec psql -X -q -tA -U postgres -c "SELECT extname FROM pg_extension"', 'sh', (string) $db]), 60);
        $got = $exit === 0 ? array_filter(array_map('trim', explode("\n", $out))) : [];
        $have += count($got);
        foreach ($exts as $e) {
            if (!in_array($e, $got, true)) {
                $missing[] = "$db.$e";
            }
        }
    }
    return ['have' => $have, 'missing' => $missing];
}

/**
 * What a Postgres dump holds besides its tables: the databases it connects to (pg_dumpall's \connect) and the
 * extensions it creates in each.
 *
 * @return array{dbs: list<string>, ext: array<string, list<string>>}
 */
function drillDumpFacts(string $file): array
{
    $h = @gzopen($file, 'rb');
    if (!$h) {
        return ['dbs' => [], 'ext' => []];
    }
    $db = '';
    $out = ['dbs' => [], 'ext' => []];
    $carry = '';
    while (!gzeof($h)) {
        $data = gzread($h, 1 << 20);
        if ($data === false || $data === '' || rsWatch()) {
            break;
        }
        $data = $carry . $data;
        $nl = strrpos($data, "\n");
        if ($nl === false) {
            $carry = $data;
            continue;
        }
        drillExtLines(substr($data, 0, $nl + 1), $db, $out);
        $carry = substr($data, $nl + 1);
    }
    drillExtLines($carry, $db, $out);
    gzclose($h);
    return $out;
}

function drillExtLines(string $lines, string &$db, array &$out): void
{
    if (!preg_match_all('/^(\\\\connect\s+.*|CREATE EXTENSION (?:IF NOT EXISTS )?"?([A-Za-z0-9_-]+)"?.*)$/m', $lines, $m, PREG_SET_ORDER)) {
        return;
    }
    foreach ($m as $x) {
        if ($x[1][0] === '\\') {
            $count = [];
            $cur = $db === '' ? null : $db;
            rsDumpCount($x[1] . "\n", $cur, $count);
            $db = (string) $cur;
            if ($db !== '' && !in_array($db, $out['dbs'], true)) {
                $out['dbs'][] = $db;
            }
            continue;
        }
        if ($x[2] !== 'plpgsql' && !in_array($x[2], $out['ext'][$db] ?? [], true)) {
            $out['ext'][$db][] = $x[2];
        }
    }
}

/**
 * The live database's databases (one read-only catalog query in the app's own container, its variables as names
 * only — like his restores) against the dump's: a database the dump doesn't hold would be lost. Nothing when the
 * container doesn't run or doesn't answer.
 *
 * @return list<string>  databases the dump misses
 */
function drillLiveCatalog(array $s, string $file): array
{
    if (!rsIsRunning((string) $s['container'])) {
        return [];
    }
    $sys = ['information_schema', 'mysql', 'performance_schema', 'sys', 'postgres', 'template0', 'template1'];
    try {
        $script = rsDbScript('databases', $s);           // variable names only (RS_ENV_VARS), the values stay in the container
        [$exit, $out] = run(drillCmd(['docker', 'exec', (string) $s['container'], 'sh', '-c', $script]), 30);
    } catch (Throwable) {
        return [];
    }
    $have = $s['type'] === 'postgres' ? drillDumpFacts($file)['dbs'] : [(string) $s['db']];
    if ($exit !== 0) {
        return [];
    }
    $live = array_values(array_filter(array_map('trim', explode("\n", $out)), fn ($n) => $n !== '' && !in_array($n, $sys, true)));
    return array_values(array_diff($live, $have));
}

// --------------------------------------------------------------------- SQLite (L1)

/** A media server's copy from the run's snapshot: integrity_check, user_version, its main table against the live one */
function drillDoSqlite(array &$j, array $s, array &$env): array
{
    $pkgFile = "{$env['base']}/apps/{$s['id']}/{$s['file']}";
    $local = drillLocal($pkgFile, (string) $s['run'], $env['ctx']);
    $file = $local['state'] === 'found' ? $local['path'] : $pkgFile;
    $out = ['level' => 0, 'copy' => $local['state'] === 'found' ? 'snapshot' : 'package', 'run' => (string) $s['run'],
            'state_time' => rsRunTime((string) $s['run']), 'params' => ['file' => basename((string) $s['file'])]];
    if ($local['state'] === 'asleep') {
        return ['state' => 'asleep', 'code' => 'asleep'] + $out;
    }
    if (!is_file($file)) {
        return ['state' => 'failed', 'code' => 'package_file_missing', 'params' => ['file' => $s['file']]] + $out;
    }
    $copy = drillSqliteLook("file:" . drillUriPath($file) . '?mode=ro&immutable=1', true);
    if ($copy['error'] !== null) {
        return ['state' => 'not_checked', 'code' => 'sqlite_unchecked', 'detail' => drillCut($copy['error'])] + $out;
    }
    if ($copy['integrity'] !== 'ok') {
        return ['state' => 'failed', 'code' => 'sqlite_corrupt', 'detail' => drillCut($copy['integrity'])] + $out;
    }
    $params = $out['params'] + ['version' => $copy['version'], 'table' => $copy['table'], 'rows' => $copy['rows']];
    $state = 'ok';
    $code = 'sqlite_ok';
    $live = null;
    if ($env['set']['live_sqlite'] && $s['source'] !== '' && str_starts_with((string) $s['source'], '/mnt/')) {
        $asleep = (bool) array_filter(rsLocate((string) $s['source'], $env['ctx']), fn ($p) => $p['asleep']);
        if (!$asleep && is_file((string) $s['source'])) {
            $live = drillSqliteLook('file:' . drillUriPath((string) $s['source']) . '?mode=ro', false);
        }
    }
    if ($live && $live['error'] === null) {
        $params['live_version'] = $live['version'];
        $params['live_rows'] = $live['rows'];
        if ($copy['table'] !== null && $live['rows'] !== null && $copy['rows'] !== null && $live['rows'] > 0 && $copy['rows'] === 0) {
            [$state, $code] = ['failed', 'sqlite_foreign'];
        } elseif ($live['version'] !== null && $copy['version'] !== null && $live['version'] !== $copy['version']) {
            [$state, $code] = ['warning', 'sqlite_version_differs'];
        } elseif ($live['rows'] !== null && $copy['rows'] !== null && $copy['rows'] < $live['rows'] / 2) {
            [$state, $code] = ['warning', 'sqlite_rows_differ'];
        }
    }
    return ['state' => $state, 'code' => $code, 'params' => $params, 'level' => $state === 'failed' ? 0 : 1] + $out;
}

/** A path in an SQLite URI: each part percent-encoded (?, # and % mean something there) */
function drillUriPath(string $path): string
{
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

/**
 * One look into an SQLite database through the sqlite3 command (its own process: a big library takes seconds, the
 * budget is a timeout): integrity_check (copies only), user_version, the main table's rows.
 *
 * @return array{error: ?string, integrity: ?string, version: ?int, table: ?string, rows: ?int}
 */
function drillSqliteLook(string $uri, bool $integrity): array
{
    $bin = $GLOBALS['drill']['sqlite3'] ?? 'sqlite3';
    $in = implode(',', array_map(fn ($t) => "'$t'", DRILL_SQLITE_TABLES));
    $sql = ($integrity ? 'PRAGMA integrity_check;' : "SELECT 'ok';") . "\nPRAGMA user_version;\nSELECT name FROM sqlite_master WHERE type = 'table' AND name IN ($in) LIMIT 1;";
    [$exit, $out, $err] = drillRunStoppable([$bin, '-bail', $uri, $sql], $integrity ? DRILL_BUDGET_SQLITE : 30);
    if ($exit !== 0) {
        return ['error' => trim($err) ?: "exit $exit", 'integrity' => null, 'version' => null, 'table' => null, 'rows' => null];
    }
    $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), 'strlen'));
    $table = isset($lines[2]) && in_array($lines[2], DRILL_SQLITE_TABLES, true) ? $lines[2] : null;
    // integrity_check names every problem on a line of its own: everything before user_version
    $integ = $lines[0] ?? '';
    $version = null;
    foreach ($lines as $k => $l) {
        if ($k > 0 && ctype_digit($l)) {
            $version = (int) $l;
            if ($k > 1) {
                $integ = implode("\n", array_slice($lines, 0, $k));
            }
            $table = isset($lines[$k + 1]) && in_array($lines[$k + 1], DRILL_SQLITE_TABLES, true) ? $lines[$k + 1] : null;
            break;
        }
    }
    $rows = null;
    if ($table !== null) {
        [$exit, $out] = drillRunStoppable([$bin, $uri, "SELECT count(*) FROM \"$table\";"], $integrity ? DRILL_BUDGET_SQLITE : 60);
        $rows = $exit === 0 && ctype_digit(trim($out)) ? (int) trim($out) : null;
    }
    return ['error' => null, 'integrity' => $integ, 'version' => $version, 'table' => $table, 'rows' => $rows];
}

// --------------------------------------------------------------------- VM disks (L1)

/**
 * The header of a qcow2 image, read here (never qemu-img: it would follow a backing path into live files):
 * version, virtual size, backing file and its format (the header extension 0xE2792ACA). Null: no qcow2.
 *
 * @return array{version:int, size:int, backing:?string, backing_format:?string}|null
 */
function drillQcow2(string $head): ?array
{
    if (strlen($head) < 72 || substr($head, 0, 4) !== "QFI\xfb") {
        return null;
    }
    $u32 = fn (int $o): int => (int) unpack('N', substr($head, $o, 4))[1];
    $u64 = fn (int $o): int => (int) unpack('J', substr($head, $o, 8))[1];
    $version = $u32(4);
    if ($version < 2 || $version > 3) {
        return null;
    }
    $boff = $u64(8);
    $blen = $u32(16);
    $backing = null;
    if ($boff > 0 && $blen > 0) {
        if ($blen > 1023 || $boff + $blen > strlen($head)) {
            return ['version' => $version, 'size' => $u64(24), 'backing' => '', 'backing_format' => null];      // there, but unreadable here
        }
        $backing = substr($head, $boff, $blen);
    }
    $fmt = null;
    $o = $version >= 3 && strlen($head) >= 104 ? $u32(100) : 72;
    for ($n = 0; $n < 64 && $o + 8 <= strlen($head); $n++) {
        $type = $u32($o);
        $len = $u32($o + 4);
        if ($type === 0 || $len > 65536) {
            break;
        }
        if ($type === 0xE2792ACA) {
            $fmt = rtrim(substr($head, $o + 8, $len), "\0");
        }
        $o += 8 + (($len + 7) & ~7);
    }
    return ['version' => $version, 'size' => $u64(24), 'backing' => $backing, 'backing_format' => $fmt];
}

/** Does a raw disk carry a partition table (MBR 55AA, GPT «EFI PART» at 512 or 4096) — or anything at all? */
function drillRawLook(string $head): string
{
    if (strlen($head) >= 520 && substr($head, 512, 8) === 'EFI PART' || strlen($head) >= 4104 && substr($head, 4096, 8) === 'EFI PART') {
        return 'gpt';
    }
    if (strlen($head) >= 512 && substr($head, 510, 2) === "\x55\xAA") {
        return 'mbr';
    }
    return trim($head, "\0") === '' ? 'empty' : 'data';
}

/**
 * A VM disk in the run's snapshot: there with its size, a qcow2 chain whose every backing file lies in a snapshot of
 * the same run, the base not all zero. A sleeping part: «asleep», never woken.
 */
function drillDoVmDisk(array $s, array &$env): array
{
    $out = ['level' => 0, 'copy' => 'snapshot', 'run' => (string) $s['run'], 'state_time' => rsRunTime((string) $s['run']),
            'params' => ['disk' => basename((string) $s['source']), 'target' => $s['target']]];
    if ((string) $s['snapshot'] === '') {
        return ['state' => 'not_checked', 'code' => 'vm_no_snapshot'] + $out;
    }
    $l = drillLocal((string) $s['source'], (string) $s['run'], $env['ctx']);
    if ($l['state'] === 'asleep') {
        return ['state' => 'asleep', 'code' => 'asleep'] + $out;
    }
    if ($l['state'] !== 'found') {
        return ['state' => 'failed', 'code' => 'vm_disk_missing', 'params' => $out['params'] + ['file' => $s['source']]] + $out;
    }
    $file = $l['path'];
    $size = $s['bytes'] !== null && (int) @filesize($file) !== (int) $s['bytes'];
    $ok = fn (array $extra) => ['state' => $size ? 'warning' : 'ok', 'code' => $size ? 'vm_disk_size' : 'vm_chain_ok', 'params' => $out['params'] + $extra, 'level' => 1] + $out;
    for ($depth = 1; $depth <= 8; $depth++) {
        $h = @fopen($file, 'rb');
        if (!$h) {
            return ['state' => 'failed', 'code' => 'vm_disk_missing', 'params' => $out['params'] + ['file' => basename($file)]] + $out;
        }
        $head = (string) fread($h, 65536);
        $q = drillQcow2($head);
        if ($q === null) {
            // raw: the end of the chain — a partition table, or at least something in its first MiB
            $look = drillRawLook($head);
            if ($look === 'empty') {
                $look = trim((string) fread($h, (1 << 20) - 65536), "\0") === '' ? 'empty' : 'data';
            }
            fclose($h);
            if ($look === 'empty') {
                return ['state' => 'warning', 'code' => 'vm_disk_empty', 'params' => $out['params'] + ['chain' => $depth], 'level' => 1] + $out;
            }
            return $ok(['chain' => $depth, 'look' => $look]);
        }
        fclose($h);
        if ($q['backing'] === null) {
            return $ok(['chain' => $depth]);
        }
        $b = $q['backing'];
        if ($b === '' || preg_match('/[\x00-\x1f]/', $b) || str_contains("/$b/", '/../')) {
            return ['state' => 'failed', 'code' => 'vm_chain_outside', 'params' => $out['params'] + ['file' => '?']] + $out;
        }
        if (str_starts_with($b, '/')) {
            // a path as the VM sees it: the same file in this run's snapshot (another dataset of it too), else outside
            $l = drillLocal($b, (string) $s['run'], $env['ctx']);
            if ($l['state'] === 'asleep') {
                return ['state' => 'asleep', 'code' => 'asleep'] + $out;
            }
            if ($l['state'] !== 'found') {
                return ['state' => 'failed', 'code' => 'vm_chain_outside', 'params' => $out['params'] + ['file' => $b]] + $out;
            }
            $file = $l['path'];
        } else {
            // relative: next to this file, inside the same snapshot
            $file = dirname($file) . '/' . $b;
            clearstatcache(true, $file);
            if (!is_file($file) || is_link($file)) {
                return ['state' => 'failed', 'code' => 'vm_chain_outside', 'params' => $out['params'] + ['file' => $b]] + $out;
            }
        }
    }
    return ['state' => 'failed', 'code' => 'vm_chain_outside', 'params' => $out['params'] + ['file' => basename($file)]] + $out;
}


// --------------------------------------------------------------------- Kopia (L0, structure, L1 sample)

/** A kopia command inside its container as the server's user, with a fresh cache and log folder of the drill's own */
function drillKopiaCmd(string $container, int $uid, string $id, array $args): array
{
    $tmp = "/tmp/uso-drill-$id";
    return ['docker', 'exec', '-u', (string) $uid, '-e', "KOPIA_CACHE_DIRECTORY=$tmp/cache", '-e', "KOPIA_LOG_DIR=$tmp/logs",
            ...($uid !== 0 ? ['-e', 'HOME=/tmp'] : []), $container, 'kopia', ...$args, '--log-dir', "$tmp/logs", '--disable-content-log'];
}

/**
 * A command whose output is read as it comes, with the heartbeat, the array-stop watch and the deadline (rsBeat) —
 * and a byte limit for stdout ($limit; over it: ended). $chunk gets every piece of stdout instead of keeping it.
 *
 * @return array{0:int, 1:string, 2:string, 3:bool}  exit code, stdout (unless $chunk), stderr, ended by us
 */
function drillExec(array &$j, array $cmd, int $timeout, int $limit = 33554432, ?callable $chunk = null): array
{
    $p = proc_open(drillCmd($cmd), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', rsEnv());
    if (!is_resource($p)) {
        return [127, '', 'could not start', false];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = $err = '';
    $bytes = 0;
    $until = time() + $timeout;
    $ended = false;
    while (true) {
        $read = array_values(array_filter([$pipes[1], $pipes[2]], fn ($x) => !feof($x)));
        if (!$read) {
            break;
        }
        $w = $e = null;
        @stream_select($read, $w, $e, 0, 200000);
        $o = (string) @fread($pipes[1], 1 << 20);
        if ($o !== '') {
            $bytes += strlen($o);
            if ($chunk) {
                $chunk($o);
            } else {
                $out .= $o;
            }
        }
        $err .= (string) @fread($pipes[2], 65536);
        if (strlen($err) > 65536) {
            $err = substr($err, -65536);
        }
        if ($bytes > $limit || time() > $until || rsBeat($j)) {
            $ended = true;
            break;
        }
    }
    if ($ended) {
        proc_terminate($p);
        usleep(200000);
        proc_terminate($p, 9);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    return [$ended ? 124 : $code, $out, $err, $ended];
}

/** What Kopia knows of a source, newest first, with each snapshot's root object and run (description "uso-backup <run>") */
function drillKopiaParse(string $json): array
{
    $byId = [];
    foreach ((array) json_decode($json, true) as $s) {
        if (is_array($s) && is_string($s['id'] ?? null)) {
            $obj = $s['rootEntry']['obj'] ?? null;
            $byId[$s['id']] = is_string($obj) && preg_match('/^[A-Za-z0-9]{1,8}[0-9a-f]{16,128}$/D', $obj) ? $obj : null;
        }
    }
    $out = [];
    foreach (rsKopiaParse($json) as $s) {
        $s['obj'] = $byId[$s['id']] ?? null;
        $s['run'] = preg_match('/\b(\d{8}-\d{4})\b/', $s['description'], $m) ? $m[1] : null;
        $out[] = $s;
    }
    return $out;
}

/** A Kopia directory object's entries ({name, type d|f|s, size, obj}) — only in that shape */
function drillKopiaEntries(string $json): array
{
    $d = json_decode($json, true);
    $out = [];
    foreach (is_array($d['entries'] ?? null) ? $d['entries'] : [] as $e) {
        if (!is_array($e) || !is_string($e['name'] ?? null) || !in_array($e['type'] ?? '', ['d', 'f'], true) || !is_string($e['obj'] ?? null)
            || !preg_match('/^[A-Za-z0-9]{0,8}[0-9a-f]{16,128}$/D', $e['obj']) || $e['name'] === '' || str_contains($e['name'], '/') || in_array($e['name'], ['.', '..'], true)) {
            continue;
        }
        $size = $e['size'] ?? null;
        $out[] = ['name' => $e['name'], 'type' => $e['type'], 'obj' => $e['obj'],
                  'size' => is_int($size) ? $size : (is_string($size) && ctype_digit($size) ? (int) $size : null)];
    }
    return $out;
}

/**
 * A Kopia source: its newest snapshot (L0: there, complete, no failed files, its age), its structure (snapshot
 * verify, metadata only), and a sample (L1): files of the newest complete snapshot streamed back with a fresh cache
 * — for an app's or VM's own source its package, for a share a few random files — hashed here and compared with the
 * same file in the local snapshot of the run Kopia read it from.
 */
function drillDoKopia(array &$j, int $i, array &$env): array
{
    $s = $j['steps'][$i];
    $out = ['level' => 0, 'copy' => 'kopia', 'params' => ['source' => $s['source']]];
    $k = $env['kopia'];
    if (!$k || !$k['container'] || !$k['running'] || !$k['root']) {
        return ['state' => 'not_checked', 'code' => 'kopia_unavailable', 'params' => ['source' => $s['source'], 'name' => (string) ($k['container'] ?? '')]] + $out;
    }
    $env['kopia_until'] ??= min((int) $j['deadline'], time() + DRILL_BUDGET_KOPIA);
    if (time() >= $env['kopia_until']) {
        return ['state' => 'not_checked', 'code' => 'budget'] + $out;
    }
    $env['uid'] ??= rsKopiaUid($k['container']);
    if (!array_filter($j['made'], fn ($x) => ($x['kind'] ?? '') === 'kopia_tmp')) {
        drillMade($j, ['kind' => 'kopia_tmp', 'name' => "/tmp/uso-drill-{$j['id']}", 'container' => $k['container'], 'uid' => $env['uid']]);
    }
    $path = "{$k['root']}/{$s['source']}";
    [$exit, $json, $err] = drillExec($j, drillKopiaCmd($k['container'], $env['uid'], $j['id'], ['--no-progress', 'snapshot', 'list', $path, '--json']),
        max(30, min(300, $env['kopia_until'] - time())));
    if ($exit !== 0) {
        return ['state' => !empty($GLOBALS['rsStop']) ? 'skipped' : 'failed', 'code' => 'kopia_list_failed', 'detail' => drillCut($err)] + $out;
    }
    $snaps = drillKopiaParse($json);
    $complete = array_values(array_filter($snaps, fn ($x) => $x['incomplete'] === ''));
    if (!$complete) {
        return ['state' => 'warning', 'code' => $snaps ? 'kopia_incomplete' : 'kopia_none'] + $out;
    }
    $newest = $complete[0];
    $results = [];
    $code = 'kopia_ok';
    $params = ['source' => $s['source'], 'files' => $newest['files'], 'bytes' => $newest['bytes'], 'time' => $newest['time'], 'run' => $newest['run']];
    $env['kopia_times'][$s['source']] = $newest['time'];
    if ($snaps[0]['incomplete'] !== '') {
        $results[] = 'warning';
        $code = 'kopia_incomplete';
    }
    if ($newest['failed'] > 0) {
        $results[] = 'warning';
        $code = 'kopia_failed_files';
        $params['failed'] = $newest['failed'];
    }
    // older than the last backup run that went to Kopia? (a source that stopped going there)
    $lastRun = readJson(rsUbData() . '/state/last-run.json');
    if (is_int($lastRun['started'] ?? null) && $newest['time'] < $lastRun['started'] - 3 * 86400) {
        $results[] = 'warning';
        $code = $code === 'kopia_ok' ? 'kopia_old' : $code;
    }
    // structure: every content of the snapshots in the index (metadata only, no download of files)
    [$exit, , $err, $ended] = drillExec($j, drillKopiaCmd($k['container'], $env['uid'], $j['id'],
        ['snapshot', 'verify', "--sources=$path", '--max-errors=10', '--verify-files-percent=0']), max(30, $env['kopia_until'] - time()));
    if ($ended && !empty($GLOBALS['rsStop'])) {
        return ['state' => 'skipped', 'code' => (string) $GLOBALS['rsStopWhy']] + $out;
    }
    if ($ended) {
        $params['verify'] = 'budget';
    } elseif ($exit !== 0) {
        return ['state' => 'failed', 'code' => 'kopia_verify_failed', 'params' => $params, 'detail' => drillCut($err), 'level' => 0, 'state_time' => $newest['time']] + $out;
    } else {
        $params['verify'] = 'ok';
    }
    // the sample
    $sample = $newest['obj'] !== null ? drillKopiaSample($j, $s, $newest, $env) : ['files' => 0, 'bytes' => 0, 'compared' => 0, 'asleep' => 0, 'differs' => null, 'missing' => 0];
    $j['egress'] = (int) ($j['egress'] ?? 0) + $sample['bytes'];
    $params += ['sampled' => $sample['files'], 'sample_bytes' => $sample['bytes'], 'compared' => $sample['compared'], 'local_asleep' => $sample['asleep']];
    if ($sample['differs'] !== null) {
        return ['state' => 'failed', 'code' => 'kopia_differs', 'params' => $params + ['path' => $sample['differs']], 'level' => 0, 'state_time' => $newest['time']] + $out;
    }
    if (!empty($sample['error'])) {
        $results[] = 'warning';
        $code = $code === 'kopia_ok' ? 'kopia_sample_failed' : $code;
    }
    $state = drillWorst($results);
    return ['state' => $state, 'code' => $code, 'params' => $params, 'level' => $sample['files'] > 0 ? 1 : 0, 'state_time' => $newest['time'],
            'run' => (string) $newest['run']] + $out;
}

/**
 * Files of a Kopia snapshot to stream back: for an app's or VM's own source its package (in the source as
 * <share>/<folder>/apps|vms/<id>/ — the dumps and copies first), for a share a few random files found by walking
 * down; within what Kopia may still download in this drill. Each is hashed while it streams (nothing on a disk) and
 * compared with the same file in the local snapshot of the run.
 */
function drillKopiaSample(array &$j, array $s, array $snap, array &$env): array
{
    $k = $env['kopia'];
    $res = ['files' => 0, 'bytes' => 0, 'compared' => 0, 'asleep' => 0, 'differs' => null, 'missing' => 0, 'error' => false];
    $show = fn (string $obj, int $limit) => drillExec($j, drillKopiaCmd($k['container'], $env['uid'], $j['id'], ['show', $obj]), 120, $limit);
    $dirOf = function (string $obj) use ($show): ?array {
        [$exit, $json] = $show($obj, 32 << 20);
        return $exit === 0 ? drillKopiaEntries($json) : null;
    };
    $files = [];                    // [rel path, obj, size]
    if ($s['kind'] !== 'share') {
        $base = (string) $env['base'];
        if (!preg_match('#^/mnt/user/([^/]+)/(.+)$#', $base, $m)) {
            return $res;
        }
        $walk = [$m[1], ...explode('/', $m[2]), "{$s['kind']}s", $s['id']];
        $obj = $snap['obj'];
        foreach ($walk as $name) {
            $entries = $dirOf($obj);
            $hit = array_values(array_filter($entries ?? [], fn ($e) => $e['name'] === $name && $e['type'] === 'd'))[0] ?? null;
            if (!$hit) {
                $res['error'] = $entries === null;
                return $res;
            }
            $obj = $hit['obj'];
        }
        $stack = [[implode('/', $walk), $obj, 0]];
        while ($stack && count($files) < 200) {
            [$rel, $o, $depth] = array_shift($stack);
            foreach ($dirOf($o) ?? [] as $e) {
                if ($e['type'] === 'd' && $depth < 4) {
                    $stack[] = ["$rel/{$e['name']}", $e['obj'], $depth + 1];
                } elseif ($e['type'] === 'f') {
                    $files[] = ["$rel/{$e['name']}", $e['obj'], $e['size']];
                }
            }
        }
        usort($files, fn ($a, $b) => [!str_contains($a[0], '/db/'), $a[0]] <=> [!str_contains($b[0], '/db/'), $b[0]]);
        // a dump this drill plays from Kopia is read back there (and compared), not twice
        $skip = array_map(fn ($f) => implode('/', $walk) . "/$f", (array) ($env['kopia_dumps'][$s['source']] ?? []));
        $files = array_values(array_filter($files, fn ($f) => !in_array($f[0], $skip, true)));
    } else {
        // a few random files of at least 1 KB: walk down from the top, a random entry at each level
        $looks = 0;
        $left = max(0, $env['kopia_left'] - ($env['kopia_reserved'] ?? 0));
        // what is left shared by the shares still to come, one file at most 64 MB (several small proofs beat one big one)
        $per = min(64 << 20, $env['shares'] > 0 ? intdiv($left, max(1, $env['shares'])) : $left);
        for ($n = 0; $n < DRILL_KOPIA_SAMPLES * 3 && count($files) < DRILL_KOPIA_SAMPLES && $looks < DRILL_KOPIA_LOOKS; $n++) {
            $obj = $snap['obj'];
            $rel = $s['source'];
            for ($depth = 0; $depth < 8 && $looks < DRILL_KOPIA_LOOKS; $depth++) {
                $entries = $dirOf($obj);
                $looks++;
                if (!$entries) {
                    break;
                }
                $pick = $entries[random_int(0, count($entries) - 1)];
                $fileOk = fn ($e) => $e['type'] === 'f' && ($e['size'] ?? 0) >= 1024 && ($e['size'] ?? PHP_INT_MAX) <= $per;
                $candidates = array_values(array_filter($entries, $fileOk));
                if ($candidates && ($depth > 0 || $pick['type'] === 'f')) {
                    $f = $candidates[random_int(0, count($candidates) - 1)];
                    if (!in_array($f['obj'], array_column($files, 1), true)) {
                        $files[] = ["$rel/{$f['name']}", $f['obj'], $f['size']];
                    }
                    break;
                }
                $dirs = array_values(array_filter($entries, fn ($e) => $e['type'] === 'd'));
                if (!$dirs) {
                    break;
                }
                $d = $dirs[random_int(0, count($dirs) - 1)];
                $obj = $d['obj'];
                $rel .= "/{$d['name']}";
            }
        }
    }
    foreach ($files as [$rel, $obj, $size]) {
        if (!empty($GLOBALS['rsStop']) || time() >= $env['kopia_until']) {
            break;
        }
        // what Kopia may still download, less what is kept for the dumps it plays later
        $avail = $env['kopia_left'] - ($env['kopia_reserved'] ?? 0);
        if ($avail <= 0 || ($size !== null && $size > $avail)) {
            continue;
        }
        $ctx = hash_init('sha256');
        $got = 0;
        [$exit, , $err] = drillExec($j, drillKopiaCmd($k['container'], $env['uid'], $j['id'], ['show', $obj]), max(30, $env['kopia_until'] - time()),
            $size !== null ? $size + 1 : $avail, function (string $data) use ($ctx, &$got): void {
                hash_update($ctx, $data);
                $got += strlen($data);
            });
        $env['kopia_left'] -= $got;
        $res['bytes'] += $got;
        if ($exit !== 0 || ($size !== null && $got !== $size)) {
            $res['error'] = true;
            rsLog($j['id'], "  kopia show $rel: exit $exit, $got bytes" . (trim($err) !== '' ? ': ' . drillCut($err) : ''));
            continue;
        }
        $res['files']++;
        $sum = hash_final($ctx);
        // the same file in the local snapshot it was read from (<share>/<path> — a share split over its parts: <share>/<base>/<path>)
        $local = $snap['run'] !== null ? drillKopiaLocal($rel, (string) $snap['run'], $env['ctx']) : null;
        if (!$local || $local['state'] === 'missing') {
            $res['missing']++;
            continue;
        }
        if ($local['state'] === 'asleep') {
            $res['asleep']++;
            continue;
        }
        $res['compared']++;
        if (!hash_equals($sum, (string) hash_file('sha256', $local['path']))) {
            $res['differs'] = $rel;
            rsLog($j['id'], "  $rel: Kopia's copy differs from the local snapshot ({$local['snap']})");
            break;
        }
    }
    return $res;
}

/**
 * A file of a Kopia source in the local snapshot of a run: <share>/<path> — a share the engine mounted split over its
 * parts is <share>/<base>/<path> in Kopia (tried first when the second part names a pool or disk; never a look
 * through /mnt/user: it could wake a disk)
 */
function drillKopiaLocal(string $rel, string $run, array &$ctx): ?array
{
    $parts = explode('/', $rel);
    $share = array_shift($parts);
    if ($share === null || $share === '' || !$parts) {
        return null;
    }
    if (count($parts) > 1 && isset($ctx['fs'][$parts[0]])) {
        $l = drillLocal("/mnt/{$parts[0]}/$share/" . implode('/', array_slice($parts, 1)), $run, $ctx);
        if ($l['state'] !== 'missing') {
            return $l;
        }
    }
    return drillLocal("/mnt/user/$share/" . implode('/', $parts), $run, $ctx);
}

// --------------------------------------------------------------------- what the drill made, and cleaning up

/** Written down BEFORE it is created: in the journal (the sweeper) and in record.json (the night watchman) */
function drillMade(array &$j, array $what): void
{
    $j['made'][] = $what + ['t' => time(), 'gone' => false];
    drillJournalWrite($j);
    if (($what['kind'] ?? '') === 'container') {
        drillRecord(['t' => time(), 'kind' => 'container', 'name' => (string) $what['name'], 'image' => (string) ($what['image'] ?? ''), 'id' => $j['id']]);
    }
}

/** data/restore-drill/record.json: what drills created (root only, the newest 50 within 7 days) */
function drillRecord(array $entry): void
{
    $file = drillData() . '/record.json';
    $list = (array) ((readJson($file) ?? [])['made'] ?? []);
    $list = array_values(array_filter($list, fn ($e) => is_array($e) && is_int($e['t'] ?? null) && $e['t'] > time() - DRILL_RECORD_DAYS * 86400));
    $list[] = $entry;
    writeAtomic($file, jsonEncode(['made' => array_slice($list, -DRILL_RECORD_KEEP)]), 0600, 0, 0);
}

/** The drill's own throwaways, Kopia's temporary folder and a dump from Kopia in RAM gone — whatever happened ($only: these names) */
function drillCleanup(array &$j, ?array $env, bool $public = true, bool $fast = false, ?array $only = null): void
{
    foreach ($j['made'] as $n => $x) {
        if (!empty($x['gone']) || ($fast && !in_array($x['kind'] ?? '', ['container', 'kopia_dump'], true))
            || ($only !== null && !in_array((string) ($x['name'] ?? ''), $only, true))) {
            continue;
        }
        if ($x['kind'] === 'container' && preg_match(DRILL_CONTAINER, (string) $x['name'], $m) && $m[1] === $j['id']) {
            $gone = drillRemoveContainer((string) $x['name'], $j['id']);
        } elseif ($x['kind'] === 'kopia_dump' && drillRamFileOk((string) $x['name'], $j['id'])) {
            clearstatcache(true, (string) $x['name']);
            $gone = !file_exists((string) $x['name']) || @unlink((string) $x['name']);
        } elseif ($x['kind'] === 'kopia_tmp' && $x['name'] === "/tmp/uso-drill-{$j['id']}" && preg_match('/^[\w.-]+$/D', (string) ($x['container'] ?? ''))) {
            if (!empty($GLOBALS['rsStop'])) {
                run(drillCmd(['docker', 'exec', (string) $x['container'], 'pkill', '-INT', '-f', "uso-drill-{$j['id']}"]), 15);
            }
            [$exit] = run(drillCmd(['docker', 'exec', '-u', (string) (int) ($x['uid'] ?? 0), (string) $x['container'], 'rm', '-rf', '--', (string) $x['name']]), 60);
            $gone = $exit === 0;
        } else {
            continue;
        }
        $j['made'][$n]['gone'] = $gone;
    }
    try {
        drillJournalWrite($j, $public);
    } catch (Throwable) {
    }
}

/** A dump from Kopia in the drill's RAM folder — exactly that drill's name there, a plain file (never through a link) */
function drillRamFileOk(string $path, string $id): bool
{
    return (bool) preg_match('#^' . preg_quote(drillRamDir(), '#') . '/' . preg_quote($id, '#') . '-\d{1,3}-[\w.@-]{1,120}$#D', $path)
        && !is_link($path) && !is_link(drillRamDir());
}

/** A throwaway removed — only one that is exactly ours: its name and its label name the same drill */
function drillRemoveContainer(string $name, string $id): bool
{
    if (!preg_match(DRILL_CONTAINER, $name, $m) || $m[1] !== $id) {
        return false;
    }
    [$exit, $out] = run(drillCmd(['docker', 'inspect', '--format', '{{index .Config.Labels "' . DRILL_LABEL . '"}}', $name]), 20);
    if ($exit !== 0) {
        return true;                         // not there (any more)
    }
    if (trim($out) !== $id) {
        return false;                        // not ours: never touched
    }
    return run(drillCmd(['docker', 'rm', '-f', '-v', $name]), 60)[0] === 0;
}

/**
 * What the sweeper finds (its one look, also Ms. Dustdevil's): journals of drills whose job is gone (a crash, a
 * reboot) — with what they made and is not gone yet —, and with $deep every container with the drill's label whose
 * name, label and id pattern all say it is a drill's (never the one going on now, never anything else). Cheap without
 * $deep: the journal folder only (Docker asked only when a journal was open).
 *
 * @return array{journals: array<string, array{j: array, live: bool}>, orphans: array<string, string>}  orphans: name => drill id
 */
function drillSweepFind(?string $current = null, bool $deep = false): array
{
    $out = ['journals' => [], 'orphans' => []];
    foreach (array_slice(array_values(array_filter(@scandir(drillData(), SCANDIR_SORT_DESCENDING) ?: [], fn ($n) => (bool) preg_match(DRILL_ID_PATTERN, $n))), 0, 60) as $id) {
        if ($id === $current) {
            continue;
        }
        $j = drillJournal($id);
        if (!$j) {
            continue;
        }
        $live = in_array($j['result'] ?? '', ['queued', 'running'], true);
        if ($live && (($j['result'] === 'running' && rsJobAlive($j)) || ($j['result'] === 'queued' && time() - (int) ($j['created'] ?? 0) < 120))) {
            continue;                        // going on right now
        }
        $left = array_filter((array) ($j['made'] ?? []), fn ($x) => is_array($x) && empty($x['gone']));
        if ($live || $left) {
            $out['journals'][$id] = ['j' => $j, 'live' => $live];
        }
    }
    if ($deep || $out['journals']) {
        $running = drillRunning();
        [$exit, $out2] = run(drillCmd(['docker', 'ps', '-a', '--filter', 'label=' . DRILL_LABEL, '--format', '{{.Names}}']), 20);
        foreach ($exit === 0 ? array_filter(explode("\n", trim($out2))) : [] as $name) {
            if (preg_match(DRILL_CONTAINER, $name, $m) && $m[1] !== $current && $m[1] !== ($running['id'] ?? null)) {
                $out['orphans'][$name] = $m[1];
            }
        }
    }
    return $out;
}

/**
 * The sweeper: journals of drills whose job is gone (a crash, a reboot) — what they made goes, the journal says
 * «interrupted»; with $deep also every container with the drill's label whose name, label and id pattern all say
 * it is a drill's (never the one going on now, never anything else). Cheap without $deep: the journal folder only.
 * $only: only these of drillLeftovers()' ids (Ms. Dustdevil's «remove») — the same look, the same removal; then the
 * ids removed are returned (else the containers' names).
 */
function drillSweep(?string $current = null, bool $deep = false, ?array $only = null): array
{
    $removed = [];
    $found = drillSweepFind($current, $deep || $only !== null);
    foreach ($found['journals'] as $id => ['j' => $j, 'live' => $live]) {
        $names = $only === null ? null : array_map(fn ($x) => substr($x, strlen("drill:$id:")), array_filter($only, fn ($x) => str_starts_with((string) $x, "drill:$id:")));
        if ($names === []) {
            continue;
        }
        drillCleanup($j, null, false, false, $names);
        foreach ($j['made'] as $x) {
            if (!empty($x['gone']) && $names === null && ($x['kind'] ?? '') === 'container') {
                $removed[] = (string) $x['name'];
            } elseif (!empty($x['gone']) && $names !== null && in_array((string) ($x['name'] ?? ''), $names, true)) {
                $removed[] = "drill:$id:" . (string) $x['name'];
            }
        }
        if ($live) {
            $j['result'] = 'interrupted';
            $j['finished'] = $j['heartbeat'] ?? time();
            try {
                drillJournalWrite($j, false);
            } catch (Throwable) {
            }
            drillCertHistory($j);
        } elseif ($names !== null) {
            try {
                drillJournalWrite($j, false);          // what Ms. Dustdevil removed: gone in its journal too
            } catch (Throwable) {
            }
        }
    }
    foreach ($found['orphans'] as $name => $id) {
        if ($only === null && drillRemoveContainer($name, $id)) {
            $removed[] = $name;
        } elseif ($only !== null && in_array("drill:$id:$name", $only, true) && drillRemoveContainer($name, $id)) {
            $removed[] = "drill:$id:$name";
        }
    }
    $removed = array_values(array_unique($removed));
    if ($removed) {
        logLine('Mr. Restori: the drill\'s sweeper removed ' . implode(', ', $removed));
    }
    return $removed;
}

/**
 * What crashed drills left (Ms. Dustdevil's «What a drill left» — the sweeper's own look): what their journals name
 * and is still there — the throwaway containers, Kopia's temporary folder in its container, a dump from Kopia in RAM —
 * and containers no journal names whose name, label and id pattern say a drill's AND the drill's record names them
 * (what the night watchman trusts — a label alone anyone can set). Ids for drillSweep()'s $only: drill:<drill>:<name>.
 *
 * @return list<array{id: string, drill: string, what: string, name: string, container: ?string, t: ?int}>
 */
function drillLeftovers(): array
{
    $found = drillSweepFind(drillRunning()['id'] ?? null, true);
    $named = array_column(array_filter((array) ((readJson(drillData() . '/record.json') ?? [])['made'] ?? []), fn ($r) => is_array($r) && is_string($r['name'] ?? null)), 'id', 'name');
    $out = [];
    foreach ($found['journals'] as $id => ['j' => $j]) {
        foreach ((array) ($j['made'] ?? []) as $x) {
            if (!is_array($x) || !empty($x['gone']) || !in_array($x['kind'] ?? '', ['container', 'kopia_tmp', 'kopia_dump'], true) || !is_string($x['name'] ?? null)) {
                continue;
            }
            if ($x['kind'] === 'container' && !isset($found['orphans'][$x['name']])) {
                continue;                    // not there (any more), or not exactly the drill's
            }
            if ($x['kind'] === 'kopia_dump' && (!drillRamFileOk($x['name'], $id) || !is_file($x['name']))) {
                continue;
            }
            $out[] = ['id' => "drill:$id:{$x['name']}", 'drill' => $id, 'what' => $x['kind'], 'name' => $x['name'],
                      'container' => $x['kind'] === 'kopia_tmp' ? (string) ($x['container'] ?? '') : null, 't' => is_int($x['t'] ?? null) ? $x['t'] : null];
        }
    }
    $listed = array_column($out, 'id');
    foreach ($found['orphans'] as $name => $id) {
        if (!in_array("drill:$id:$name", $listed, true) && ($named[$name] ?? null) === $id) {
            $out[] = ['id' => "drill:$id:$name", 'drill' => $id, 'what' => 'container', 'name' => $name, 'container' => null, 't' => null];
        }
    }
    return $out;
}

// ===================================================================== the certificate

function drillCertificate(): ?array
{
    $c = readJson(drillCertFile());
    return $c && ($c['interface'] ?? 0) === 1 ? $c : null;
}

/**
 * The certificate a drill builds on: this version's (interface 1) as it stands — keys and history rows it doesn't know
 * carried through as they are —, else a fresh one. A certificate of another interface (a newer office's, met after a
 * downgrade) isn't this version's to read, but nothing of it is dropped (CLAUDE.md «Updates»): its history rows go on
 * in the fresh one as they are, and the whole file is kept aside (officeMigrateAside(): <file>.before-<version>).
 */
function drillCertBase(): array
{
    $fresh = ['interface' => 1, 'last' => null, 'last_passed' => null, 'items' => [], 'lose' => [], 'history' => []];
    $cert = drillCertificate();
    if ($cert !== null) {
        return $cert;
    }
    $other = readJson(drillCertFile());
    if (is_array($other) && isset($other['interface'])) {
        $hist = $other['history'] ?? null;
        $fresh['history'] = is_array($hist) && array_is_list($hist) ? array_values(array_filter($hist, 'is_array')) : [];
        officeMigrateAside(drillCertFile(), AGENT_VERSION);
    }
    return $fresh;
}

/** The certificate's items from a drill's steps: per step what was proven, from which copy, how it went */
function drillCertItems(array $j): array
{
    $items = [];
    foreach ((array) $j['steps'] as $s) {
        if (!in_array($s['state'] ?? '', DRILL_RESULTS, true)) {
            continue;
        }
        $items[] = ['kind' => $s['do'], 'of' => $s['kind'], 'id' => $s['id'], 'name' => $s['name'],
                    'what' => (string) ($s['container'] ?? $s['target'] ?? $s['source'] ?? (isset($s['file']) ? basename((string) $s['file']) : '')),
                    'level' => (int) ($s['level'] ?? 0), 'copy' => (string) ($s['copy'] ?? ''), 'run' => (string) ($s['run'] ?? ''),
                    'state_time' => isset($s['state_time']) ? (int) $s['state_time'] : null, 'result' => $s['state'], 'code' => (string) ($s['code'] ?? ''),
                    'params' => (array) ($s['params'] ?? []), 'seconds' => (int) ($s['seconds'] ?? 0), 'at' => (int) ($s['finished'] ?? 0)];
    }
    return $items;
}

/**
 * «What you would lose» per app and VM: the newest state proven locally (its dumps' run, else its package's) and in
 * Kopia (its own source, else the backup place's share that carries its package)
 */
function drillCertLose(array $items, string $placeShare): array
{
    $out = [];
    foreach ($items as $it) {
        if (!in_array($it['of'], ['app', 'vm'], true)) {
            continue;
        }
        $key = "{$it['of']}:{$it['id']}";
        $out[$key] ??= ['kind' => $it['of'], 'id' => $it['id'], 'name' => $it['name'], 'local' => null, 'kopia' => null, 'best' => 0, 'played' => null,
                        'kopia_played' => null];
        $ok = in_array($it['result'], ['ok', 'warning'], true);
        $fromKopia = ($it['copy'] ?? '') === 'kopia';
        if ($ok && !$fromKopia && in_array($it['kind'], ['dump', 'sqlite', 'package', 'vmdisk'], true) && $it['state_time']) {
            // the oldest part sets what comes back: a dump kept from an earlier night is older than the package
            $out[$key]['local'] = $out[$key]['local'] === null ? $it['state_time'] : min($out[$key]['local'], $it['state_time']);
        }
        if ($ok && $it['kind'] === 'kopia' && $it['state_time']) {
            $out[$key]['kopia'] = $it['state_time'];
        }
        if ($ok && !$fromKopia && $it['kind'] === 'dump') {
            $out[$key]['played'] = (int) ($out[$key]['played'] ?? 0) + (int) ($it['params']['seconds'] ?? $it['seconds']);
        }
        // a database played back from Kopia alone (L2): the state it came back with
        if ($ok && $fromKopia && $it['kind'] === 'dump' && $it['level'] >= 2 && $it['state_time']) {
            $out[$key]['kopia_played'] = max((int) $out[$key]['kopia_played'], $it['state_time']);
        }
        if ($ok) {
            $out[$key]['best'] = max($out[$key]['best'], $it['level']);
        }
    }
    // the package goes to Kopia with the backup place's share
    $share = array_values(array_filter($items, fn ($it) => $it['kind'] === 'kopia' && $it['of'] === 'share' && $it['id'] === $placeShare
        && in_array($it['result'], ['ok', 'warning'], true)))[0] ?? null;
    foreach ($out as $k => $v) {
        if ($v['kopia'] === null && $share) {
            $out[$k]['kopia'] = $share['state_time'];
        }
    }
    return array_values($out);
}

/** The newest drills in the certificate's history (also a refused or interrupted one) */
function drillCertHistory(array $j, ?array $cert = null): array
{
    $cert ??= drillCertBase();
    $row = ['id' => $j['id'], 'scope' => (string) ($j['scope'] ?? ''), 'started' => $j['started'] ?? null, 'ended' => $j['finished'] ?? null,
            'result' => (string) $j['result'], 'reason' => $j['reason'] ?? null] + drillCounts((array) $j['steps']);
    $hist = array_values(array_filter((array) ($cert['history'] ?? []), fn ($h) => is_array($h) && ($h['id'] ?? '') !== $j['id']));
    array_unshift($hist, $row);
    $cert['history'] = array_slice($hist, 0, DRILL_KEEP);
    $cert['updated'] = time();
    try {
        writeAtomic(drillCertFile(), jsonEncode($cert));
    } catch (Throwable) {
    }
    return $cert;
}

function drillCounts(array $steps): array
{
    $c = ['proven' => 0, 'warnings' => 0, 'failed' => 0, 'not_checked' => 0, 'asleep' => 0];
    foreach ($steps as $s) {
        match ($s['state'] ?? '') {
            'ok'          => $c['proven']++,
            'warning'     => $c['warnings']++,
            'failed'      => $c['failed']++,
            'not_checked' => $c['not_checked']++,
            'asleep'      => $c['asleep']++,
            default       => null,
        };
    }
    return $c;
}

/**
 * The certificate after a drill: a drill that ran to its end (passed: nothing failed — warnings and «not checked»
 * are said, never hidden; or failed) replaces the items; an aborted one (array stop, deadline, stopped) only joins
 * the history — what was proven before stays.
 */
function drillCertWrite(array $j, ?array $env): array
{
    $cert = drillCertBase();
    if (in_array($j['result'], ['passed', 'failed'], true)) {
        $items = drillCertItems($j);
        $cert['last'] = ['id' => $j['id'], 'started' => $j['started'], 'ended' => $j['finished'], 'result' => $j['result'], 'scope' => (string) $j['scope'],
                         'egress' => (int) ($j['egress'] ?? 0), 'items' => count($items)] + drillCounts((array) $j['steps']);
        if ($j['result'] === 'passed') {
            $cert['last_passed'] = $j['finished'];
        }
        $cert['items'] = $items;
        $cert['lose'] = drillCertLose($items, (string) ($env['place']['share'] ?? ''));
    }
    return drillCertHistory($j, $cert);
}

/** A drill that failed: one warning, naming what failed (codes and names only, never row data) */
function drillNotify(array $j): void
{
    $lang = officeNotifyLang();
    $failed = array_values(array_filter((array) $j['steps'], fn ($s) => ($s['state'] ?? '') === 'failed'));
    $names = array_values(array_unique(array_map(fn ($s) => (string) $s['name'], $failed)));
    $lines = [];
    foreach (array_slice($failed, 0, 8) as $s) {
        $key = 'drill.code.' . (string) ($s['code'] ?? 'drill_error');
        $lines[] = $s['name'] . ': ' . (officeNotifyText('restore', $key, drillTextParams((array) ($s['params'] ?? [])), $lang) ?: (string) ($s['code'] ?? ''));
    }
    officeNotify(officeNotifyText('restore', 'notify.drill_failed', ['n' => count($failed)], $lang),
        officeNotifyText('restore', 'notify.drill_failed_text', ['names' => implode(', ', array_slice($names, 0, 6)), 'n' => count($names)], $lang),
        'warning', implode("\n", $lines), officeNotifyLink('restore/drill'));
}

/** Params as texts take them: scalars only */
function drillTextParams(array $p): array
{
    return array_filter($p, 'is_scalar');
}

// ===================================================================== on its own: the night after the backup

/**
 * Is a drill due now? After a nightly backup run that ended ok or with warnings (last-run.json, within 6 h), inside
 * the window (00:00–07:00), monthly (none passed or failed this month yet) or weekly (none in 7 days), once packages
 * exist for 7 days — and only one try per backup run (a drill refused or ended early waits for the next one).
 * Returns the scope or null.
 */
function drillDue(array $set, ?array $lastRun, ?array $cert, ?array $auto, ?int $since, int $now): ?string
{
    if ($set['schedule'] === 'off' || !$lastRun || ($lastRun['mode'] ?? '') !== 'backup' || !in_array($lastRun['result'] ?? '', ['ok', 'warnings'], true)) {
        return null;
    }
    $finished = (int) ($lastRun['finished'] ?? 0);
    if ($finished <= 0 || $now - $finished > DRILL_AFTER_RUN || $now < $finished) {
        return null;
    }
    $hour = (int) date('G', $now);
    if ($hour < DRILL_WINDOW[0] || $hour >= DRILL_WINDOW[1]) {
        return null;
    }
    if ($since === null || $now - $since < DRILL_AUTO_DAYS * 86400) {
        return null;
    }
    if (($auto['run'] ?? null) === ($lastRun['run'] ?? '')) {
        return null;                         // tried after this run already
    }
    $last = (int) ($cert['last']['started'] ?? 0);
    if ($set['schedule'] === 'monthly' && $last >= (int) mktime(0, 0, 0, (int) date('n', $now), 1, (int) date('Y', $now))) {
        return null;
    }
    if ($set['schedule'] === 'weekly' && $now - $last < 7 * 86400 - 6 * 3600) {
        return null;
    }
    return $set['schedule'];
}

/** His tick: cheap — once a minute whether a drill is due, once an hour the sweeper's look at the journal folder */
function drillTick(): void
{
    static $looked = 0, $swept = 0;
    $now = time();
    if ($now - $looked < DRILL_TICK_EVERY) {
        return;
    }
    $looked = $now;
    if ($now - $swept >= DRILL_SWEEP_EVERY) {
        $swept = $now;
        try {
            drillSweep(drillRunning()['id'] ?? null, false);
        } catch (Throwable $e) {
            logLine('Mr. Restori: the drill\'s sweeper: ' . $e->getMessage());
        }
    }
    // outside the window nothing is read at all; inside it a few small files, once a minute
    $hour = (int) date('G', $now);
    if ($hour < DRILL_WINDOW[0] || $hour >= DRILL_WINDOW[1]) {
        return;
    }
    $lastRun = readJson(rsUbData() . '/state/last-run.json');
    $auto = readJson(drillData() . '/auto.json');
    $scope = drillDue(drillSettings(), $lastRun, drillCertificate(), $auto, drillPackagesSince(), $now);
    if ($scope === null || !in_array('restore', staffHired(), true)) {
        return;                              // not due — or he isn't hired (then he does nothing on his own)
    }
    $note = ['run' => (string) ($lastRun['run'] ?? ''), 'at' => $now, 'id' => null, 'refused' => null];
    try {
        $plan = drillPlanBuild($scope);
        $plan['stamp'] = date('Ymd-His', $now);
        if ($plan['blockers']) {
            $note['refused'] = $plan['blockers'][0]['key'];
            // busy or a parity check: tried again after the next run; too close to the next backup: the same
        } else {
            $note['id'] = drillLaunch($plan);
        }
    } catch (Throwable $e) {
        $note['refused'] = $e instanceof Problem ? $e->key : 'drill_error';
        logLine('Mr. Restori: the drill could not start: ' . $e->getMessage());
    }
    try {
        rsPrivateDir(drillData());
        writeAtomic(drillData() . '/auto.json', jsonEncode($note), 0600, 0, 0);
    } catch (Throwable) {
    }
}

// ===================================================================== the Team Lead, the tile, Mr. Backupsy, Prometheus

/**
 * The Team Lead: a passed drill within 60 days (only once packages exist for 30 days) — and a failed drill not
 * passed since (its failing items in the params: noting it with «I know, thanks» lasts until they change)
 */
function drillChecks(?array $cert = null, ?int $since = null, ?int $now = null): array
{
    $cert ??= drillCertificate();
    $since ??= drillPackagesSince();
    $now ??= time();
    $out = [];
    if ($since !== null && $now - $since >= DRILL_CHECK_DAYS * 86400) {
        $passed = (int) ($cert['last_passed'] ?? 0);
        $out[] = finding('drill', 'recommended', $passed > 0 && $now - $passed <= DRILL_OVERDUE_DAYS * 86400,
            ['days' => $passed > 0 ? intdiv($now - $passed, 86400) : 0, 'never' => $passed === 0 ? 1 : 0], '#/restore/drill');
    }
    if (is_array($cert['last'] ?? null)) {
        $failed = ($cert['last']['result'] ?? '') === 'failed';
        $names = array_values(array_unique(array_map(fn ($it) => (string) $it['name'],
            array_filter((array) ($cert['items'] ?? []), fn ($it) => is_array($it) && ($it['result'] ?? '') === 'failed'))));
        $out[] = finding('drill_failed', 'recommended', !$failed, ['items' => implode(', ', array_slice($names, 0, 6))], '#/restore/drill');
    }
    return $out;
}

/** His numbers for Prometheus — from the certificate only */
function drillMetrics(): array
{
    $cert = metricsCached(drillCertFile(), fn () => drillCertificate());
    if (!$cert) {
        return [];
    }
    $by = array_fill_keys(DRILL_RESULTS, 0);
    foreach ((array) ($cert['items'] ?? []) as $it) {
        if (is_array($it) && isset($by[$it['result'] ?? ''])) {
            $by[$it['result']]++;
        }
    }
    return [
        metricsGauge('uso_restore_drill_last_timestamp_seconds', 'When the last complete restore drill ended', (int) ($cert['last']['ended'] ?? 0)),
        metricsGauge('uso_restore_drill_last_passed_timestamp_seconds', 'When the last restore drill that passed ended', (int) ($cert['last_passed'] ?? 0)),
        metricsGauge('uso_restore_drill_items', 'Items of the last restore drill by result', array_map(fn ($r, $n) => [['result' => $r], $n], array_keys($by), $by)),
        metricsGauge('uso_restore_drill_downloaded_bytes', 'Bytes the last restore drill read back from Kopia', (int) ($cert['last']['egress'] ?? 0)),
    ];
}
