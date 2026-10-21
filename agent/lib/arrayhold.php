<?php
declare(strict_types=1);

/*
 * «What holds the array?» — the Night Watchman's look at what would hold up stopping the array or shutting the server
 * down, right now (owner, 2026-10-20; the model: dlandon's Open Files plugin). Read only: nothing stopped, killed or
 * changed; no disk wakes up — /proc's links are read (readlink), never a file under /mnt opened or stat'ed.
 *
 * What Unraid does when the array stops (7.3.3, read on a test server; agent/lib/paritywhy.php has the shutdown's side):
 *   emhttpd «Stopping services…»: Samba and NFS stopped (their clients' open files closed), «Stopping mover…»
 *            (/usr/local/sbin/mover stop), rc.docker stop, rc.libvirt stop; then umount of every disk, pool and /mnt/user
 *            — a program with a file or its working folder there makes it «Retry unmounting disk share(s)…» until the
 *            shutdown time-out runs out (Disk Settings «Shutdown time-out», var.ini shutdownTimeout, empty = 90 s):
 *            «Forcing shutdown…», an unclean shutdown, a parity check at the next start.
 *   rc.docker  `docker stop --time=DOCKER_TIMEOUT` for Unraid's own containers (label net.unraid.docker.managed) all at
 *            once — a container's own stop time (StopTimeout) does not count for those; the others (Compose …) stop with
 *            dockerd, each with its own stop time (Docker's default 10 s), and Unraid waits up to 30 s for dockerd.
 *   rc.libvirt `virsh shutdown` for every running VM, up to domain.cfg TIMEOUT (empty = 60 s), then «Forced shutting down
 *            VM». libvirt asks the guest agent first when the VM has its channel (org.qemu.guest_agent.0) and the agent
 *            answers (state='connected' in the running domain's XML), else ACPI — which a Windows VM without the agent
 *            often ignores at first: Unraid then waits the whole time-out.
 *   loop     a disk image mounted from the array (an ISO, an img) keeps its file open: rc.local_shutdown logs `losetup -a`
 *            when the time-out ran out. Unraid's own (docker.img on /var/lib/docker, libvirt.img on /etc/libvirt) it
 *            unmounts itself.
 * Not the array: /mnt/remotes (SMB/NFS shares of other machines), /mnt/disks and /mnt/rootshare (Unassigned Devices) —
 * they never hold it; a remote that doesn't answer only makes everything slower. /mnt/addons is the office's own (RAM).
 *
 * Who a process is comes from Ms. Protocolli's disk-wake capture (agent/desks/logs-diskwakes.php: logsWakeWho(),
 * logsWakeKind(), logsWakeStat(), logsWakeSmb()) — called, never copied; what only this look needs (a shell in an SSH
 * session or Unraid's web terminal, a User Script, a plugin's script) lies here.
 *
 * arrayholdLook() runs in a job of its own (`php agent.php job watchman-hold`, started from his page), capped by time and
 * count (`cut`: looked in part); its answer lies in RAM (RUN_DIR/watchman-hold.json) and goes into his page's state.
 */

require_once __DIR__ . '/paritywhy.php';
require_once __DIR__ . '/../desks/logs-diskwakes.php';

const ARRAYHOLD_BUDGET_MS = 1500;       // the walk through /proc stops after this many milliseconds (`cut`)
const ARRAYHOLD_PIDS      = 20000;      // … or this many processes
const ARRAYHOLD_FDS       = 200000;     // … or this many open files read
const ARRAYHOLD_LIST      = 20;         // things kept per list
const ARRAYHOLD_FILES     = 5;          // files kept per thing
const ARRAYHOLD_DOCKERD   = 30;         // rc.docker waits this long for dockerd (and the containers it stops itself)
const ARRAYHOLD_CT_OWN    = 10;         // Docker's default stop time of a container
const ARRAYHOLD_UP        = 16;         // processes above one looked at, at most
const ARRAYHOLD_FILE      = RUN_DIR . '/watchman-hold.json';
// /mnt's top folders that are not the array: other machines' shares, Unassigned Devices, the office's own (RAM)
const ARRAYHOLD_OUTSIDE   = ['remotes' => 'remote', 'disks' => 'ud', 'rootshare' => 'ud'];
const ARRAYHOLD_UNRAIDS   = ['/var/lib/docker', '/etc/libvirt'];           // loop mounts Unraid unmounts itself
const ARRAYHOLD_SHELLS    = ['sshd' => 'ssh', 'sshd-session' => 'ssh', 'ttyd' => 'web', 'tmux: server' => 'tmux', 'screen' => 'screen', 'SCREEN' => 'screen'];

