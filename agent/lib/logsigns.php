<?php
declare(strict_types=1);

/*
 * Signs in the log (1.96, the concept «Der Nachtwächter in 1.96», 11.3 H): fixed patterns in the kernel's and the
 * services' own words, each with where that wording comes from — so a reader can check it against the source, and a
 * kernel that words it otherwise is found by a test, not guessed at. Shared: the Night Watchman's syslog pass reads it
 * (watchman-signs.php); Ms. Protocolli may take it later. The desks stay apart: this file only matches a line, it keeps
 * nothing and tells nothing.
 *
 * Sources (checked 2026-11-22 against mainline; nw196-new-4-hardware.md §9): T: = git.kernel.org torvalds/linux.git tree.
 * Left out on purpose: «entered promiscuous mode» (every Docker bridge, VM tap and veth says it — net/core/dev.c), a USB
 * device plugged in (drivers/usb/core/hub.c), an NVMe command that timed out but completed («completion polled»).
 *
 * Each sign: re (one match a line; the groups named), kind (the book's kind it feeds, or a hardware step's evidence:
 * `hw_kernel`, `hw_mce` — B's, not booked here), src (where the wording lives).
 */
const LOGSIGNS = [
    // T:mm/oom_kill.c — «Out of memory: Killed process <pid> (<comm>) total-vm:…»; a cgroup's: «Memory cgroup out of memory: …»
    'oom'          => ['re' => '/(?:Memory cgroup out of memory|Out of memory): Killed process \d{1,10} \((?<thing>[^()\s]{1,64})\)/',
                       'kind' => 'host_oom', 'src' => 'mm/oom_kill.c'],
    // T:arch/x86/mm/fault.c — «<comm>[<pid>]: segfault at <addr> ip … sp … error N in <file>[…]»
    'segfault'     => ['re' => '/(?:^|\s)(?<thing>[A-Za-z0-9_.:\/-]{1,64})\[\d{1,10}\]: segfault at [0-9a-f]+ /',
                       'kind' => 'host_crash', 'src' => 'arch/x86/mm/fault.c'],
    // T:arch/x86/kernel/traps.c — «traps: <comm>[<pid>] general protection fault ip:… sp:… error:0 in …» (user space)
    'gpf'          => ['re' => '/traps: (?<thing>[A-Za-z0-9_.:\/-]{1,64})\[\d{1,10}\] general protection fault /',
                       'kind' => 'host_crash', 'src' => 'arch/x86/kernel/traps.c'],
    // T:block/blk-mq.c, block/blk-core.c — «<what> error, dev <disk>, sector N op …» (I/O, critical medium, timeout …);
    // older kernels put «blk_update_request: » before it
    'io'           => ['re' => '/(?:^|\s)(?:I\/O|critical medium|critical target|critical nexus|critical space allocation|critical protection|recoverable transport|timeout) error, dev (?<thing>[a-z][a-z0-9]{1,15}), sector \d/',
                       'kind' => 'hw_io', 'src' => 'block/blk-mq.c'],
    // T:fs/xfs/xfs_buf.c — «XFS (<dev>): metadata I/O error in "<func>" at daddr …»
    'xfs_io'       => ['re' => '/XFS \((?<thing>[a-z][a-z0-9]{1,15})\): metadata I\/O error /',
                       'kind' => 'hw_io', 'src' => 'fs/xfs/xfs_buf.c'],
    // T:fs/btrfs/messages.c — «BTRFS error (device <dev>): …», «BTRFS critical (device …)», «BTRFS: error (device <dev>) in …»
    'btrfs'        => ['re' => '/BTRFS(?: error| critical|: error) \(device (?<thing>[a-z][a-z0-9]{1,15})(?:\)|[ :])/',
                       'kind' => 'hw_io', 'src' => 'fs/btrfs/messages.c'],
    // T:fs/ext4/super.c — «EXT4-fs error (device <dev>): <func>:<line>: …»
    'ext4'         => ['re' => '/EXT4-fs error \(device (?<thing>[a-z][a-z0-9]{1,15})\)/',
                       'kind' => 'hw_io', 'src' => 'fs/ext4/super.c'],
    // T:drivers/nvme/host/pci.c — «nvme nvme0: I/O tag N (…) opcode 0x.. (…) QID N timeout, aborting|reset controller|disable controller»
    // («…, completion polled»: it came back — no sign)
    'nvme_timeout' => ['re' => '/nvme (?<thing>nvme\d{1,3}): I\/O tag \d+ .{0,120}timeout, (?:aborting|reset controller|disable controller)/',
                       'kind' => 'hw_io', 'src' => 'drivers/nvme/host/pci.c'],
    // T:net/ipv4/tcp_input.c — «TCP: request_sock_TCP: Possible SYN flooding on port <ip>:<port>. Sending cookies.» (IPv6:
    // [addr]:port; older kernels: «… on port <port>. …»)
    'syn_flood'    => ['re' => '/Possible SYN flooding on port (?:\S{1,60}:)?(?<thing>\d{1,5})\. /',
                       'kind' => 'host_flood', 'src' => 'net/ipv4/tcp_input.c'],
    // T:net/netfilter/nf_conntrack_core.c — «nf_conntrack: table full, dropping packet»
    'conntrack'    => ['re' => '/nf_conntrack: table full, dropping packet/',
                       'kind' => 'host_flood', 'src' => 'net/netfilter/nf_conntrack_core.c'],
    // evidence for the hardware step B (`hw_kernel`, `hw_mce`) — matched, not booked by the watchman's pass yet
    'oops'         => ['re' => '/(?:^|\s)Oops: /', 'kind' => 'hw_kernel', 'src' => 'arch/x86/kernel/dumpstack.c'],
    'kernel_bug'   => ['re' => '/kernel BUG at |(?:^|\s)BUG: (?:unable to handle|scheduling while atomic|Bad page)/', 'kind' => 'hw_kernel', 'src' => 'lib/bug.c'],
    'soft_lockup'  => ['re' => '/watchdog: BUG: soft lockup - CPU#\d+ stuck/', 'kind' => 'hw_kernel', 'src' => 'kernel/watchdog.c'],
    'hard_lockup'  => ['re' => '/Watchdog detected hard LOCKUP on cpu/', 'kind' => 'hw_kernel', 'src' => 'kernel/watchdog.c'],
    'rcu_stall'    => ['re' => '/rcu: INFO: rcu_\w{1,16} (?:self-detected stall|detected stalls)/', 'kind' => 'hw_kernel', 'src' => 'kernel/rcu/tree_stall.h'],
    'hw_error'     => ['re' => '/\[Hardware Error\]/', 'kind' => 'hw_mce', 'src' => 'arch/x86/kernel/cpu/mce/core.c'],
];