/** Where it reads — fixed; the tests hand in copies */
function arrayholdPaths(): array
{
    return [
        'proc'        => '/proc',
        'mounts'      => '/proc/self/mounts',
        'sys_block'   => '/sys/block',
        'qemu_run'    => '/var/run/libvirt/qemu',            // a running domain's XML (with its channels' state)
        'var_ini'     => '/var/local/emhttp/var.ini',
        'domain_cfg'  => '/boot/config/domain.cfg',
        'docker_cfg'  => '/boot/config/docker.cfg',
        'docker'      => 'docker',
        'docker_sock' => '/var/run/docker.sock',
        'mover_pid'   => '/var/run/mover.pid',
    ];
}

/**
 * Where a path lies: 'array' (a disk, a pool, /mnt/user, /mnt/user0), 'remote' / 'ud' (not the array) or null (not
 * under /mnt, or the office's own /mnt/addons)
 */
function arrayholdWhere(string $path): ?string
{
    if (!preg_match('#^/mnt/([^/]+)#', $path, $m) || $m[1] === 'addons') {
        return null;
    }
    return ARRAYHOLD_OUTSIDE[$m[1]] ?? 'array';
}

/**
 * The look. $o: 'now' (the tests' clock), 'smb' (smbd pid => machine; else smbstatus), 'self' (a pid left out besides
 * this process).
 *
 * @return array{v: int, time: int, ms: int, cut: bool, started: bool, count: int, holds: list<array>, vms: list<array>,
 *               containers: ?list<array>, itself: list<array>, outside: list<array>, times: array}
 */