/** A line worth matching at all: one cheap test before the patterns (most of a syslog is none of these) */
const LOGSIGNS_MAYBE = '/Killed process|segfault at|general protection|error, dev |metadata I\/O error|BTRFS|EXT4-fs error|timeout, |SYN flooding|table full|Oops|BUG|LOCKUP|stall|Hardware Error/';

const LOGSIGNS_LINE_MAX = 200;      // characters of a line kept as evidence

/**
 * One syslog line → the sign it shows, or null: ['sign' => id, 'kind' => …, 'thing' => what it is about (a program, a
 * device, a port; '' when nothing), 'line' => the line as evidence (logsignsClean())]. The first sign that matches wins.
 */
function logsignsMatch(string $line): ?array
{
    if ($line === '' || !preg_match(LOGSIGNS_MAYBE, $line)) {
        return null;
    }
    foreach (LOGSIGNS as $id => $s) {
        if (preg_match($s['re'], $line, $m)) {
            return ['sign' => $id, 'kind' => $s['kind'], 'thing' => (string) ($m['thing'] ?? ''), 'line' => logsignsClean($line)];
        }
    }
    return null;
}

/**
 * The line as evidence: from the message on (the syslog's time and host left out), addresses hidden (‹ip›, ‹mac›),
 * control characters gone, at most LOGSIGNS_LINE_MAX characters
 */
function logsignsClean(string $line): string
{
    $msg = preg_replace('/^(?:[A-Z][a-z]{2} [ \d]\d \d\d:\d\d:\d\d|\d{4}-\d\d-\d\dT[0-9:.+-]{8,40}) \S+ /', '', $line, 1) ?? $line;
    $msg = preg_replace('/\b(?:[0-9a-f]{2}:){5}[0-9a-f]{2}\b/i', '‹mac›', $msg) ?? $msg;
    $msg = preg_replace('/(?<![\w.:])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])/', '‹ip›', $msg) ?? $msg;
    // IPv6 (with «::» or a hex letter — a time like 12:34:56 stays), in brackets or not
    $msg = preg_replace('/(?<![\w:])\[?(?=[0-9a-f:]{0,40}(?:::|[a-f]))[0-9a-f]{0,4}(?::[0-9a-f]{0,4}){2,7}\]?(?![\w])/i', '‹ip›', $msg) ?? $msg;
    $msg = preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $msg) ?? $msg;
    return mb_strlen($msg) > LOGSIGNS_LINE_MAX ? rtrim(mb_substr($msg, 0, LOGSIGNS_LINE_MAX - 1)) . '…' : $msg;
}

/**
 * The host a syslog line names (rsyslog's traditional «Nov 22 10:00:00 Tower kernel: …» or RFC 3339
 * «2026-11-22T10:00:00+01:00 Tower kernel: …»), lower case — null when the line has neither shape
 */
function logsignsHost(string $line): ?string
{
    return preg_match('/^(?:[A-Z][a-z]{2} [ \d]\d \d\d:\d\d:\d\d|\d{4}-\d\d-\d\dT[0-9:.+-]{8,40}) (\S{1,253}) /', $line, $m) ? strtolower($m[1]) : null;
}