function arrayholdLook(array $paths, array $o = []): array
{
    $t0 = hrtime(true);
    $now = (int) ($o['now'] ?? time());
    $var = readCfg((string) $paths['var_ini']);
    $out = ['v' => 1, 'time' => $now, 'ms' => 0, 'cut' => false, 'started' => ($var['fsState'] ?? '') === 'Started', 'count' => 0,
            'holds' => [], 'vms' => [], 'containers' => null, 'itself' => [], 'outside' => [], 'times' => []];
    if (!$out['started']) {
        return $out;
    }
    $mounts = arrayholdMounts((string) $paths['mounts']);
    $vms = arrayholdVms((string) $paths['qemu_run']);
    $cts = arrayholdContainers($paths);
    $walk = arrayholdWalk((string) $paths['proc'], $o, $t0);
    $names = [];
    foreach ($cts ?? [] as $c) {
        $names[$c['id']] = $c['name'];
    }
    $smb = $walk['smb'] ? ($o['smb'] ?? logsWakeSmb()) : [];

    $holds = [];
    $itself = [];
    foreach ($walk['found'] as $w) {
        $files = array_values(array_filter($w['files'], fn (string $f): bool => arrayholdWhere($f) === 'array'));
        $kind = $w['kind'];
        if ($kind === 'container' || $kind === 'vm') {
            continue;                   // Unraid stops them before it unmounts: their own lists, with how long it waits
        }
        if (in_array($kind, ['office', 'mover', 'smb', 'nfs'], true)) {
            $key = $kind . ':' . ($kind === 'office' ? $w['desk'] : ($kind === 'smb' ? ($smb[(string) $w['pid']] ?? '') : ''));
            $itself[$key] ??= ['kind' => $kind] + ($kind === 'office' ? ['desk' => $w['desk']] : [])
                + ($kind === 'smb' ? ['machine' => $smb[(string) $w['pid']] ?? null] : []) + ['files' => 0];
            $itself[$key]['files'] += count($files);
            continue;
        }
        if (!$files) {
            continue;                   // only the outside (a remote share, Unassigned Devices): never holds the array
        }
        $key = $w['group'];
        if (!isset($holds[$key])) {
            $holds[$key] = array_intersect_key($w, array_flip(['kind', 'name', 'via', 'pid', 'prog'])) + ['files' => [], 'more' => 0, 'procs' => 0];
        }
        $h = &$holds[$key];
        $h['procs']++;
        foreach ($files as $f) {
            if (in_array($f, $h['files'], true)) {
                continue;
            }
            if (count($h['files']) < ARRAYHOLD_FILES) {
                $h['files'][] = $f;
            } else {
                $h['more']++;
            }
        }
        unset($h);
    }
    // the office's own jobs, the mover — running, files open or not: they stop by themselves
    foreach ($walk['office'] as $desk) {
        $itself["office:$desk"] ??= ['kind' => 'office', 'desk' => $desk, 'files' => 0];
    }
    $mover = trim((string) @file_get_contents((string) $paths['mover_pid'], false, null, 0, 32));
    if (ctype_digit($mover) && is_dir((string) $paths['proc'] . "/$mover")) {
        $itself['mover:'] ??= ['kind' => 'mover', 'files' => 0];
    }
    foreach (arrayholdLoops((string) $paths['sys_block'], $mounts) as $l) {
        $holds['loop:' . $l['dev']] = $l;
    }
    foreach ($mounts as [$dev, $point, $type]) {
        $where = arrayholdWhere($point);
        if ($where !== null && $where !== 'array' && substr_count($point, '/') >= 3 && count($out['outside']) < ARRAYHOLD_LIST) {
            $out['outside'][] = ['kind' => $where, 'mount' => mb_strcut($point, 0, LOGS_WAKE_PATH, 'UTF-8'), 'type' => mb_strcut($type, 0, 32, 'UTF-8')];
        }
    }

    // the containers by name; Unraid's own stop with its Docker Stop Timeout, the others with their own (up to dockerd's 30 s)
    $docker = readCfg((string) $paths['docker_cfg']);
    $dt = trim((string) ($docker['DOCKER_TIMEOUT'] ?? ''));
    $given = preg_match('/^\d{1,5}$/D', $dt) && (int) $dt > 0 ? (int) $dt : 10;
    $times = waVmStop(readCfg((string) $paths['domain_cfg']), $var);
    $managed = $others = 0;
    if ($cts !== null) {
        $out['containers'] = [];
        foreach ($cts as $c) {
            $own = $c['own'] ?? ARRAYHOLD_CT_OWN;
            $gets = $c['managed'] ? $given : min($own, ARRAYHOLD_DOCKERD);
            if ($c['managed']) {
                $managed++;
            } else {
                $others = max($others, $gets);
            }
            $out['containers'][] = ['name' => $c['name'], 'managed' => $c['managed'], 'own' => $c['own'], 'gets' => $gets,
                                    'short' => $own > $gets];
        }
    }
    $out['vms'] = $vms;
    $vmWait = $vms ? $times['timeout'] : 0;
    $ctWait = ($managed ? $given : 0) + $others;
    $need = $ctWait + $vmWait + PARITYWHY_MARGIN;
    $out['times'] = ['disk' => $times['disk_timeout'], 'vm' => $times['timeout'], 'docker' => $given, 'containers' => $ctWait, 'vms' => $vmWait,
                     'margin' => PARITYWHY_MARGIN, 'need' => $need, 'fits' => $times['disk_timeout'] >= $need];

    usort($holds, fn (array $a, array $b): int => [count($b['files']) + $b['more'], $a['kind']] <=> [count($a['files']) + $a['more'], $b['kind']]);
    $out['holds'] = array_slice(array_values($holds), 0, ARRAYHOLD_LIST);
    $out['more'] = max(0, count($holds) - ARRAYHOLD_LIST);
    $out['itself'] = array_values($itself);
    $out['cut'] = $walk['cut'];
    // what would hold it up: whatever keeps a file there, and every VM without a guest agent that answers
    $out['count'] = count($holds) + count(array_filter($vms, fn (array $v): bool => $v['agent'] !== 'connected'));
    $out['ms'] = (int) round((hrtime(true) - $t0) / 1e6);
    return $out;
}

/**
 * Every process with a file or its working folder under /mnt (logsWakeWho()), what it is (logsWakeKind(), refined by
 * arrayholdRefine()); the office's running jobs (by their command line); capped by ARRAYHOLD_*.
 *
 * @return array{found: list<array>, office: list<string>, smb: bool, cut: bool}
 */
function arrayholdWalk(string $proc, array $o, int $t0): array
{
    $self = getmypid();
    $skip = [$self, (int) ($o['self'] ?? 0)];
    $found = [];
    $office = [];
    $cut = false;
    $total = 0;
    $ns = [];
    $n = 0;
    foreach (@scandir($proc) ?: [] as $pid) {
        if (!ctype_digit($pid)) {
            continue;
        }
        $pid = (int) $pid;
        if (in_array($pid, $skip, true)) {
            continue;
        }
        if (++$n > ARRAYHOLD_PIDS || $total > ARRAYHOLD_FDS || hrtime(true) - $t0 > ARRAYHOLD_BUDGET_MS * 1e6) {
            $cut = true;
            break;
        }
        $cmd = (string) @file_get_contents("$proc/$pid/cmdline", false, null, 0, 4096);
        if (str_contains($cmd, '/plugins/unraid-secretary-office/')) {
            $k = logsWakeKind($proc, $pid);
            $desk = $k['desk'] ?? '';
            if ($desk !== '' && !in_array($desk, ['watchman', 'logs'], true) && !in_array($desk, $office, true)
                && preg_match('#(?:job\.sh|agent\.php\x00job|/backup/|/smartmover/|/mediacache/|/gather/)#', $cmd)) {
                $office[] = $desk;
            }
        }
        $fds = 0;
        $w = logsWakeWho($proc, $pid, $fds, $ns, true);
        $total += $fds;
        if ($fds > LOGS_WAKE_FDS) {
            $cut = true;                // one process with more open files than one look reads
        }
        if ($w === null || $w['prog'] === 'shfs') {
            continue;                   // nothing under /mnt — or Unraid's own user-share layer
        }
        $found[] = arrayholdRefine($proc, $w);
    }
    return ['found' => $found, 'office' => $office, 'smb' => (bool) array_filter($found, fn (array $w): bool => $w['kind'] === 'smb'), 'cut' => $cut];
}

/**
 * A program or a cron job, closer — by the processes above it: a User Script (its folder under /tmp/user.scripts), a
 * plugin's script (its folder), a shell in an SSH session, Unraid's web terminal, tmux or screen. `group`: what one
 * thing on the page is (a session, a script, a plugin — or the process alone).
 */
function arrayholdRefine(string $proc, array $w): array
{
    $w += ['name' => null, 'via' => null];
    $w['group'] = "{$w['kind']}:{$w['pid']}";
    if ($w['kind'] === 'office' || $w['kind'] === 'mover') {
        $w['group'] = $w['kind'] . ':' . ($w['desk'] ?? '');
        return $w;
    }
    if (!in_array($w['kind'], ['program', 'cron'], true)) {
        return $w;
    }
    $p = (int) $w['pid'];
    for ($i = 0; $i < ARRAYHOLD_UP && $p > 1; $i++) {
        $st = logsWakeStat("$proc/$p/stat");
        if ($st === null) {
            break;
        }
        $line = str_replace("\0", ' ', (string) @file_get_contents("$proc/$p/cmdline", false, null, 0, 4096));
        if (preg_match('#(?:/tmp/user\.scripts/tmpScripts|/boot/config/plugins/user\.scripts/scripts)/([^/\s]{1,100})/#', $line, $m)) {
            return ['kind' => 'userscript', 'name' => $m[1], 'group' => "userscript:{$m[1]}"] + $w;
        }
        if (preg_match('#(?:/usr/local/emhttp/plugins|/boot/config/plugins)/([A-Za-z0-9._-]{1,64})/#', $line, $m) && $m[1] !== 'user.scripts') {
            return ['kind' => 'plugin', 'name' => $m[1], 'group' => "plugin:{$m[1]}"] + $w;
        }
        $via = ARRAYHOLD_SHELLS[$st[0]] ?? (str_starts_with($st[0], 'tmux') ? 'tmux' : null);
        if ($via !== null && $p !== (int) $w['pid']) {
            return ['kind' => 'shell', 'via' => $via, 'group' => "shell:$via:$p"] + $w;
        }
        $p = $st[2];
    }
    if ($w['kind'] === 'cron') {
        $w['group'] = 'cron:' . $w['name'];
    }
    return $w;
}

/** /proc/self/mounts: [device, mount point, type] */
function arrayholdMounts(string $file): array
{
    $out = [];
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $f = explode(' ', $line);
        if (count($f) >= 3) {
            $out[] = [mountUnescape($f[0]), mountUnescape($f[1]), $f[2]];
        }
    }
    return $out;
}

/**
 * Loop devices whose file lies on the array (/sys/block/loopN/loop/backing_file) — an ISO or an image mounted from
 * there holds it; Unraid's own (docker.img, libvirt.img) are left out
 */
function arrayholdLoops(string $sys, array $mounts): array
{
    $out = [];
    foreach (@scandir($sys) ?: [] as $dev) {
        if (!preg_match('/^loop\d{1,4}$/D', $dev)) {
            continue;
        }
        $file = trim(preg_replace('/ \(deleted\)$/', '', (string) @file_get_contents("$sys/$dev/loop/backing_file", false, null, 0, 4096)));
        if ($file === '' || arrayholdWhere($file) !== 'array') {
            continue;
        }
        $at = null;
        foreach ($mounts as [$src, $point]) {
            if ($src === "/dev/$dev") {
                $at = $point;
                break;
            }
        }
        if ($at !== null && in_array($at, ARRAYHOLD_UNRAIDS, true)) {
            continue;
        }
        $out[] = ['kind' => 'loop', 'name' => mb_strcut(basename($file), 0, 200, 'UTF-8'), 'dev' => $dev, 'mount' => $at,
                  'files' => [mb_strcut($file, 0, LOGS_WAKE_PATH, 'UTF-8')], 'more' => 0, 'via' => null, 'pid' => null, 'prog' => null, 'procs' => 0];
    }
    return $out;
}

/**
 * The running VMs from libvirt's own records in RAM (/var/run/libvirt/qemu/<name>.xml: the live domain): its name, its
 * state, the guest agent — 'connected' (it answers), 'disconnected' (the channel is there, no agent answers), 'none' (no
 * channel) — and whether it is a Windows VM (Unraid's template, or Hyper-V's enlightenments)
 *
 * @return list<array{name: string, state: string, agent: string, windows: bool}>
 */
function arrayholdVms(string $dir): array
{
    $out = [];
    foreach (@scandir($dir) ?: [] as $f) {
        if (!str_ends_with($f, '.xml') || count($out) >= ARRAYHOLD_LIST * 5) {
            continue;
        }
        $xml = (string) @file_get_contents("$dir/$f", false, null, 0, 2 * 1024 * 1024);
        if (!preg_match('#<domain\b.*?<name>([^<]{1,200})</name>#s', $xml, $n)) {
            continue;
        }
        $agent = 'none';
        if (preg_match_all('#<target\b([^>]*)/?>#', $xml, $targets)) {
            foreach ($targets[1] as $attrs) {
                if (preg_match('#\bname=([\'"])org\.qemu\.guest_agent\.0\1#', $attrs)) {
                    $agent = preg_match('#\bstate=([\'"])connected\1#', $attrs) ? 'connected' : 'disconnected';
                }
            }
        }
        $state = preg_match('#<domstatus\b[^>]*\bstate=([\'"])(\w{1,20})\1#', $xml, $s) ? $s[2] : 'running';
        $out[] = ['name' => html_entity_decode($n[1], ENT_QUOTES | ENT_XML1, 'UTF-8'), 'state' => $state, 'agent' => $agent,
                  'windows' => (bool) preg_match('#<vmtemplate\b[^>]*\bos=([\'"])windows#i', $xml) || str_contains($xml, '<hyperv')];
    }
    usort($out, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    return $out;
}

/**
 * The running containers: id, name, Unraid's own (its label) or not, its own stop time (StopTimeout; null: Docker's
 * default). Null: Docker isn't running or didn't answer. Only these fields — never a container's environment.
 *
 * @return list<array{id: string, name: string, managed: bool, own: ?int}>|null
 */
function arrayholdContainers(array $paths): ?array
{
    if (!file_exists((string) $paths['docker_sock'])) {
        return null;
    }
    $docker = (string) $paths['docker'];
    [$exit, $text] = run([$docker, 'ps', '--no-trunc', '--format', '{{.ID}}\t{{.Names}}\t{{.Label "net.unraid.docker.managed"}}'], 20);
    if ($exit !== 0) {
        return null;
    }
    $out = [];
    foreach (explode("\n", trim((string) $text)) as $line) {
        $f = explode("\t", $line);
        if (count($f) >= 2 && preg_match('/^[0-9a-f]{12,64}$/D', $f[0]) && count($out) < 500) {
            $out[$f[0]] = ['id' => $f[0], 'name' => mb_strcut($f[1], 0, 200, 'UTF-8'), 'managed' => trim($f[2] ?? '') !== '', 'own' => null];
        }
    }
    if ($out) {
        [$exit, $text] = run(array_merge([$docker, 'inspect', '--format', "{{.Id}}\t{{.Config.StopTimeout}}"], array_keys($out)), 30);
        foreach (explode("\n", trim((string) $text)) as $line) {
            $f = explode("\t", $line);
            if (count($f) === 2 && isset($out[$f[0]]) && preg_match('/^\d{1,6}$/D', $f[1])) {
                $out[$f[0]]['own'] = (int) $f[1];
            }
        }
    }
    $out = array_values($out);
    usort($out, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    return $out;
}

/** His page: the last look (RAM) — only in exactly its shape */
function arrayholdRead(string $file = ARRAYHOLD_FILE): ?array
{
    $d = readJson($file);
    return is_array($d) && ($d['v'] ?? null) === 1 && is_int($d['time'] ?? null) && is_array($d['holds'] ?? null) ? $d : null;
}
