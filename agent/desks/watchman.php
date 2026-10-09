<?php
declare(strict_types=1);

/*
 * The Night Watchman — calm, factual, few words. The office's security
 * department, in two parts: how secure the server stands now (his posture
 * tips, see "how secure" below) and what changed — his watch book, which
 * tells only what is DIFFERENT from normal. Read only.
 *
 * When he is hired, his first round records what is normal now (the
 * baseline) and reports nothing for it. After that a round every WATCH_EVERY
 * seconds — `php agent.php job watchman-round`, a process of its own started
 * from his tick (like Ms. Protocolli's tour), also with the office closed —
 * compares what he sees with what is normal:
 *
 *   logins      Unraid's web logins (syslog "webgui: Successful login user …
 *               from <ip>" / "Unsuccessful login user … from <ip>.") and SSH
 *               ("Accepted …", "Failed …", "Invalid user …"): a login from an
 *               address he has not seen before; a burst of failures
 *               (WATCH_FAIL_BURST from one address within WATCH_FAIL_WINDOW).
 *               The syslog is read by offset, a rotation found by inode — no
 *               line is looked at twice. Names tried in failed logins are kept
 *               only when they are users of this server (somebody may type a
 *               password into the name field).
 *   containers  docker inspect of every container: newly privileged, host
 *               network/PID/IPC, new published ports, new capabilities and
 *               devices, the Docker socket or / mounted. A new container only
 *               counts when it has such rights (ports alone don't).
 *   plugins     /var/log/plugins/*.plg: a new plugin, or its pluginURL now
 *               pointing somewhere else (the host, on code hosts its owner)
 *   flash       /boot/config/go (line hashes; the text only of lines added
 *               since, kept in that entry of the book — nothing else), files in
 *               /boot/extra, the users in /boot/config/passwd, a changed
 *               password (a hash of each user's shadow field — never the field
 *               itself), SSH keys in /boot/config/ssh/<user>/authorized_keys
 *               (/root/.ssh links there on Unraid 7)
 *   shares      emhttp's sec.ini / sec_nfs.ini (RAM: user shares, disks, pools
 *               and the flash, as Unraid applies them) with SMB/NFS switched on
 *               in /boot/config/share.cfg: a share now open to guests (secure =
 *               they read, public = they read and write)
 *   scheduled   what starts on its own as root (see "scheduled and
 *               auto-starting" below): root's own crontab next to Unraid's — new
 *               lines, lines in both (they run twice), the office's own lines
 *               there, with the syslog around the file's time as evidence; the
 *               plugins' .cron files on the flash; User Scripts and their
 *               schedules; atd's queue; Unraid's notification agents. (Lines whose
 *               program went with its plugin are order, not security: Ms.
 *               Dustdevil tells those, in her «Where is what».)
 *   data flow   who pulls how much (see "data flow" below): per client and file
 *               service (SMB, NFS, SSH, the WebGUI) the bytes the server sent,
 *               from the kernel's counters of the open connections (ss);
 *               SMB's users, machines and the hours they start sessions;
 *               per container what it sent (its network namespace's counters);
 *               per ZFS share what was written (ZFS `written`, the ransomware
 *               pattern) and what vanished from it (ZFS `referenced`; XFS and
 *               btrfs disks: their used space) — hourly, learned per client,
 *               container and share, told only when far above what is normal at
 *               that time of the week; the office's own backup and restore, and
 *               what moves data (the mover, EmbyCache …), are expected
 *   snapshots   ZFS snapshots of the awake pools and the btrfs snapshot folders of
 *               the awake disks, round against round (see "snapshots that vanish"):
 *               gone without the office removing them (Ms. Snapshotini's log, the
 *               engine's retention during its run, renamed, the storeroom), a hold
 *               released not by Ms. Snapshotini — what an attacker does first
 *   API keys    Unraid's own API (see "Unraid's API as a door"): a new key or more rights for one (from its store
 *               on the flash — never the key's value, he never asks the API), the doors its settings open
 *
 * All of it lives in RAM or on the flash: no disk wakes up. What differs goes
 * into his watch book, to the team lead as 'checks' (recommended, one per
 * kind) and — the important kinds — right away to Unraid's notifications (per
 * kind at most one every WATCH_NOTIFY_QUIET, so a burst gives one message).
 * «I know, thanks» — on his page, or the team lead's on his finding — makes
 * that state the new normal. What gets safer (fewer rights, a share closed
 * again, a plugin removed) becomes normal by itself.
 *
 * How secure (his posture tips, each round, from RAM and the flash): the flash
 * exported to guests (also only for reading: password hashes and SSH keys lie
 * there), shares guests may read, write and delete, Telnet, Unraid's FTP server, an
 * API key that can do everything (ADMIN, or it may make itself one), the CPU's
 * protection against Spectre-like flaws switched off (and VMScape with VMs) or
 * on, privileged containers that run or start by themselves (one stopped and without autostart only a quiet line
 * in the tip, inbox #10, 1.51.0). Advice, not findings: never in the book, never a
 * notification; «I know, thanks» on one is kept in posture.json (for every
 * browser) until the tip goes or what it is about changes. The team lead gets
 * one hint with how many there are. Where Ms. Whereabouts (up to 1.30; now Ms.
 * Dustdevil's «Where is what») gave security tips before, she now points here.
 *
 * Not Fix Common Problems' static checks: never "SSH is on", "a weak
 * password", "a plugin not known to Community Applications" or "the FTP
 * server with users" — those are its own.
 *
 * data/watchman/: baseline.json (what is normal), book.json (the watch book,
 * at most WATCH_BOOK_MAX entries, noted ones for WATCH_BOOK_DAYS), state.json
 * (syslog position, recent failures, the last round, notifications — small,
 * the tick and the metrics read it), seen.json (what the last round saw, for
 * «I know, thanks»), flow.json (the data flow's hourly history, aggregated —
 * never per connection; the last round's counters stay in RAM), posture.json
 * (his posture tips you know about), snaps.json (the snapshots of the last round,
 * what the office removed, the position in its log). The page reads data/watchman.json
 * (watchmanPageState()); with Grafana and the office's dashboard there (the
 * consultant's look) it links his data flow's history in it.
 *
 * Partner offices (stage 2 of briefs/uso-partner-zfs-plan.md, agent/lib/partnerlook.php — the door and the pairing are
 * the Team Lead's): the office's own line in authorized_keys (uso-partner:<id>, exactly as the pairing writes it, the
 * pair's key, written right at the pairing) is noted by himself; a known pair's line changed is `door_changed`; the
 * pair's key from another address or its address with another key `door_key_moved`; three refusals at the door within a
 * minute `door_refused`; the door's transfers and the sender's phase `partner` are the office's own in the data flow,
 * what the door's retention destroyed (data/partner/deletes.jsonl) no `snap_gone`; posture tips for a door wider than
 * the office made it, a door line of no pair, a pair facing the internet, plain copies going to a «friend».
 *
 * The network (group `net`, agent/lib/watchnet.php — stage 1 of the router SOC, UniFi only): what the router sends to
 * Unraid's own syslog server, read from its files like his syslog (never in the night shift, never the files of this
 * server's own addresses, nothing written there) — a witness's statement about the server: a new sender, a new device,
 * someone claiming the server's name or address, router and VPN logins not seen before, firewall changes, other config
 * changes (a line a day), IPS detections against or from the server, the gateway blocking something from the server, the
 * router's log going silent; the router's clock as a posture tip. Other clients' addresses only in net_new_device and
 * net_spoof (the privacy rule); the «whole LAN» switch adds per-device counts, nothing more.
 */

require_once __DIR__ . '/../lib/partnerlook.php';
require_once __DIR__ . '/../lib/watchnet.php';
require_once __DIR__ . '/../lib/paritywhy.php';

const WATCH_EVERY        = 300;              // a round every 5 minutes
const WATCH_LOOK         = 20;               // the tick looks whether one is due this often (seconds)
const WATCH_MAX_RUN      = 240;              // a round that takes longer is stopped
const WATCH_FAIL_BURST   = 5;                // failed logins from one address …
const WATCH_FAIL_WINDOW  = 600;              // … within 10 minutes: a burst
const WATCH_NOTIFY_QUIET = 3600;             // per kind at most one notification an hour
const WATCH_BOOK_MAX     = 500;              // entries in the watch book
const WATCH_BOOK_DAYS    = 90;               // noted entries stay this long
const WATCH_FORGET       = 30 * 86400;       // what is gone (a plugin, a container, a share) stays known this long
const WATCH_READ_MAX     = 32 * 1024 * 1024; // syslog read in one round at most (then the newest part)
const WATCH_LEARN_MAX    = 16 * 1024 * 1024; // per file when he takes over the watch
const WATCH_IPS_MAX      = 500;              // login addresses he knows
const WATCH_TRACK_MAX    = 2000;             // addresses with recent failures followed
const WATCH_LIST_MAX     = 8;                // names and services kept per entry
const WATCH_ID           = '/^w[0-9a-f]{10}$/D';
const WATCH_CODE_HOSTS   = ['github.com', 'raw.githubusercontent.com', 'gitlab.com', 'codeberg.org', 'bitbucket.org'];
// what the office installed itself (the consultant's record, watchmanOfficeLook()): never told, noted by itself
const WATCH_OFFICE_PLUGIN = 900;             // a plugin he installed: its link in /var/log/plugins (and a cron.d file of its name) this soon after his job started
const WATCH_OFFICE_FORM   = 7200;            // a container from Unraid's form he prepared: created this soon after
const WATCH_OFFICE_KEEP   = 7 * 86400;       // his records older than this are left out
// Mr. Restori's drill (its record, watchmanDrillRecord()): a throwaway of exactly the recorded name, label uso.drill=<its
// drill> and image, created this soon after the record, no rights — the office's own
const WATCH_DRILL_NAME    = '/^uso-drill-(\d{8}-\d{6}-[0-9a-f]{4})-\d{1,3}$/D';
const WATCH_DRILL_CREATE  = 600;
const WATCH_DRILL_KEEP    = 7 * 86400;
// partner offices (agent/lib/partnerlook.php)
const WATCH_PARTNER_PAIRED = 900;            // the office's line in authorized_keys: written this soon after the pair's `paired`
const WATCH_REFUSED_BURST  = 3;              // refusals at the door from one pair …
const WATCH_REFUSED_WINDOW = 60;             // … within a minute: door_refused
const WATCH_DOOR_WHAT = ['from' => 'door_what.from', 'restrict' => 'door_what.restrict', 'command' => 'door_what.command', 'key' => 'door_what.key',
                         'options' => 'door_what.options'];      // what of a door line changed, in words

/** Every kind of entry: its group, and whether it goes to Unraid's notifications right away */
const WATCH_KINDS = [
    'login_new_ip'         => ['login', true],
    'login_failures'       => ['login', true],
    'container_new'        => ['container', true],
    'container_privileged' => ['container', true],
    'container_host'       => ['container', true],
    'container_ports'      => ['container', false],
    'container_rights'     => ['container', false],
    'drill_throwaway'      => ['container', false],     // Mr. Restori's drill made it (his record, name, label and image agree): noted by himself (by office)
    'plugin_new'           => ['plugin', true],
    'plugin_source'        => ['plugin', true],
    'flash_go'             => ['flash', true],
    'flash_extra'          => ['flash', true],
    'flash_user'           => ['flash', true],
    'flash_password'       => ['flash', true],
    'flash_ssh_key'        => ['flash', true],
    'share_public'         => ['share', true],
    'cron_new'             => ['sched', true],
    'cron_twice'           => ['sched', true],
    'cron_office'          => ['sched', true],
    'cron_file'            => ['sched', false],
    'cron_file_foreign'    => ['sched', true],
    'script_new'           => ['sched', false],
    'script_changed'       => ['sched', false],
    'at_job'               => ['sched', true],
    'at_userscript'        => ['sched', false],     // a User Script run in the background: noted by himself (watchmanAtUserScript())
    'at_plugin'            => ['sched', false],     // a plugin's own job (a file of an installed plugin, as root): noted by himself (watchmanAtPlugin())
    'notify_agent'         => ['sched', true],
    'flow_client'          => ['flow', true],
    'flow_container'       => ['flow', true],
    'flow_written'         => ['flow', true],
    'flow_gone'            => ['flow', true],
    'smb_user'             => ['flow', true],
    'smb_client'           => ['flow', false],
    'smb_hour'             => ['flow', false],
    'snap_gone'            => ['snap', true],       // snapshots gone that the office didn't remove
    'snap_hold_released'   => ['snap', true],       // a hold released, not by Ms. Snapshotini
    'log_cleared'          => ['host', true],       // a log emptied or replaced outside its rotation (watchmanHostLogsCompare())
    'user_ram'             => ['host', true],       // an account in RAM the flash doesn't have, a second root, a login shell or password for a system account
    'listen_new'           => ['host', true],       // a program of the server listens on a network port it never used
    'proc_odd'             => ['host', true],       // a program runs from a scratch folder (/tmp, /dev/shm …) or from memory
    'door_new'             => ['host', true],       // a new way in from outside: SSH on or on another port, UPnP, Connect's remote access, a single sign-on, a WireGuard peer, the API's sandbox, origins, Unraid.net logins
    'api_key_new'          => ['host', true],       // a new key for Unraid's API (its name, roles, permissions — never its value; watchmanApiKeys())
    'api_key_changed'      => ['host', true],       // an API key's roles or permissions changed (fewer roles alone: safer, normal by itself)
    'array_stop'           => ['array', false],     // the array was stopped: a plain line, noted by himself (watchmanArrayLines())
    'array_start'          => ['array', false],     // the array was started: likewise
    'server_boot'          => ['array', false],     // the server was started (a new boot id): likewise (watchmanBootLine())
    'parity_check'         => ['array', false],     // a parity check (or rebuild) began: its reason in plain words, noted by himself (agent/lib/paritywhy.php)
    'partner_paired'       => ['partner', false],   // the office's own door line for a pair, written at the pairing: noted by himself (by office)
    'door_changed'         => ['partner', true],    // a known pair's door line changed (another from=, no restrict, another command or key)
    'door_key_moved'       => ['partner', true],    // a pair's key from another address, or a pair's address with another key
    'door_refused'         => ['partner', true],    // three or more refusals at the door within a minute from one pair
    // the network (agent/lib/watchnet.php): what the router says about the server
    'net_sender_new'          => ['net', false],    // a new file in Unraid's syslog server folder: a host started sending there
    'net_new_device'          => ['net', false],    // a MAC never seen on the LAN (important when it takes a known device's name: the entry's `important`)
    'net_spoof'               => ['net', true],     // the server's name or one of its addresses with a MAC that isn't the server's
    'net_router_login'        => ['net', true],     // a router admin login by an admin or from an address not seen before
    'net_firewall_change'     => ['net', true],     // the router's firewall, NAT, port forwarding or policies changed
    'net_router_config'       => ['net', false],    // other router settings changed: a line a day (noted by himself when the admin is known)
    'net_vpn_login'           => ['net', true],     // a VPN user or remote address not seen before
    'net_ips_server'          => ['net', true],     // an IPS detection against the server or from it
    'net_blocked_from_server' => ['net', true],     // the gateway dropped something the server sent (a destination and port not seen before)
    'net_log_silent'          => ['net', true],     // the router's file stopped growing while the router still answers
];

/**
 * Each kind's nearest MITRE ATT&CK technique (attack.mitre.org) — the words a security team searches for; the page
 * shows it as a chip (no link out — Benj, 2026-10-09), the syslog export carries it. Nearest, not exact: a new plugin is software that runs as root at boot.
 */
const WATCH_ATTACK = [
    'login_new_ip' => 'T1078', 'login_failures' => 'T1110',
    'container_new' => 'T1610', 'container_privileged' => 'T1611', 'container_host' => 'T1611', 'container_ports' => 'T1133',
    'container_rights' => 'T1611', 'drill_throwaway' => 'T1610',
    'plugin_new' => 'T1543', 'plugin_source' => 'T1195.002',
    'flash_go' => 'T1037.004', 'flash_extra' => 'T1037.004', 'flash_user' => 'T1136.001', 'flash_password' => 'T1098',
    'flash_ssh_key' => 'T1098.004', 'share_public' => 'T1222',
    'cron_new' => 'T1053.003', 'cron_twice' => 'T1053.003', 'cron_office' => 'T1053.003', 'cron_file' => 'T1053.003',
    'cron_file_foreign' => 'T1053.003', 'script_new' => 'T1053.003', 'script_changed' => 'T1053.003',
    'at_job' => 'T1053.002', 'at_userscript' => 'T1053.002', 'at_plugin' => 'T1053.002', 'notify_agent' => 'T1546',
    'flow_client' => 'T1039', 'flow_container' => 'T1041', 'flow_written' => 'T1486', 'flow_gone' => 'T1485',
    'smb_user' => 'T1021.002', 'smb_client' => 'T1021.002', 'smb_hour' => 'T1021.002',
    'snap_gone' => 'T1490', 'snap_hold_released' => 'T1490',
    'log_cleared' => 'T1070.002', 'user_ram' => 'T1136.001', 'listen_new' => 'T1133', 'proc_odd' => 'T1105', 'door_new' => 'T1133',
    // an API key is a credential added to the server's API (or more rights given to one) to keep a way in: Account Manipulation
    // (its sub-technique .001 is for cloud accounts, so the parent is the nearest); T1078 Valid Accounts would be its use, which
    // he can't see — he never asks the API
    'api_key_new' => 'T1098', 'api_key_changed' => 'T1098',
    // an array stopped is a service stopped (T1489 Service Stop); started again is its other end — the same technique, so a SIEM finds both
    'array_stop' => 'T1489', 'array_start' => 'T1489',
    // a reboot: System Shutdown/Reboot
    'server_boot' => 'T1529',
    // a parity check: mostly the trace of a reboot whose stop wasn't clean — System Shutdown/Reboot again
    'parity_check' => 'T1529',
    // the partner door: a key in authorized_keys (SSH Authorized Keys) — the office's own written at the pairing, or one
    // changed; the pair's key used from elsewhere is a valid account's use; refusals are someone trying the door (SSH)
    'partner_paired' => 'T1098.004', 'door_changed' => 'T1098.004', 'door_key_moved' => 'T1078', 'door_refused' => 'T1021.004',
    // the network: a new sender or device is hardware added (T1200); someone taking the server's identity on the LAN an
    // adversary-in-the-middle (T1557); a router admin's login a valid account (T1078); a firewall change Impair Defenses:
    // Disable or Modify System Firewall; other settings Impair Defenses (T1562); a VPN login External Remote Services;
    // an IPS hit against the server Active Scanning (T1595) — from it Application Layer Protocol (T1071), like a block
    // from the server; the router's log silent Impair Defenses: Indicator Blocking
    'net_sender_new' => 'T1200', 'net_new_device' => 'T1200', 'net_spoof' => 'T1557', 'net_router_login' => 'T1078',
    'net_firewall_change' => 'T1562.004', 'net_router_config' => 'T1562', 'net_vpn_login' => 'T1133', 'net_ips_server' => 'T1595',
    'net_blocked_from_server' => 'T1071', 'net_log_silent' => 'T1562.006',
];

/**
 * His posture tips — how secure the server stands now (watchmanPosture()): id => level, in the order
 * of his page. advice: he would change it; info: can be right as it is, as long as you know.
 */
const WATCH_POSTURE = [
    'flash'           => 'advice',
    'public'          => 'advice',
    'telnet'          => 'advice',
    'upnp'            => 'advice',
    'ftp'             => 'advice',
    'api_admin'       => 'advice',
    'mitigations_off' => 'advice',
    'vmscape'         => 'advice',
    'partner_wide'    => 'advice',     // a partner's door line without restrict or from= (wider than the office made it)
    'partner_unknown' => 'advice',     // a uso-partner line for a pair the office doesn't know
    'partner_public'  => 'advice',     // a pair whose address faces the internet
    'router_clock'    => 'advice',     // the router's clock (or its time zone) off the server's by more than 5 minutes
    'remote_access'   => 'info',
    'privileged'      => 'info',
    'partner_friend'  => 'info',       // plain copies going to a partner marked «a friend»
    'mitigations_on'  => 'info',
];
/** The office's dashboard in Grafana (monitoring/grafana-dashboard.json): the panel of a data flow group (tests check the ids) */
const WATCH_GRAFANA_PANELS = ['flow_clients' => 50, 'flow_shares' => 51];

// syslog lines: Unraid's "Oct  6 08:54:00 Tower …" (or an ISO time, if rsyslog is set so)
const WATCH_TIME_SYSLOG = '/^([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d\d:\d\d:\d\d)\s/';
const WATCH_TIME_ISO    = '/^(\d{4}-\d\d-\d\d)[T ](\d\d:\d\d:\d\d)(?:\.\d+)?(Z|[+-]\d\d:?\d\d)?\s/';
// the web login (dynamix/include/.login.php, my_logger → tag "webgui"): the address is the last " from …" (the name is the user's input)
const WATCH_WEB         = '/\swebgui:\s+(Successful|Unsuccessful) login user (.*) from (\S+?)\.?(?:\s|$)/i';
const WATCH_SSH_OK      = '/\ssshd[\w-]*(?:\[\d+\])?:\s+Accepted (\S+) for (\S+) from (\S+) port \d+/';
const WATCH_SSH_FAIL    = '/\ssshd[\w-]*(?:\[\d+\])?:\s+Failed (\S+) for (invalid user )?(\S+) from (\S+) port \d+/';
const WATCH_SSH_INVALID = '/\ssshd[\w-]*(?:\[\d+\])?:\s+Invalid user (.*) from (\S+) port \d+/';
// docker inspect: name, image, HostConfig and mounts as JSON (tabs and newlines inside are escaped), the main process (data flow)
// and the consultant's label (ADVISOR_LABEL: he prepared Unraid's form) and when it was created — what the office installed itself;
// then its networks (the network watch: a container's own address and MAC on the LAN are the server's, watchnetServer()), last
// its storage driver's data (Docker on ZFS: the dataset of its writable layer — who wrote into a share, watchmanFlowSources())
const WATCH_INSPECT     = "{{json .Name}}\t{{json .Config.Image}}\t{{json .HostConfig}}\t{{json .Mounts}}\t{{.State.Pid}}\t{{json (index .Config.Labels \"uso.installed-by\")}}\t{{json .Created}}\t{{json .NetworkSettings.Networks}}\t{{json (index .Config.Labels \"uso.drill\")}}\t{{json .GraphDriver.Data}}\t{{json (index .Config.Labels \"com.docker.compose.project\")}}";

// the night shift (agent.php nightshift, watchmanNightRound()): while the array is stopped, and from boot until the first array
// start (an encrypted array waits for its key), he keeps the RAM and flash parts of his watch — nothing under /mnt, no data folder
const WATCH_NIGHT_DIR    = RUN_DIR . '/nightshift';             // the night's book, state and log (RAM, root only)
const WATCH_NIGHT_LOCK   = RUN_DIR . '/nightshift.lock';        // held while the night shift is on: never two of them, never with the agent
const WATCH_NIGHT_FILES  = ['baseline.json', 'book.json', 'state.json', 'seen.json', 'posture.json'];
const WATCH_MIRROR_RAM   = RUN_DIR . '/watchman-mirror.json';   // what the night shift needs, after every round (RAM, for an array stop)
const WATCH_MIRROR_FLASH = '/boot/config/plugins/' . OFFICE_PLUGIN . '/watchman-mirror.json';  // the same for a reboot, without secrets
const WATCH_MIRROR_EVERY = 3600;                                // the flash mirror at most once an hour (the flash wears), only when it changed
const WATCH_ARRAY_EVENTS = RUN_DIR . '/array-events';           // "<time> stop|start" lines (scripts/agent.sh array …, from the event scripts)
const WATCH_ARRAY_BEFORE = 1800;                                // logins this long before an array stop or start …
const WATCH_ARRAY_AFTER  = 300;                                 // … and this long after it are named in its line
const WATCH_LOGINS_MAX   = 30;                                  // successful logins remembered for those lines (state.json logins)

desk('watchman', [
    'fit'     => fn (): array => fit(true, 'yes'),
    'start'   => fn () => watchmanRecover(),
    'tick'    => fn () => watchmanTick(),
    'actions' => [
        'refresh' => fn (array $r) => ['ok' => true, 'state' => watchmanPageState()],
        'round'   => fn (array $r) => watchmanRoundNow(),
        'ack'     => fn (array $r) => watchmanAck(is_array($r['id'] ?? null) ? null : ($r['id'] ?? null)),      // one id; a list is ack_some's
        'ack_all' => fn (array $r) => watchmanAck('*'),
        'ack_some' => fn (array $r) => watchmanAck(idList($r, 'ids')),       // a folded row of his page: «All n: I know, thanks»
        'notify_set' => fn (array $r) => watchmanNotifySet($r['on'] ?? null),
        'syslog_set' => fn (array $r) => watchmanSyslogSet($r['on'] ?? null),
        'posture_ack' => fn (array $r) => watchmanPostureAck($r['id'] ?? null, $r['on'] ?? null),
        'net_lan_set' => fn (array $r) => watchmanNetLanSet($r['on'] ?? null),
    ],
    'jobs'    => ['watchman-round' => fn (array $args) => watchmanRun()],
    'checks'  => fn (): array => watchmanChecks(),
    'metrics' => fn (): array => watchmanMetrics(),
]);

/** Where he reads — fixed; the tests hand in copies */
function watchmanPaths(): array
{
    return [
        'syslog'     => '/var/log/syslog',
        'plugins'    => '/var/log/plugins',
        'go'         => '/boot/config/go',
        'extra'      => '/boot/extra',
        'passwd'     => '/boot/config/passwd',
        'shadow'     => '/boot/config/shadow',
        'ssh'        => '/boot/config/ssh',
        'sec'        => '/var/local/emhttp/sec.ini',
        'sec_nfs'    => '/var/local/emhttp/sec_nfs.ini',
        'share_cfg'  => '/boot/config/share.cfg',
        'etc_passwd' => '/etc/passwd',
        'crontabs'   => '/var/spool/cron/crontabs',
        'cron_d'     => '/etc/cron.d',
        'cron_files' => '/boot/config/plugins',
        'userscripts' => '/boot/config/plugins/user.scripts',
        'atjobs'     => '/var/spool/atjobs',
        'emhttp_plugins' => '/usr/local/emhttp/plugins',            // the plugins' files (RAM): a plugin's README names it (watchmanAtPluginName())
        'office_installs' => DATA_DIR . '/advisor/installs.json',    // the consultant's record of what he installed (root only)
        'drill_record' => DATA_DIR . '/restore-drill/record.json',  // Mr. Restori's drill: the throwaways it made (root only)
        'agents'     => '/boot/config/plugins/dynamix/notifications/agents',
        'var_ini'    => '/var/local/emhttp/var.ini',
        'disks_ini'  => '/var/local/emhttp/disks.ini',
        // snapshots that vanish (watchmanSnaps())
        'zfs'        => 'zfs',
        'zpool'      => 'zpool',
        'mnt'        => '/mnt',
        'agent_log'  => AGENT_LOG,
        'snap_record' => DATA_DIR . '/snapshot/deletes.jsonl',     // Ms. Snapshotini's own record (root only)
        'engine'     => BACKUP_DATA_DIR,
        // how secure (watchmanPostureLook())
        'ident'      => '/boot/config/ident.cfg',
        'inetd'      => '/etc/inetd.conf',
        'ftp_users'  => '/boot/config/vsftpd.user_list',
        'cmdline'    => '/proc/cmdline',
        'cpuinfo'    => '/proc/cpuinfo',
        'cpu_vulns'  => '/sys/devices/system/cpu/vulnerabilities',
        'libvirt_sock' => '/var/run/libvirt/libvirt-sock',
        'virsh'      => 'virsh',
        // the host itself (watchmanHost())
        'proc'       => '/proc',
        'etc_shadow' => '/etc/shadow',
        'logs'       => WATCH_HOST_LOGS,
        'logrotate'  => '/var/lib/logrotate.status',
        'boot_id'    => '/proc/sys/kernel/random/boot_id',
        'stat'       => '/proc/stat',             // btime: when the server was started (watchmanBootLine())
        // why a parity check runs (agent/lib/paritywhy.php): the flash's history, Unraid's schedule, Parity Check Tuning's
        // records, what the shutdown before left in /boot/logs, the time-outs (the syslog, var.ini, rsyslog.cfg: above/below)
        'parity_log'  => '/boot/config/parity-checks.log',
        'parity_cron' => '/boot/config/plugins/dynamix/parity-check.cron',
        'pct_dir'     => PARITYWHY_PCT_DIR,
        'boot_logs'   => '/boot/logs',
        'domain_cfg'  => '/boot/config/domain.cfg',
        'docker_cfg'  => '/boot/config/docker.cfg',
        // what starts a stopped privileged container by itself (watchmanContainerStarts()): Unraid's Docker autostart list,
        // Compose Manager's stacks (its setting where they lie)
        'docker_autostart' => HOUSE_AUTOSTART,
        'compose_cfg'      => HOUSE_COMPOSE_CFG,
        'compose_projects' => HOUSE_COMPOSE_PROJECTS,
        'port_range' => '/proc/sys/net/ipv4/ip_local_port_range',
        'ss'         => 'ss',
        'connect'    => '/boot/config/plugins/dynamix.my.servers/configs/connect.json',
        'oidc'       => '/boot/config/plugins/dynamix.my.servers/configs/oidc.json',
        'wireguard'  => '/boot/config/wireguard',
        // Unraid's own API (7.2+) as a door: its keys (never their values) and the doors its settings open (watchmanApiKeys(),
        // watchmanHostDoors()) — both on the flash, both or neither
        'api_keys'   => '/boot/config/plugins/dynamix.my.servers/keys',
        'api_cfg'    => '/boot/config/plugins/dynamix.my.servers/configs/api.json',
        'logger'     => 'logger',
        // array stops and starts (event/stopping, event/started → scripts/agent.sh array …): lines in the book
        'array_events' => WATCH_ARRAY_EVENTS,
        // partner offices (agent/lib/partnerlook.php): the pairs (root only), the door's records in RAM, its records in the data folder
        'partner_pairs' => DATA_DIR . '/partner/pairs.json',
        'partner_tickets' => DATA_DIR . '/partner/tickets.json',        // the restore tickets this office gave (stage 3)
        'partner_run'   => RUN_DIR . '/partner',
        'partner_data'  => DATA_DIR . '/partner',
        // the network (agent/lib/watchnet.php): Unraid's syslog server (the flash), the share's storage (RAM), this server's MACs, ARP
        'rsyslog_cfg'   => WATCHNET_CFG,
        'shares_ini'    => '/var/local/emhttp/shares.ini',
        'net_class'     => '/sys/class/net',
        'arp'           => '/proc/net/arp',
    ];
}

function watchmanDir(): string
{
    return DATA_DIR . '/watchman';
}

/** Since when he works here (data/office/staff.json), or null */
function watchmanHiredSince(): ?int
{
    $hired = (array) ((readJson(DATA_DIR . '/office/staff.json') ?? [])['hired'] ?? []);
    return isset($hired['watchman']) ? (int) $hired['watchman'] : null;
}

// ===================================================================== his files

/**
 * His files. Entries of a kind he no longer keeps (cron_dead up to 1.28: Ms. Dustdevil tells those now) and
 * the false alarms about dcron's reload signal (up to 1.29, watchmanCronSignalEntry()) are left out quietly —
 * gone from every view, and from the file at the book's next write.
 *
 * @return array{baseline: ?array, book: list<array>, state: array}
 */
function watchmanLoad(?string $dir = null): array
{
    $dir ??= watchmanDir();
    $book = readJson("$dir/book.json");
    $entries = [];
    foreach ((array) ($book['entries'] ?? []) as $e) {
        if (is_array($e) && is_string($e['id'] ?? null) && is_string($e['kind'] ?? null) && (isset(WATCH_KINDS[$e['kind']]) || $e['kind'] === 'watch')
            && !watchmanCronSignalEntry($e)) {
            $entries[] = $e;
        }
    }
    return ['baseline' => readJson("$dir/baseline.json"), 'book' => $entries, 'state' => watchmanStateFix(readJson("$dir/state.json") ?? [])];
}

/**
 * Up to 1.30 the SIEM switch (syslog_set) and the syslog's read position shared the key `syslog`: the switch
 * overwrote the position (the next round failed on it) and a position counted as «on». The switch is `siem`
 * now; a switch found under `syslog` moves there, and the syslog is read on from its end.
 */
function watchmanStateFix(array $st): array
{
    if (array_key_exists('syslog', $st) && !is_array($st['syslog']) && $st['syslog'] !== null) {
        $st['siem'] ??= (bool) $st['syslog'];
        $st['syslog'] = null;
    }
    return $st;
}

/** A false alarm up to 1.29: «new» lines that were only dcron's reload signal (WATCH_CRON_SIGNAL, its text the user's name) */
function watchmanCronSignalEntry(array $e): bool
{
    $jobs = (array) ($e['p']['jobs'] ?? []);
    return $e['kind'] === 'cron_new' && $jobs && count($jobs) === (int) ($e['p']['lines'] ?? -1)
        && !array_filter($jobs, fn ($j) => !preg_match('#^(?:cron\.d/)?' . preg_quote(WATCH_CRON_SIGNAL, '#') . ': [a-z_][a-z0-9_.-]{0,31}$#D', (string) $j));
}

/** Writes what changed ($new: baseline, book, state; seen when given) */
function watchmanSave(string $dir, array $old, array $new): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @lchown($dir, FILE_UID);
        @lchgrp($dir, FILE_GID);
    }
    foreach (['baseline' => 'baseline.json', 'book' => 'book.json', 'state' => 'state.json', 'seen' => 'seen.json', 'flow' => 'flow.json',
              'posture' => 'posture.json', 'snaps' => 'snaps.json', 'net' => 'net.json'] as $k => $file) {
        if (!array_key_exists($k, $new) || $new[$k] === null || ($old[$k] ?? null) === $new[$k]) {
            continue;
        }
        writeAtomic("$dir/$file", jsonEncode($k === 'book' ? ['entries' => array_values($new[$k])] : $new[$k]));
    }
}

/** A lock of his own in RAM (one per data folder, so a test never waits for the real one; named by its path as the user set it) */
function watchmanLockFile(string $dir, string $what): string
{
    @mkdir(RUN_DIR, 0700, true);
    return RUN_DIR . "/watchman-$what-" . substr(md5(dataPathUser($dir)), 0, 8) . '.lock';
}

/** Runs $fn while holding his book (a round's merge, «I know, thanks»): a few milliseconds each */
function watchmanLocked(string $dir, callable $fn): mixed
{
    $h = @fopen(watchmanLockFile($dir, 'book'), 'c');
    if (!$h) {
        throw new Problem('watch_busy');
    }
    $until = microtime(true) + 10;
    while (!flock($h, LOCK_EX | LOCK_NB)) {
        if (microtime(true) > $until) {
            fclose($h);
            throw new Problem('watch_busy');
        }
        usleep(50000);
    }
    try {
        return $fn();
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

// ===================================================================== rounds: started from the tick

/** At the start: a round from before the agent restarted may still be on its way (it holds the round lock) */
function watchmanRecover(): void
{
    $GLOBALS['wmOrphan'] = watchmanRoundBusy(watchmanDir());
}

/** Is a round running? It holds its lock for as long as it runs */
function watchmanRoundBusy(string $dir): bool
{
    $h = @fopen(watchmanLockFile($dir, 'round'), 'c');
    if (!$h) {
        return false;
    }
    $free = flock($h, LOCK_SH | LOCK_NB);
    if ($free) {
        flock($h, LOCK_UN);
    }
    fclose($h);
    return !$free;
}

/**
 * Every ~150 ms: while a round runs, whether it is done (or takes too long);
 * otherwise every WATCH_LOOK seconds whether one is due — two small files.
 */
function watchmanTick(): void
{
    $job = $GLOBALS['wmRound'] ?? null;
    if ($job) {
        $status = proc_get_status($job['process']);
        if ($status['running']) {
            if (time() - $job['since'] > WATCH_MAX_RUN && empty($job['stopped'])) {
                proc_terminate($job['process']);
                $GLOBALS['wmRound']['stopped'] = true;
                logLine('Night watchman: his round took longer than ' . WATCH_MAX_RUN . ' s — stopped');
            }
            return;
        }
        proc_close($job['process']);
        $GLOBALS['wmRound'] = null;
        if ($status['exitcode'] > 0) {
            $err = trim((string) @file_get_contents(RUN_DIR . '/watchman-round.err', false, null, 0, 4096));
            logLine('Night watchman: round failed (exit ' . $status['exitcode'] . ')' . ($err !== '' ? ': ' . strtok($err, "\n") : ''));
        }
        return;
    }
    $now = time();
    if ($now < ($GLOBALS['wmNextLook'] ?? 0)) {
        return;
    }
    $GLOBALS['wmNextLook'] = $now + WATCH_LOOK;
    if (!empty($GLOBALS['wmOrphan'])) {
        $GLOBALS['wmOrphan'] = watchmanRoundBusy(watchmanDir());
        if ($GLOBALS['wmOrphan']) {
            return;
        }
    }
    $since = watchmanHiredSince();
    if ($since === null) {
        if (empty($GLOBALS['wmMirrorGone'])) {
            $GLOBALS['wmMirrorGone'] = true;
            watchmanMirrorDrop();       // let go: no night shift any more, and nothing of a night to take over
            if (!watchmanNightOn()) {
                foreach (WATCH_NIGHT_FILES as $f) {
                    @unlink(WATCH_NIGHT_DIR . "/$f");
                }
            }
        }
        return;
    }
    $GLOBALS['wmMirrorGone'] = false;
    $st = readJson(watchmanDir() . '/state.json') ?? [];
    $started = (int) ($GLOBALS['wmStarted'] ?? 0);
    $due = $now - max((int) ($st['round']['time'] ?? 0), $started) >= WATCH_EVERY;
    $anew = (int) ($st['hired'] ?? -1) !== $since && $now - $started >= 60;     // just hired (again): his first round right away
    // the array was started after a night shift: its entries into the book right away (a stat; never while it is still on)
    $night = $now - $started >= 60 && is_file(WATCH_NIGHT_DIR . '/book.json') && !watchmanNightOn();
    if ($due || $anew || $night) {
        watchmanStart();
    }
}

/** A round in a process of its own (niced), without the agent's open files */
function watchmanStart(): bool
{
    if (!empty($GLOBALS['wmRound']) || !empty($GLOBALS['wmOrphan'])) {
        return true;
    }
    @mkdir(RUN_DIR, 0700, true);
    $GLOBALS['wmStarted'] = time();
    $pipes = [];
    $process = @proc_open(['/bin/sh', '-c', 'exec "$@"' . closeInheritedFds(), 'sh',
                           bin('nice') ?? 'nice', '-n', '10', PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'watchman-round'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', RUN_DIR . '/watchman-round.err', 'w']], $pipes, '/');
    if (!is_resource($process)) {
        logLine('Night watchman: could not start his round');
        return false;
    }
    $GLOBALS['wmRound'] = ['process' => $process, 'since' => time()];
    return true;
}

/** "round": one now (the page polls until round.running is false) */
function watchmanRoundNow(): array
{
    if (watchmanHiredSince() === null) {
        throw new Problem('not_hired', ['desk' => 'watchman']);
    }
    if (!watchmanStart()) {
        throw new Problem('watch_start_failed');
    }
    return ['ok' => true, 'state' => watchmanPageState()];
}

/** php agent.php job watchman-round — one round (only one at a time, only while he works here) */
function watchmanRun(): int
{
    $since = watchmanHiredSince();
    if ($since === null) {
        return 0;
    }
    $dir = watchmanDir();
    $h = @fopen(watchmanLockFile($dir, 'round'), 'c');
    if (!$h || !flock($h, LOCK_EX | LOCK_NB)) {
        return 0;               // another round is on its way
    }
    try {
        // the array was stopped: first what the night shift saw, and its place in the syslog (never while it is still on)
        $night = watchmanNightHandover($dir);
        if (!empty($night['busy'])) {
            logLine('Night watchman: the night shift is still on — his round waits for it');
            return 0;
        }
        if ($night !== null) {
            logLine("Night watchman: took over from the night shift — {$night['new']} new in the watch book, {$night['updated']} brought up to date");
        }
        $r = watchmanRound(watchmanPaths(), $dir, $since, flow: fn (?array $containers): array => watchmanFlowLook(watchmanPaths(), $containers), snaps: true);
    } catch (Throwable $e) {
        logLine('Night watchman: round failed: ' . $e->getMessage());
        try {
            watchmanLocked($dir, function () use ($dir, $e): void {
                $d = watchmanLoad($dir);
                $st = $d['state'];
                $st['round'] = ['time' => time(), 'failed' => true, 'error' => substr($e->getMessage(), 0, 300)] + (array) ($st['round'] ?? []);
                watchmanSave($dir, $d, ['state' => $st]);
            });
        } catch (Throwable) {
        }
        return 1;
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
    if ($r['fresh']) {
        logLine('Night watchman: took over the watch — ' . watchmanSummaryLine($r['summary']));
    }
    if ($r['added']) {
        $kinds = array_count_values($r['added']);
        logLine('Night watchman: ' . count($r['added']) . ' new in the watch book (' . implode(', ', array_map(fn ($k, $n) => "$k×$n", array_keys($kinds), $kinds)) . ')');
    }
    foreach ($r['told'] as $t) {
        logLine("Night watchman: Unraid's notifications " . ($t['sent'] ? 'told' : 'could not be told') . " about {$t['n']} × {$t['kind']}");
    }
    watchmanMirrorKeep($dir);
    watchmanPageState();
    return 0;
}

/** The night shift's mirror after a change (a round, «I know, thanks», a switch) — a failure is logged, never in the way */
function watchmanMirrorKeep(string $dir): void
{
    try {
        if (watchmanMirrorWrite($dir) === 'flash') {
            logLine('Night watchman: his baseline for the night shift written to the flash (' . WATCH_MIRROR_FLASH . ')');
        }
    } catch (Throwable $e) {
        logLine('Night watchman: could not write his mirror for the night shift: ' . $e->getMessage());
    }
}

// ===================================================================== the night shift

/*
 * While the array is stopped — and from boot until the first array start: an encrypted array waits for its
 * key while the WebGUI and SSH are already reachable — the office's agent is gone (its data folder lies on
 * the pool, which is unmounted then; the office never keeps a pool busy). The night shift keeps his watch:
 * `php agent.php nightshift`, started by scripts/agent.sh in a session of its own (event/stopping; at boot by
 * the .plg), cwd /, nothing open under /mnt — its book, state and log in RAM (WATCH_NIGHT_DIR, 0700). Every
 * WATCH_EVERY a round of what lives in RAM or on the flash: logins (the syslog), the flash (go, extra, users,
 * passwords, keys), plugins, what starts on its own, the host (logs, accounts, ports, programs, ways in),
 * posture, the array's stop and start. Not looked at: containers (Docker is down — a program from an odd
 * place is the server's then; while Docker is still or again up, programs aren't looked at), the shares'
 * exports, the data flow, snapshots.
 *
 * His baseline comes from a mirror the agent writes after every round (watchmanMirrorWrite()): in RAM for an
 * array stop (all of it, with the syslog's position and what the last round saw), on the flash for a reboot
 * (only when it changed, at most once an hour; hashes, addresses and names only — no fingerprint of a
 * password, no words of a crontab line, no position: the flash is vfat, whoever reads the flash share reads
 * it). No mirror (never a round since he was hired, or not hired — the mirror goes when he is let go): no
 * night shift, it says so in its log and ends. With the flash mirror the night's first look stands for the
 * passwords: a change before it is found by the agent's first round after the start, against its own book.
 *
 * It reports like the day watch: the same important kinds, per kind once an hour (the mirror carries when
 * each was told), the notify switch, Unraid's language (from the flash, as always). At the array start
 * (event/started → agent.sh start: the night shift ends first) the agent's next round takes the night's
 * entries into the book on the pool (watchmanNightHandover(): `night` = seen while the array was stopped;
 * what was told stays told; an entry open in the day's book is only brought up to date) and reads the
 * syslog on from the night's place: nothing told twice, nothing lost.
 */
const WATCH_BUMP_KINDS = ['login_new_ip', 'login_failures', 'log_cleared', 'door_key_moved', 'door_refused'];     // entries that count up (watchmanBump()); the others hold a state

/** Where the night shift reads: what lies in the pool or needs the array is left out (missing parts: not looked at) */
function watchmanNightPaths(): array
{
    // libvirt is left alone too (while the array stops it shuts the VMs down; his VM count for a posture tip keeps the day's word)
    return array_diff_key(watchmanPaths(), array_flip(['office_installs', 'drill_record', 'zfs', 'zpool', 'mnt', 'agent_log', 'snap_record', 'engine',
        'sec', 'sec_nfs', 'share_cfg', 'libvirt_sock', 'virsh', 'partner_pairs', 'partner_tickets', 'partner_data',
        'rsyslog_cfg', 'shares_ini', 'arp', 'parity_log', 'parity_cron', 'pct_dir', 'boot_logs', 'domain_cfg', 'docker_cfg',
        'docker_autostart', 'compose_cfg', 'compose_projects']));
}

/** This boot's id: the RAM mirror and a position in the syslog belong to one boot */
function watchmanBootId(string $file = '/proc/sys/kernel/random/boot_id'): string
{
    $id = trim((string) @file_get_contents($file, false, null, 0, 64));
    return preg_match('/^[0-9a-f-]{8,64}$/D', $id) ? $id : '';
}

/** Is the night shift on? It holds its lock for as long as it runs */
function watchmanNightOn(string $lock = WATCH_NIGHT_LOCK): bool
{
    $h = @fopen($lock, 'c');
    if (!$h) {
        return false;
    }
    $free = flock($h, LOCK_SH | LOCK_NB);
    if ($free) {
        flock($h, LOCK_UN);
    }
    fclose($h);
    return !$free;
}

/**
 * What the night shift needs from his files ($d: baseline, state, seen, book). $flash: what still says something
 * after a reboot and is no secret — the baseline without the passwords' fingerprints, crontab lines by their
 * hashes only, no times that move every round; the open entries by kind and key; when each kind was told; the
 * switches. Without $flash: all of it, with the syslog's position, the failures and what the last round saw.
 */
function watchmanMirror(array $d, string $boot, int $now, bool $flash): array
{
    $b = (array) ($d['baseline'] ?? []);
    $st = (array) ($d['state'] ?? []);
    $base = ['hired' => (int) ($b['hired'] ?? 0), 'time' => (int) ($b['time'] ?? 0)];
    foreach (['ips', 'fail_ips', 'plugins', 'flash', 'sched', 'host', 'partner', 'net'] as $k) {
        $base[$k] = is_array($b[$k] ?? null) ? $b[$k] : null;
    }
    $open = [];
    foreach ((array) ($d['book'] ?? []) as $e) {
        if (watchmanOpen($e)) {
            $open[] = $flash ? ['id' => (string) $e['id'], 'kind' => (string) $e['kind'], 'key' => (string) ($e['key'] ?? ''), 'time' => (int) $e['time'],
                                'last' => (int) $e['last'], 'count' => (int) $e['count'], 'told' => $e['told'] ?? null, 'muted' => $e['muted'] ?? null]
                             : $e;
        }
    }
    $m = ['v' => 1, 'boot' => $boot, 'time' => $now, 'baseline' => $base, 'open' => $open,
          'state' => ['notify' => ($st['notify'] ?? true) !== false, 'siem' => !empty($st['siem']), 'notified' => (array) ($st['notified'] ?? []),
                      'chains' => (array) ($st['chains'] ?? [])]];
    if (!$flash) {
        $seen = (array) ($d['seen'] ?? []);
        $m['state'] += ['syslog' => is_array($st['syslog'] ?? null) ? $st['syslog'] : null, 'fails' => (array) ($st['fails'] ?? []),
                        'logins' => (array) ($st['logins'] ?? []), 'array_seen' => (int) ($st['array_seen'] ?? 0), 'last_notify' => $st['last_notify'] ?? null];
        $m['seen'] = ['sched' => $seen['sched'] ?? null, 'host' => $seen['host'] ?? null];
        $m['state']['net_pos'] = is_array($d['net']['pos'] ?? null) ? $d['net']['pos'] : null;     // the router files' positions (no line of them)
        return $m;
    }
    $mb = &$m['baseline'];
    if (is_array($mb['ips'])) {
        $mb['ips'] = array_map(fn ($k) => ['first' => 0, 'last' => 0, 'users' => (array) ($k['users'] ?? []), 'services' => (array) ($k['services'] ?? [])], $mb['ips']);
    }
    if (is_array($mb['fail_ips'])) {
        $mb['fail_ips'] = array_map(fn () => ['since' => 0, 'last' => 0, 'n' => 0, 'quiet' => 0], $mb['fail_ips']);
    }
    if (is_array($mb['plugins'])) {
        $mb['plugins'] = array_map(fn ($p) => array_diff_key((array) $p, ['seen' => 1]), $mb['plugins']);
    }
    if (is_array($mb['flash'])) {
        unset($mb['flash']['pw']);          // a fingerprint of a password's hash stays in RAM
    }
    if (is_array($mb['sched'])) {
        $hashes = fn ($lines) => array_map(fn () => '', (array) $lines);
        foreach (['lines', 'twice', 'office'] as $k) {
            if (is_array($mb['sched']['crontab'][$k] ?? null)) {
                $mb['sched']['crontab'][$k] = $hashes($mb['sched']['crontab'][$k]);
            }
        }
        if (is_array($mb['sched']['files'] ?? null)) {
            $mb['sched']['files'] = array_map(fn ($f) => ['h' => (string) ($f['h'] ?? ''), 'lines' => $hashes($f['lines'] ?? [])], $mb['sched']['files']);
        }
    }
    if (is_array($mb['host'])) {
        foreach (['listen', 'procs'] as $k) {
            if (is_array($mb['host'][$k] ?? null)) {
                $mb['host'][$k] = array_map(fn () => 0, $mb['host'][$k]);
            }
        }
    }
    unset($mb);
    return $m;
}

/** A mirror in exactly the shape watchmanMirror() writes it (the flash's may have been edited by anyone who can write the flash share) */
function watchmanMirrorOk(mixed $m): bool
{
    return is_array($m) && ($m['v'] ?? null) === 1 && is_array($m['baseline'] ?? null) && is_int($m['baseline']['hired'] ?? null)
        && $m['baseline']['hired'] > 0 && is_array($m['state'] ?? null) && is_array($m['open'] ?? null) && is_string($m['boot'] ?? null);
}

/**
 * After a round (and «I know, thanks», a switch on his page): the mirror for the night shift — in RAM always, on the
 * flash when it changed and its last write there is WATCH_MIRROR_EVERY old (else at a round after that). No
 * baseline (not on watch): both go.
 *
 * @return string  what was written: ram, flash, none
 */
function watchmanMirrorWrite(string $dir, string $ram = WATCH_MIRROR_RAM, ?string $flash = WATCH_MIRROR_FLASH, ?int $now = null, ?string $boot = null): string
{
    $now ??= time();
    $d = watchmanLoad($dir);
    if (!is_array($d['baseline'])) {
        watchmanMirrorDrop($ram, $flash);
        return 'none';
    }
    $d['seen'] = readJson("$dir/seen.json");
    $d['net'] = readJson("$dir/net.json");
    $boot ??= watchmanBootId();
    @mkdir(dirname($ram), 0700, true);
    writeAtomic($ram, jsonEncode(watchmanMirror($d, $boot, $now, false)), 0600, 0, 0);
    if ($flash === null || !is_dir(dirname($flash))) {
        return 'ram';
    }
    $m = watchmanMirror($d, $boot, $now, true);
    $sum = substr(hash('sha256', jsonEncode(array_diff_key($m, ['time' => 1, 'boot' => 1]))), 0, 32);
    clearstatcache(true, $flash);
    $old = readJson($flash);
    if (($old['sum'] ?? null) === $sum) {
        return 'ram';
    }
    $mtime = @filemtime($flash);
    if (is_array($old) && $mtime !== false && $now >= $mtime && $now - $mtime < WATCH_MIRROR_EVERY) {
        return 'ram';                       // changed, but the flash wears: at a round after the hour
    }
    writeAtomic($flash, jsonEncode($m + ['sum' => $sum]), 0600, 0, 0);
    return 'flash';
}

/** Not on watch any more: no mirror, nothing of a night left */
function watchmanMirrorDrop(string $ram = WATCH_MIRROR_RAM, ?string $flash = WATCH_MIRROR_FLASH): void
{
    foreach (array_filter([$ram, $flash]) as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

/**
 * The night's first round: his files in RAM from the mirror — the RAM one when it is of this boot (an array stop),
 * else the flash one (a reboot: this boot's syslog from its start, the passwords and the ports as the first look
 * sees them). Null: no mirror — no night shift.
 *
 * @return array{hired: int, from: string}|null
 */
function watchmanNightBegin(array $paths, string $night, int $now, string $ram = WATCH_MIRROR_RAM, ?string $flash = WATCH_MIRROR_FLASH, ?string $boot = null): ?array
{
    $boot ??= watchmanBootId();
    $from = 'ram';
    $m = readJson($ram);
    if (!watchmanMirrorOk($m) || $boot === '' || $m['boot'] !== $boot) {
        $from = 'flash';
        $m = $flash !== null ? readJson($flash) : null;
        if (!watchmanMirrorOk($m)) {
            return null;
        }
    }
    $b = $m['baseline'];
    $st = $m['state'];
    if ($from === 'flash') {
        if (is_array($b['flash'] ?? null)) {
            $b['flash']['pw'] = watchmanFlash($paths)['pw'];
        }
        foreach (['listen', 'procs'] as $k) {
            if (is_array($b['host'][$k] ?? null)) {
                $b['host'][$k] = array_map(fn () => $now, $b['host'][$k]);
            }
        }
    }
    $book = [];
    foreach ($m['open'] as $e) {
        if (!is_array($e) || !is_string($e['id'] ?? null) || !preg_match(WATCH_ID, $e['id']) || !isset(WATCH_KINDS[$e['kind'] ?? ''])) {
            continue;
        }
        $e += ['key' => '', 'time' => $now, 'last' => $now, 'count' => 1, 'p' => [], 'told' => null];
        $e['noted'] = null;
        $e['by'] = null;
        // as the day's book had it: what the night adds is told apart at the handover
        $e['stub'] = ['count' => (int) $e['count'], 'last' => (int) $e['last'], 'p' => $from === 'ram'];
        $book[] = $e;
    }
    $state = ['hired' => $b['hired'], 'notify' => ($st['notify'] ?? true) !== false, 'siem' => !empty($st['siem']),
              'notified' => (array) ($st['notified'] ?? []), 'chains' => (array) ($st['chains'] ?? []),
              // a reboot: this boot's syslog from its start (its id differs from the position's)
              'syslog' => $from === 'ram' && is_array($st['syslog'] ?? null) ? $st['syslog'] : ['ino' => -1, 'size' => 0],
              'fails' => $from === 'ram' ? (array) ($st['fails'] ?? []) : [], 'logins' => $from === 'ram' ? (array) ($st['logins'] ?? []) : [],
              'array_seen' => (int) ($st['array_seen'] ?? 0), 'last_notify' => $st['last_notify'] ?? null,
              // the boot the mirror was written in: from the flash after a reboot another one — his first round books the start
              'boot_seen' => (string) $m['boot'],
              'night' => ['since' => $now, 'from' => $from]];
    $seen = $from === 'ram' && is_array($m['seen'] ?? null) ? array_filter($m['seen'], 'is_array') : [];
    foreach (['baseline.json' => $b, 'state.json' => $state, 'seen.json' => $seen, 'book.json' => ['entries' => $book]] as $file => $data) {
        writeAtomic("$night/$file", jsonEncode($data), 0600, 0, 0);
    }
    @unlink("$night/posture.json");
    return ['hired' => $b['hired'], 'from' => $from];
}

/** Docker during the night: down — no container runs, a program from an odd place is the server's; still or again up — not looked at */
function watchmanNightDocker(string $pidFile = '/var/run/dockerd.pid'): ?array
{
    $pid = (int) @file_get_contents($pidFile, false, null, 0, 32);
    return $pid > 1 && @posix_kill($pid, 0) ? null : [];
}

/**
 * One round of the night shift in $night: his RAM and flash parts against the mirror's baseline. Null: no mirror —
 * no night shift. $docker: what Docker answers (the tests); by default nothing while it is down.
 *
 * @return array{fresh: bool, added: list<string>, told: list<array>, summary: array, begun: ?array}|null
 */
function watchmanNightRound(array $paths, string $night = WATCH_NIGHT_DIR, ?int $now = null, bool $notify = true, ?callable $docker = null,
                            string $ram = WATCH_MIRROR_RAM, ?string $flash = WATCH_MIRROR_FLASH, ?string $boot = null): ?array
{
    $now ??= time();
    if (!is_dir($night) && !@mkdir($night, 0700, true)) {
        throw new RuntimeException("cannot make $night");
    }
    $b = readJson("$night/baseline.json");
    $begun = null;
    if (!is_array($b) || (int) ($b['hired'] ?? 0) <= 0) {
        $begun = watchmanNightBegin($paths, $night, $now, $ram, $flash, $boot);
        if ($begun === null) {
            return null;
        }
        $b = readJson("$night/baseline.json") ?? [];
    }
    // the team lead's notes lie in the pool: none in the night
    $r = watchmanRound($paths, $night, (int) ($b['hired'] ?? 0), $now, $docker ?? fn (): ?array => watchmanNightDocker(), $notify, "$night/no-acks.json");
    return $r + ['begun' => $begun];
}

/**
 * The array is started and the agent back: the night's book goes into his on the pool — new entries with `night`
 * (seen while the array was stopped; a line that was new in the night and is open in the day's book under the same
 * key brings that one up to date), what the night added to entries open since the day (counts, the newest words);
 * what was told stays told — and he reads the syslog on from the night's place (also the failures followed, when each
 * kind was told, the logins of the last half hour). The night's files go afterwards. Never while the night shift is on.
 *
 * @return array{busy?: bool, new?: int, updated?: int}|null  null: no night to take over
 */
function watchmanNightHandover(string $dir, string $night = WATCH_NIGHT_DIR, ?int $now = null, string $lock = WATCH_NIGHT_LOCK): ?array
{
    $now ??= time();
    clearstatcache();
    if (!is_file("$night/book.json") && !is_file("$night/state.json")) {
        return null;
    }
    if (watchmanNightOn($lock)) {
        return ['busy' => true];
    }
    $n = watchmanLoad($night);
    $res = watchmanLocked($dir, function () use ($dir, $n, $now): array {
        $d = watchmanLoad($dir);
        if (!is_array($d['baseline']) || (int) ($d['baseline']['hired'] ?? -1) !== (int) ($n['baseline']['hired'] ?? -2)) {
            return ['new' => 0, 'updated' => 0];        // not on watch here, or since another hiring: the night is dropped
        }
        [$book, $new, $updated] = watchmanNightMerge($d['book'], $n['book'], $now);
        $st = watchmanNightState($d['state'], $n['state'], $now);
        $st['open'] = watchmanOpenCounts($book);
        watchmanSave($dir, $d, ['book' => $book, 'state' => $st]);
        return ['new' => $new, 'updated' => $updated];
    });
    foreach (WATCH_NIGHT_FILES as $f) {
        @unlink("$night/$f");
    }
    return $res;
}

/** @return array{0: list<array>, 1: int, 2: int}  the day's book with the night's entries, how many new, how many brought up to date */
function watchmanNightMerge(array $book, array $night, int $now): array
{
    $new = $updated = 0;
    $at = fn (callable $match): ?int => array_key_first(array_filter($book, $match));
    foreach ($night as $e) {
        if (($e['kind'] ?? '') === 'watch' || !isset(WATCH_KINDS[$e['kind'] ?? ''])) {
            continue;
        }
        $stub = is_array($e['stub'] ?? null) ? $e['stub'] : null;
        unset($e['stub']);
        if ($stub !== null) {
            // open in the day's book when the night began: only what the night added to it
            $i = $at(fn ($x) => $x['id'] === $e['id']);
            if ($i === null || !watchmanOpen($book[$i])) {
                continue;                   // noted or gone meanwhile: the night's word on it is no news
            }
            $more = max(0, (int) $e['count'] - (int) $stub['count']);
            $changed = $more > 0 || (int) $e['last'] > (int) $stub['last'];
            $before = $book[$i];
            $book[$i] = watchmanNightInto($book[$i], $e, $more, $changed ? ($stub['p'] ? 'take' : null) : 'keep');
            $updated += $book[$i] !== $before ? 1 : 0;
            continue;
        }
        $i = ($e['key'] ?? '') === '' ? null : $at(fn ($x) => ($x['key'] ?? '') === $e['key'] && watchmanOpen($x));
        if ($i !== null && watchmanOpen($e)) {
            $book[$i] = watchmanNightInto($book[$i], $e, (int) $e['count'], null);
            $updated++;
            continue;
        }
        $e['night'] = 1;
        $book[] = $e;
        $new++;
    }
    return [watchmanPrune($book, $now), $new, $updated];
}

/**
 * The night's word on an entry of the day's book: $more counted on, first and last seen, what was told or muted;
 * the words ($p): 'take' the night's (it began with the day's), 'keep' the day's, null joined (an entry that counts
 * up) or the night's (a state, it is newer)
 */
function watchmanNightInto(array $x, array $e, int $more, ?string $p): array
{
    $x['count'] = (int) $x['count'] + $more;
    $x['time'] = min((int) $x['time'], (int) $e['time']);
    $x['last'] = max((int) $x['last'], (int) $e['last']);
    if ($p === 'take' || ($p === null && !in_array($x['kind'], WATCH_BUMP_KINDS, true))) {
        $x['p'] = (array) ($e['p'] ?? []);
    } elseif ($p === null) {
        $x['p'] = watchmanMerge((array) ($x['p'] ?? []), (array) ($e['p'] ?? []));
    }
    foreach (['told', 'muted'] as $k) {
        if (empty($x[$k]) && !empty($e[$k])) {
            $x[$k] = (int) $e[$k];
        }
    }
    return $x;
}

/** The night's state into the day's: the syslog's place, the failures followed, when each kind was told, chains, logins, array lines, the boot seen */
function watchmanNightState(array $day, array $n, int $now): array
{
    if (is_array($n['syslog'] ?? null)) {
        $day['syslog'] = $n['syslog'];
    }
    if (is_array($n['fails'] ?? null)) {
        $day['fails'] = $n['fails'];
    }
    foreach (['notified', 'chains'] as $part) {
        foreach ((array) ($n[$part] ?? []) as $k => $v) {
            $day[$part][$k] = max((int) ($day[$part][$k] ?? 0), (int) $v);
        }
    }
    if ((int) ($n['last_notify']['time'] ?? 0) > (int) ($day['last_notify']['time'] ?? 0)) {
        $day['last_notify'] = $n['last_notify'];
    }
    $day['logins'] = watchmanRecentLogins(array_merge((array) ($day['logins'] ?? []), (array) ($n['logins'] ?? [])), [], $now);
    $day['array_seen'] = max((int) ($day['array_seen'] ?? 0), (int) ($n['array_seen'] ?? 0));
    if (is_string($n['boot_seen'] ?? null) && $n['boot_seen'] !== '') {
        $day['boot_seen'] = $n['boot_seen'];        // the night booked the server's start: never again by the day
    }
    if (is_array($n['night'] ?? null)) {
        $day['night'] = ['since' => (int) ($n['night']['since'] ?? 0), 'until' => $now, 'from' => (string) ($n['night']['from'] ?? '')];
    }
    return $day;
}

// ===================================================================== the array: stopped, started

/**
 * The successful logins of the last WATCH_ARRAY_BEFORE (and a round), one per address, user and service with its
 * newest time — what an array line names. $events: a round's login events; $old: the list so far.
 *
 * @return list<array{t: int, ip: string, user: ?string, service: string}>
 */
function watchmanRecentLogins(array $old, array $events, int $now): array
{
    $list = $old;
    foreach ($events as $ev) {
        if (!empty($ev['ok'])) {
            $list[] = ['t' => (int) $ev['time'], 'ip' => $ev['ip'], 'user' => $ev['user'], 'service' => $ev['service']];
        }
    }
    $by = [];
    foreach ($list as $l) {
        $t = (int) ($l['t'] ?? 0);
        if (!is_array($l) || !is_string($l['ip'] ?? null) || $t < $now - WATCH_ARRAY_BEFORE - WATCH_EVERY || $t > $now + 60) {
            continue;
        }
        $user = is_string($l['user'] ?? null) ? $l['user'] : null;
        $k = $l['ip'] . '|' . $user . '|' . (string) ($l['service'] ?? '');
        if (!isset($by[$k]) || $by[$k]['t'] < $t) {
            $by[$k] = ['t' => $t, 'ip' => (string) $l['ip'], 'user' => $user, 'service' => (string) ($l['service'] ?? '')];
        }
    }
    usort($by, fn ($a, $b) => $b['t'] <=> $a['t']);
    return array_slice(array_values($by), 0, WATCH_LOGINS_MAX);
}

/** The array's stops and starts the event scripts noted ("<time> stop|start", agent.sh array), oldest first */
function watchmanArrayEvents(string $file): array
{
    $out = [];
    foreach (array_slice(explode("\n", (string) @file_get_contents($file, false, null, 0, 65536)), -100) as $line) {
        if (preg_match('/^(\d{9,11}) (stop|start)$/D', trim($line), $m)) {
            $out[] = [(int) $m[1], $m[2]];
        }
    }
    usort($out, fn ($a, $b) => $a[0] <=> $b[0]);
    return $out;
}

/**
 * The array stopped or started since the last line: a plain line in the book each (noted by himself, `by` array) —
 * when, and who had logged in to the WebGUI or by SSH around then, as the syslog says (it doesn't say who clicked).
 * @return list<string>  the kinds written
 */
function watchmanArrayLines(array &$book, array &$st, string $file, int $now): array
{
    $seen = (int) ($st['array_seen'] ?? 0);
    $added = [];
    foreach (watchmanArrayEvents($file) as [$t, $what]) {
        if ($t <= $seen || $t > $now + 60) {
            continue;
        }
        $kind = $what === 'stop' ? 'array_stop' : 'array_start';
        $e = watchmanEntry($kind, "$kind:$t", $t, ['logins' => watchmanLoginsAround($st, $t)]);
        $e['noted'] = $now;
        $e['by'] = 'array';
        $book[] = $e;
        $added[] = $kind;
        $seen = $t;
    }
    $st['array_seen'] = $seen;
    return $added;
}

/** The WebGUI/SSH logins he remembers (state.json logins) from WATCH_ARRAY_BEFORE before $t to WATCH_ARRAY_AFTER after it, oldest first */
function watchmanLoginsAround(array $st, int $t): array
{
    $logins = array_values(array_filter((array) ($st['logins'] ?? []),
        fn ($l) => is_array($l) && (int) ($l['t'] ?? 0) >= $t - WATCH_ARRAY_BEFORE && (int) ($l['t'] ?? 0) <= $t + WATCH_ARRAY_AFTER));
    usort($logins, fn ($a, $b) => $a['t'] <=> $b['t']);
    return array_slice($logins, -WATCH_LIST_MAX);
}

/** When the server was started: the kernel's btime in /proc/stat, or null */
function watchmanBootTime(string $stat = '/proc/stat'): ?int
{
    return preg_match('/^btime (\d{9,11})$/m', (string) @file_get_contents($stat, false, null, 0, 65536), $m) ? (int) $m[1] : null;
}

/**
 * The server was started since his last round — another boot id than the one he kept (`boot_seen`; up to 1.30 the
 * syslog position's): a plain line in the book, noted by himself (`by` array) like the array's — when ($btime, else
 * now), and who had logged in around then. A reboot leaves no array_stop line (the event scripts' clock lies in RAM),
 * so this one says the server went down and came up. Once per boot id (its key); the night shift books it at its first
 * round after a boot and the handover carries `boot_seen`, so the day never books it again. Unknown before (an old
 * state, no boot id): nothing — only remembered.
 *
 * @return list<string>  the kinds written
 */
function watchmanBootLine(array &$book, array $st, string $boot, ?int $btime, int $now): array
{
    $before = is_string($st['boot_seen'] ?? null) ? $st['boot_seen'] : (is_string($st['syslog']['boot'] ?? null) ? $st['syslog']['boot'] : '');
    if ($boot === '' || $before === '' || $before === $boot) {
        return [];
    }
    $key = "server_boot:$boot";
    foreach ($book as $e) {
        if (($e['key'] ?? '') === $key) {
            return [];
        }
    }
    $t = $btime !== null && $btime > 0 && $btime <= $now + 60 ? $btime : $now;
    $e = watchmanEntry('server_boot', $key, $t, ['logins' => watchmanLoginsAround($st, $t)]);
    $e['noted'] = $now;
    $e['by'] = 'array';
    $book[] = $e;
    return ['server_boot'];
}

/** Who had logged in around an array stop or start, in names: "root@192.168.7.125 (WebGUI)" … or – */
function watchmanArrayWho(array $logins): string
{
    $out = [];
    foreach ($logins as $l) {
        $out[] = (is_string($l['user'] ?? null) ? $l['user'] . '@' : '') . (string) ($l['ip'] ?? '?') . ' (' . watchmanServiceName((string) ($l['service'] ?? '')) . ')';
    }
    return $out ? implode(', ', array_values(array_unique($out))) : '–';
}

// ===================================================================== a round

/**
 * One round: read what is new (outside the lock, it takes a moment), then —
 * holding the book — compare it with what is normal now and write it down.
 * $hired: since when he works here (another one than the baseline's: he
 * takes over the watch anew). $docker: a stand-in for docker inspect (tests);
 * $acks: the team lead's notes (tests); $notify false: tell nobody. $flow: the
 * data flow's look (watchmanFlowLook(), given the containers with their main
 * process); without it the data flow is left out. $snaps: the snapshot watch
 * (watchmanSnaps(), with $paths' zfs, zpool, mnt, agent_log and engine) — left
 * out without it.
 *
 * @return array{fresh: bool, added: list<string>, told: list<array>, summary: array}
 */
function watchmanRound(array $paths, string $dir, int $hired, ?int $now = null, ?callable $docker = null, bool $notify = true, ?string $acks = null,
                       ?callable $flow = null, bool $snaps = false): array
{
    $t0 = microtime(true);
    $now ??= time();
    $snap = watchmanLoad($dir);
    $fresh = !is_array($snap['baseline']) || (int) ($snap['baseline']['hired'] ?? -1) !== $hired;
    $known = watchmanKnownUsers($paths['etc_passwd']);
    // a position of another boot (the syslog is new at every boot, an inode may come again): this boot's syslog from its start
    $boot = isset($paths['boot_id']) ? watchmanBootId((string) $paths['boot_id']) : '';
    $from = $fresh ? null : ($snap['state']['syslog'] ?? null);
    if (is_array($from) && $boot !== '' && isset($from['boot']) && $from['boot'] !== $boot) {
        $from = ['ino' => -1, 'size' => 0];
    }
    [$events, $pos, $read] = watchmanReadLogins($paths['syslog'], $from, $fresh, $known, $now);
    if (is_array($pos) && $boot !== '') {
        $pos['boot'] = $boot;
    }
    $containers = $docker ? $docker() : watchmanContainers();
    $look = $flow ? $flow($containers) : null;
    $prevSeen = readJson("$dir/seen.json") ?? [];      // the last round's look: a file the same as then isn't read again
    // with the containers' main processes (whose programs run where)
    $host = watchmanHost($paths, $containers, is_array($prevSeen['host'] ?? null) ? $prevSeen['host'] : null);
    // the network (agent/lib/watchnet.php): the router files read here, judged in the lock — never in the night shift (no rsyslog_cfg)
    $netServer = $netLook = null;
    if (isset($paths['rsyslog_cfg'])) {
        $netServer = watchnetServer($paths, $containers);
        $netLook = watchnetLook($paths, $fresh ? null : readJson("$dir/net.json"), $netServer, $now);
    }
    if (is_array($containers)) {
        if (isset($paths['docker_autostart'])) {
            $containers = watchmanContainerStarts($containers, $paths);      // a privileged one: runs, starts by itself, or neither
        }
        $containers = array_map(fn ($c) => array_diff_key((array) $c, ['pid' => true, 'addrs' => true, 'macs' => true, 'ds' => true, 'restart' => true, 'compose' => true]),
            $containers);     // the data flow's and the network's only (restart, compose: watchmanContainerStarts()'s)
    }
    $seen = [
        'containers' => $containers,
        'plugins'    => watchmanPlugins($paths['plugins']),
        'flash'      => watchmanFlash($paths),
        'shares'     => watchmanShares($paths),
        'sched'      => watchmanSched($paths, (array) ($prevSeen['sched'] ?? []), $now),
        'host'       => $host,
        'partner'    => watchmanPartnerLook($paths),
    ];
    $office = watchmanOfficeLook($paths, $now);
    $facts = watchmanPostureLook($paths);
    // snapshots that vanish: only rounds write snaps.json (one at a time), so it is read out here
    $snapKnown = $snaps ? readJson("$dir/snaps.json") : null;
    $snapRes = $snaps ? watchmanSnaps($paths, $fresh ? null : $snapKnown, $fresh ? [] : (array) ($snap['baseline']['snaps']['series'] ?? []), $now) : null;

    $btime = $boot !== '' && isset($paths['stat']) ? watchmanBootTime((string) $paths['stat']) : null;
    // why a parity check runs (agent/lib/paritywhy.php) — the day's rounds only (the night shift has no parity paths)
    $parity = paritywhyLook($paths, is_array($snap['state']['parity'] ?? null) ? $snap['state']['parity'] : null, $boot, $btime, $now);

    return watchmanLocked($dir, function () use ($dir, $hired, $now, $fresh, $events, $pos, $read, $seen, $notify, $acks, $t0, $look, $facts, $snapKnown, $snapRes,
                                               $office, $paths, $boot, $btime, $netLook, $netServer, $parity): array {
        $old = watchmanLoad($dir);
        $old['seen'] = readJson("$dir/seen.json");
        $old['flow'] = readJson("$dir/flow.json");
        $b = $old['baseline'];
        $book = $old['book'];
        $st = $old['state'];
        // what Docker didn't answer this time stays as the last round saw it
        $observed = $seen;
        if ($observed['containers'] === null) {
            $observed['containers'] = $old['seen']['containers'] ?? null;
        }
        if ($observed['shares'] === null) {
            $observed['shares'] = $old['seen']['shares'] ?? null;
        }
        if (is_array($observed['partner']) && $observed['partner']['pairs'] === null) {
            $observed['partner']['pairs'] = $old['seen']['partner']['pairs'] ?? null;    // the pairs not looked at (the night): as the last round saw them
        }
        $observed['host'] = watchmanHostObserved($seen['host'], $old['seen']['host'] ?? null);
        $added = $told = [];
        $before = array_flip(array_column($book, 'id'));
        if ($fresh) {
            [$b, $book, $st] = watchmanTakeOver($hired, $events, $seen, $book, $st, $now);
        } else {
            // a part missing from his baseline (a file by hand, an older one): what he sees now is normal there
            $b['ips'] = (array) ($b['ips'] ?? []);
            $b['fail_ips'] = (array) ($b['fail_ips'] ?? []);
            $b['plugins'] = is_array($b['plugins'] ?? null) ? $b['plugins'] : array_map(fn ($p) => $p + ['seen' => $now], $seen['plugins']);
            $b['flash'] = is_array($b['flash'] ?? null) ? $b['flash'] + ['go' => null, 'extra' => [], 'users' => [], 'pw' => [], 'keys' => []] : $seen['flash'];
            $b['containers'] = is_array($b['containers'] ?? null) ? $b['containers'] : null;
            $b['shares'] = is_array($b['shares'] ?? null) ? $b['shares'] : null;
            $b['sched'] = is_array($b['sched'] ?? null) ? $b['sched'] : null;
            $b['snaps'] = is_array($b['snaps'] ?? null) ? $b['snaps'] : ['series' => []];
            watchmanTeamLeadNotes($b, $book, is_array($old['seen']) ? $old['seen'] : $observed, $now, $acks);
            // partner doors: the office's own new line is noted (and its key known) before the flash watch looks at the keys
            $b['partner'] = watchmanPartnerBase($b['partner'] ?? null);
            watchmanPartnerAdopt($b, $seen['partner'], $book, $now, (array) ($seen['flash']['keys']['root'] ?? []));
            $doors = watchmanPartnerDoors($b['partner'], $seen['partner']);
            if (is_array($seen['partner']['pairs'] ?? null)) {
                $b['partner']['pairs'] = $doors;            // for the night shift, which can't read the pairs
            }
            $doors += watchmanTicketDoors($seen['partner']);
            $added = array_merge(
                watchmanLogins($b, $book, $st, $events, false, $doors),
                watchmanContainersCompare($b['containers'], $seen['containers'], $book, $now, $office),
                watchmanPluginsCompare($b['plugins'], $seen['plugins'], $book, $now, $office),
                watchmanFlashCompare($b['flash'], $seen['flash'], $book, $now, isset($paths['go']) ? (string) $paths['go'] : null),
                watchmanSharesCompare($b['shares'], $seen['shares'], $book, $now),
                watchmanSchedCompare($b['sched'], $seen['sched'], $seen['plugins'], $book, $now, $office),
                watchmanHostCompare($b['host'], $seen['host'], is_array($old['seen']) ? ($old['seen']['host'] ?? null) : null,
                    (array) ($seen['flash']['users'] ?? []), $book, $now),
                watchmanPartnerCompare($b, $seen['partner'], $book, $st, $now),
            );
        }
        $flow = null;
        if ($look !== null) {
            // the data flow: the last round's counters (RAM) against this look; taken over anew, it starts learning anew
            $b['flow'] = $fresh ? null : (is_array($b['flow'] ?? null) ? $b['flow'] : null);
            if ($fresh) {
                $none = [];
                [, $flow, $counters] = watchmanFlowCompare($b['flow'], [], null, $look, $none, $now);
            } else {
                [$more, $flow, $counters] = watchmanFlowCompare($b['flow'], (array) ($old['flow'] ?? []), watchmanFlowCounters($dir, $now), $look, $book, $now);
                $added = array_merge($added, $more);
            }
            writeAtomic(watchmanFlowCountersFile($dir), jsonEncode($counters), 0600, 0, 0);
            $st['flow'] = watchmanFlowTotals($flow);
        }
        $net = null;
        if ($netLook !== null) {
            // the network: what the router said since the last round (taken over anew, or a router seen for the first time: learned)
            $net = $fresh ? [] : (readJson("$dir/net.json") ?? []);
            $old['net'] = $net;
            $b['net'] = $fresh ? null : ($b['net'] ?? null);
            $added = array_merge($added, watchnetCompare($b['net'], $netLook, $book, $net, $netServer, $now, $fresh, !empty($st['net_lan'])));
            $observed['net'] = ['clock' => watchnetClock($net)];
        }
        if ($snapRes !== null) {
            // snapshots gone or released that the office didn't do (taken over anew: all of it normal)
            $b['snaps'] = is_array($b['snaps'] ?? null) ? $b['snaps'] : ['series' => []];
            if (!$fresh) {
                $added = array_merge($added, watchmanSnapCompare($b['snaps'], $snapRes, $book, $now));
            }
            $st['snaps'] = $snapRes['summary'];
        }
        // the logins of the last half hour (for the array's lines); an array stopped or started since the last round: a plain line
        $st['logins'] = watchmanRecentLogins((array) ($st['logins'] ?? []), $events, $now);
        // the server started since the last round (a reboot leaves no array line: WATCH_ARRAY_EVENTS lies in RAM) — never at his first
        if (!$fresh) {
            watchmanBootLine($book, $st, $boot, $btime, $now);
        }
        if ($boot !== '') {
            $st['boot_seen'] = $boot;
        }
        if ($fresh) {
            $st['array_seen'] = max((int) ($st['array_seen'] ?? 0), $now);     // what came before he took over isn't his
        } elseif (isset($paths['array_events'])) {
            watchmanArrayLines($book, $st, (string) $paths['array_events'], $now);
        }
        // a parity check began: its reason; an unclean stop: the team lead's to-do and one notification (normal) — his switch counts
        $tell = $notify && ($st['notify'] ?? true) !== false
            ? fn (array $v): bool => ($v['btime'] === null || $now - (int) $v['btime'] <= 86400) && paritywhyNotify($v, officeNotifyLang()) : null;
        $added = array_merge($added, paritywhyCompare($book, $st, $parity, (array) ($st['logins'] ?? []), $now, $fresh,
            isset($paths['array_events']) ? (string) $paths['array_events'] : null, $tell));
        // how secure it stands: from what he sees now (what Docker or emhttp didn't answer: as the last round saw it)
        $st['posture'] = ['time' => $now, 'tips' => watchmanPosture($facts, $observed, (array) ($st['posture']['tips'] ?? []))];
        $old['posture'] = readJson("$dir/posture.json");
        $known = watchmanPostureKnown($old['posture'], $st['posture']['tips']);
        watchmanTidy($b, $st, $now);
        $book = watchmanPrune($book, $now);
        if (!$fresh) {
            $told = watchmanNotifyDue($book, $st, $now, $notify);
            $told = array_merge($told, watchmanChainsDue($book, $st, $now, $notify));
        }
        $st['hired'] = $hired;
        $st['syslog'] = $pos;
        $st['open'] = watchmanOpenCounts($book);
        $st['round'] = ['time' => $now, 'duration_ms' => (int) round((microtime(true) - $t0) * 1000), 'failed' => false,
                        'read' => $read['read'], 'skipped' => $read['skipped'], 'rotated' => $read['rotated'],
                        'docker' => $seen['containers'] !== null, 'shares' => $seen['shares'] !== null, 'flow' => $look !== null,
                        'snaps' => $snapRes !== null, 'added' => count($added)];
        if (is_array($st['night'] ?? null) && !isset($st['night']['until'])) {
            // the night shift's own state (the day's `night` has `until`): its rounds and what is new in it and open — the
            // office's page and the Dashboard tile say so while the array is stopped (officeNightShift() in src/mailbox.php)
            $st['night']['rounds'] = (int) ($st['night']['rounds'] ?? 0) + 1;
            $st['night']['new'] = count(array_filter($book, fn ($e) => watchmanOpen($e) && !isset($e['stub'])));
        }
        $old['snaps'] = $snapKnown;
        watchmanSave($dir, $old, ['baseline' => $b, 'book' => $book, 'state' => $st, 'seen' => $observed, 'flow' => $flow, 'posture' => $known,
                                  'snaps' => $snapRes['known'] ?? null, 'net' => $net]);
        if (!$fresh && !empty($st['siem']) && isset($paths['logger'])) {
            watchmanSyslogForward((string) $paths['logger'], array_values(array_filter($book, fn ($e) => !isset($before[$e['id']]) && $e['kind'] !== 'watch')),
                array_values(array_filter($told, fn ($t) => $t['kind'] === 'chain')));
        }
        return ['fresh' => $fresh, 'added' => array_values($added), 'told' => $told, 'summary' => watchmanCounts($b)];
    });
}

/**
 * The first round after hiring: everything he sees is normal — the login
 * addresses in the syslog's history too, and addresses that already had a
 * burst of failures. What was still open in his book from an earlier watch
 * is closed; one line says he took over.
 */
function watchmanTakeOver(int $hired, array $events, array $seen, array $book, array $st, int $now): array
{
    $b = ['hired' => $hired, 'time' => $now, 'ips' => [], 'fail_ips' => [], 'containers' => null, 'plugins' => [],
          'flash' => $seen['flash'], 'shares' => null, 'sched' => null, 'snaps' => ['series' => []]];
    $st['fails'] = [];
    $st['notified'] = [];
    $none = [];
    // the partner doors as they are: the office's lines, the pairs, the refusals so far — all normal
    $b['partner'] = watchmanPartnerBase(null);
    foreach ((array) ($seen['partner']['lines'] ?? []) as $id => $l) {
        $b['partner']['lines'][$id] = watchmanPartnerLineKeep($l);
    }
    $b['partner']['pairs'] = watchmanPartnerDoors($b['partner'], $seen['partner'] ?? null);
    $st['partner_refused'] = array_map(fn ($r) => (int) max([0, ...$r['times']]), (array) ($seen['partner']['refused'] ?? []));
    watchmanLogins($b, $none, $st, $events, true, $b['partner']['pairs']);
    if ($seen['containers'] !== null) {
        $b['containers'] = array_map(fn ($c) => ['tokens' => $c['tokens'], 'seen' => $now], $seen['containers']);
    }
    $b['plugins'] = array_map(fn ($p) => $p + ['seen' => $now], $seen['plugins']);
    if ($seen['shares'] !== null) {
        $b['shares'] = array_map(fn ($s) => $s + ['seen' => $now], $seen['shares']);
    }
    watchmanSchedCompare($b['sched'], $seen['sched'] ?? null, $seen['plugins'], $none, $now);     // all of it normal
    $b['host'] = null;
    watchmanHostCompare($b['host'], $seen['host'] ?? null, null, (array) ($seen['flash']['users'] ?? []), $none, $now);     // likewise
    foreach ($book as $i => $e) {
        if (watchmanOpen($e)) {
            $book[$i]['noted'] = $now;
            $book[$i]['by'] = 'baseline';
        }
    }
    $book[] = watchmanEntry('watch', 'watch', $now, watchmanCounts($b));
    return [$b, $book, $st];
}

/** What he knows, counted (the "took over" line, the log) */
function watchmanCounts(array $b): array
{
    $containers = (array) ($b['containers'] ?? []);
    $shares = (array) ($b['shares'] ?? []);
    return [
        'ips'        => count((array) ($b['ips'] ?? [])),
        'containers' => count($containers),
        'special'    => count(array_filter($containers, fn ($c) => watchmanRights((array) ($c['tokens'] ?? [])) !== [])),
        'plugins'    => count((array) ($b['plugins'] ?? [])),
        'shares'     => count($shares),
        'open'       => count(array_filter($shares, fn ($s) => ($s['smb'] ?? 0) > 0 || ($s['nfs'] ?? 0) > 0)),
    ];
}

function watchmanSummaryLine(array $c): string
{
    $w = watchmanCountWords($c, 'en');
    return "{$w['ips']}, {$w['containers']} ({$w['special']} with special rights), {$w['plugins']}, {$w['shares']} ({$w['open']} open to guests)";
}

/** The counts of «Took over the watch» in words of $lang — «1 login address», «3 containers» (count.*: one/other) */
function watchmanCountWords(array $c, string $lang): array
{
    $w = fn (string $what, string $k) => officeNotifyText('watchman', "count.$what", ['n' => (int) ($c[$k] ?? 0)], $lang);
    return ['ips' => $w('login_addresses', 'ips'), 'containers' => $w('containers', 'containers'), 'special' => (int) ($c['special'] ?? 0),
            'plugins' => $w('plugins', 'plugins'), 'shares' => $w('shares', 'shares'), 'open' => (int) ($c['open'] ?? 0)];
}

/** Keeps his memory small: old failure trackers, too many addresses, what is gone for long */
function watchmanTidy(array &$b, array &$st, int $now): void
{
    $fails = (array) ($st['fails'] ?? []);
    foreach ($fails as $ip => $tr) {
        if (!is_array($tr) || !$tr['t'] || max($tr['t']) < $now - WATCH_FAIL_WINDOW) {
            unset($fails[$ip]);
        }
    }
    if (count($fails) > WATCH_TRACK_MAX) {
        uasort($fails, fn ($x, $y) => max($y['t']) <=> max($x['t']));
        $fails = array_slice($fails, 0, WATCH_TRACK_MAX, true);
    }
    $st['fails'] = $fails;
    if (count((array) ($b['ips'] ?? [])) > WATCH_IPS_MAX) {
        uasort($b['ips'], fn ($x, $y) => ($y['last'] ?? 0) <=> ($x['last'] ?? 0));
        $b['ips'] = array_slice($b['ips'], 0, WATCH_IPS_MAX, true);
    }
    if (count((array) ($b['fail_ips'] ?? [])) > WATCH_IPS_MAX) {
        uasort($b['fail_ips'], fn ($x, $y) => ($y['last'] ?? 0) <=> ($x['last'] ?? 0));
        $b['fail_ips'] = array_slice($b['fail_ips'], 0, WATCH_IPS_MAX, true);
    }
}

// ===================================================================== the watch book

function watchmanEntry(string $kind, string $key, int $time, array $p, int $count = 1): array
{
    return ['id' => 'w' . bin2hex(random_bytes(5)), 'kind' => $kind, 'key' => $key, 'time' => $time, 'last' => $time,
            'count' => $count, 'p' => $p, 'noted' => null, 'by' => null, 'told' => null];
}

/** Not noted yet (the "took over" lines never are open) */
function watchmanOpen(array $e): bool
{
    return isset(WATCH_KINDS[$e['kind'] ?? '']) && empty($e['noted']);
}

/**
 * A state that differs (a container's rights, a plugin, a file): one open
 * entry per key — seen again, it is only brought up to date.
 * @return string|null  the kind when the entry is new
 */
function watchmanSet(array &$book, string $kind, string $key, int $now, array $p): ?string
{
    foreach ($book as $i => $e) {
        if (($e['key'] ?? '') === $key && watchmanOpen($e)) {
            if (($e['p'] ?? []) !== $p) {
                $book[$i]['p'] = $p;
                $book[$i]['last'] = $now;
            }
            return null;
        }
    }
    $book[] = watchmanEntry($kind, $key, $now, $p);
    return $kind;
}

/**
 * Something that happened (a login, failed logins): while its entry is open,
 * again counts up there.
 * @return string|null  the kind when the entry is new
 */
function watchmanBump(array &$book, string $kind, string $key, int $time, int $add, array $p): ?string
{
    foreach ($book as $i => $e) {
        if (($e['key'] ?? '') === $key && watchmanOpen($e)) {
            $book[$i]['count'] = (int) $e['count'] + $add;
            $book[$i]['last'] = max((int) $e['last'], $time);
            $book[$i]['time'] = min((int) $e['time'], $time);
            $book[$i]['p'] = watchmanMerge((array) $e['p'], $p);
            return null;
        }
    }
    $book[] = watchmanEntry($kind, $key, $time, $p, $add);
    return $kind;
}

/** Lists joined (at most WATCH_LIST_MAX), counts added, the rest from $b */
function watchmanMerge(array $a, array $b): array
{
    foreach ($b as $k => $v) {
        if (is_array($v)) {
            $a[$k] = array_slice(array_values(array_unique(array_merge((array) ($a[$k] ?? []), $v))), 0, WATCH_LIST_MAX);
        } elseif (is_int($v) && is_int($a[$k] ?? null)) {
            $a[$k] += $v;
        } else {
            $a[$k] = $v;
        }
    }
    return $a;
}

/** Noted entries go after WATCH_BOOK_DAYS; beyond WATCH_BOOK_MAX the oldest noted first, then the oldest */
function watchmanPrune(array $book, int $now): array
{
    $book = array_values(array_filter($book, fn ($e) => watchmanOpen($e) || $now - (int) ($e['noted'] ?? $e['last'] ?? 0) <= WATCH_BOOK_DAYS * 86400));
    while (count($book) > WATCH_BOOK_MAX) {
        $drop = 0;
        foreach ($book as $i => $e) {
            if (!watchmanOpen($e)) {
                $drop = $i;
                break;
            }
        }
        array_splice($book, $drop, 1);
    }
    return $book;
}

/** @return array<string, int>  kind => entries not noted yet */
function watchmanOpenCounts(array $book): array
{
    $n = [];
    foreach ($book as $e) {
        if (watchmanOpen($e)) {
            $n[$e['kind']] = ($n[$e['kind']] ?? 0) + 1;
        }
    }
    return $n;
}

// ===================================================================== logins

/** The names of this server's users (/etc/passwd): only those are kept from failed logins */
function watchmanKnownUsers(string $file): array
{
    $names = [];
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $name = strtok($line, ':');
        if (is_string($name) && preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,31}$/D', $name)) {
            $names[$name] = true;
        }
    }
    return $names;
}

/**
 * Login lines of the syslog since $pos ({ino, size}). Rotated meanwhile
 * (another inode, or shorter): the rest of the old file first (syslog.1 or
 * .2, found by its inode), then the new one from its start. $learn (taking
 * over the watch): syslog.1 and syslog, all of it. Without a position and not
 * learning: from now on. At most WATCH_READ_MAX / WATCH_LEARN_MAX of a file
 * (its newest part); only whole lines — an unfinished one waits.
 *
 * @return array{0: list<array>, 1: ?array, 2: array{read: int, skipped: int, rotated: bool}}  events, new position, what was read
 */
function watchmanReadLogins(string $syslog, ?array $pos, bool $learn, array $known, int $now): array
{
    $events = [];
    $info = ['read' => 0, 'skipped' => 0, 'rotated' => false];
    clearstatcache();
    $st = @stat($syslog);
    if (!$st) {
        return [[], $pos, $info];
    }
    $max = $learn ? WATCH_LEARN_MAX : WATCH_READ_MAX;
    $from = 0;
    if ($learn) {
        if (is_file("$syslog.1")) {
            watchmanReadFile("$syslog.1", 0, $max, $known, $now, $events, $info);
        }
    } elseif ($pos === null) {
        $from = (int) $st['size'];
    } elseif ((int) ($pos['ino'] ?? -1) === (int) $st['ino'] && (int) ($pos['size'] ?? PHP_INT_MAX) <= (int) $st['size']) {
        $from = (int) $pos['size'];
    } else {
        $info['rotated'] = true;
        foreach (["$syslog.1", "$syslog.2"] as $older) {
            $o = @stat($older);
            if ($o && (int) $o['ino'] === (int) ($pos['ino'] ?? -1)) {
                if ((int) ($pos['size'] ?? PHP_INT_MAX) <= (int) $o['size']) {
                    watchmanReadFile($older, (int) $pos['size'], $max, $known, $now, $events, $info);
                }
                break;
            }
        }
    }
    $end = $from === (int) $st['size'] && !$learn && $pos === null
        ? $from
        : watchmanReadFile($syslog, $from, $max, $known, $now, $events, $info);
    return [$events, ['ino' => (int) $st['ino'], 'size' => $end], $info];
}

/** Whole lines of $path from $from (more than $max: the newest part), login lines as events; where the last whole line ends */
function watchmanReadFile(string $path, int $from, int $max, array $known, int $now, array &$events, array &$info): int
{
    $h = @fopen($path, 'r');
    if (!$h) {
        return $from;
    }
    $size = (int) (fstat($h)['size'] ?? 0);
    $start = max($from, $size - $max);
    if ($start > $from) {
        $info['skipped'] += $start - $from;
        fseek($h, $start - 1);
        if (fgetc($h) !== "\n") {
            fgets($h);              // began inside a line
        }
    } else {
        fseek($h, $start);
    }
    $at = (int) ftell($h);
    $first = $at;
    while (($line = fgets($h)) !== false) {
        if (!str_ends_with($line, "\n")) {
            break;                  // still being written: the next round reads it
        }
        $at += strlen($line);
        if (str_contains($line, 'sshd') || stripos($line, 'webgui') !== false) {
            $ev = watchmanParseLine(rtrim($line, "\r\n"), $known, $now);
            if ($ev) {
                $events[] = $ev;
            }
        }
    }
    fclose($h);
    $info['read'] += $at - $first;
    return $at;
}

/**
 * One syslog line → a login event, or null:
 * ['ok' => bool, 'service' => 'web'|'ssh:<method>'|'ssh', 'user' => ?string, 'ip' => string, 'time' => int].
 * Failed logins keep the name only when it is a user of this server
 * ($known); "Failed … for invalid user" is left out (its "Invalid user" line
 * counts, once per connection).
 */
function watchmanParseLine(string $line, array $known, int $now): ?array
{
    $ev = null;
    if (preg_match(WATCH_WEB, $line, $m)) {
        $ok = strcasecmp($m[1], 'Successful') === 0;
        $ev = ['ok' => $ok, 'service' => 'web', 'user' => $m[2], 'ip' => $m[3]];
    } elseif (preg_match(WATCH_SSH_OK, $line, $m)) {
        $ev = ['ok' => true, 'service' => 'ssh:' . strtolower($m[1]), 'user' => $m[2], 'ip' => $m[3]];
        // the key's fingerprint (sshd: "… ssh2: ED25519 SHA256:…"): a partner's door knows its key (watchmanLogins())
        if (preg_match('# ssh2: [A-Z0-9-]{2,30} (SHA256:[A-Za-z0-9+/]{43})(?:\s|$)#', $line, $k)) {
            $ev['fp'] = $k[1];
        }
    } elseif (preg_match(WATCH_SSH_FAIL, $line, $m)) {
        if ($m[2] !== '') {
            return null;
        }
        $ev = ['ok' => false, 'service' => 'ssh:' . strtolower($m[1]), 'user' => $m[3], 'ip' => $m[4]];
    } elseif (preg_match(WATCH_SSH_INVALID, $line, $m)) {
        $ev = ['ok' => false, 'service' => 'ssh', 'user' => null, 'ip' => $m[2]];
    }
    if ($ev === null) {
        return null;
    }
    $ip = watchmanIp($ev['ip']);
    if ($ip === null) {
        return null;
    }
    $ev['ip'] = $ip;
    $user = $ev['user'];
    $ev['user'] = is_string($user) && preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.@-]{0,31}$/D', $user) && ($ev['ok'] || isset($known[$user])) ? $user : null;
    if (!preg_match('/^(?:web|ssh(?::[a-z0-9\/-]{1,40})?)$/D', $ev['service'])) {
        $ev['service'] = str_starts_with($ev['service'], 'ssh') ? 'ssh' : 'web';
    }
    $ev['time'] = watchmanLineTime($line, $now) ?? $now;
    return $ev;
}

/** An address as it is written everywhere: IPv4 plain (also from ::ffff:a.b.c.d), IPv6 short; null when it is none */
function watchmanIp(string $s): ?string
{
    $s = rtrim(trim($s, '[]'), '.');
    $s = preg_replace('/%[\w.-]+$/', '', $s) ?? $s;
    if (filter_var($s, FILTER_VALIDATE_IP) === false) {
        return null;
    }
    $bin = @inet_pton($s);
    if ($bin === false) {
        return null;
    }
    if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
        $bin = substr($bin, 12);
    }
    return inet_ntop($bin) ?: null;
}

/** The time at the start of a syslog line (no year in it: this year, or last year for December read in January) */
function watchmanLineTime(string $line, int $now): ?int
{
    if (preg_match(WATCH_TIME_SYSLOG, $line, $m)) {
        $year = (int) date('Y', $now);
        $t = strtotime("$m[1] $m[2] $year $m[3]");
        if ($t !== false && $t > $now + 86400) {
            $t = strtotime("$m[1] $m[2] " . ($year - 1) . " $m[3]");
        }
        return $t === false ? null : $t;
    }
    if (preg_match(WATCH_TIME_ISO, $line, $m)) {
        $t = strtotime("$m[1] $m[2]" . (isset($m[3]) && $m[3] !== '' ? " $m[3]" : ''));
        return $t === false ? null : $t;
    }
    return null;
}

/**
 * A failed login in its address's tracker: how many failures to add to a
 * burst — 0 while fewer than $burst within $window, all of the window's when
 * the burst starts, 1 for each one after it (until $window passes without one).
 */
function watchmanFailStep(array &$fails, array $ev, int $burst = WATCH_FAIL_BURST, int $window = WATCH_FAIL_WINDOW): int
{
    $ip = $ev['ip'];
    $t = (int) $ev['time'];
    $tr = is_array($fails[$ip] ?? null) ? $fails[$ip] : null;
    if ($tr === null || !$tr['t'] || $t - max($tr['t']) > $window) {
        $tr = ['t' => [], 'burst' => false, 'users' => [], 'services' => [], 'unknown' => 0];     // a pause: anew
    }
    $tr['t'] = array_values(array_filter($tr['t'], fn ($x) => $x > $t - $window));
    $tr['t'][] = $t;
    if ($ev['user'] !== null) {
        $tr['users'] = array_slice(array_values(array_unique(array_merge($tr['users'], [$ev['user']]))), 0, WATCH_LIST_MAX);
    } else {
        $tr['unknown']++;
    }
    $tr['services'] = array_slice(array_values(array_unique(array_merge($tr['services'], [$ev['service']]))), 0, WATCH_LIST_MAX);
    if ($tr['burst']) {
        $add = 1;
    } elseif (count($tr['t']) >= $burst) {
        $tr['burst'] = true;
        $add = count($tr['t']);
    } else {
        $add = 0;
    }
    $tr['t'] = array_slice($tr['t'], -$burst);           // enough to tell the next burst
    $fails[$ip] = $tr;
    return $add;
}

/**
 * The login events of a round. $learn (taking over): every address is
 * normal, so is one that had a burst. Otherwise: a login from an address he
 * doesn't know, a burst from one that isn't known for failing.
 * @return list<string>  the kinds of new entries
 */
function watchmanLogins(array &$b, array &$book, array &$st, array $events, bool $learn, array $doors = []): array
{
    $added = [];
    $st['fails'] = (array) ($st['fails'] ?? []);
    foreach ($events as $ev) {
        $ip = $ev['ip'];
        $time = (int) $ev['time'];
        if ($ev['ok'] && $doors && ($door = watchmanPartnerLogin($doors, $ip, $ev['fp'] ?? null)) !== null) {
            // a partner's door: its key from its address is the office's own; its key elsewhere, or its address with
            // another key, is door_key_moved — unless that pair of address and key was noted («I know, thanks»)
            if ($door['ok']) {
                continue;
            }
            $seen = $ip . '|' . ($ev['fp'] ?? '');
            if ($learn) {
                $b['partner']['logins'][$seen] = $time;
            } elseif (!isset($b['partner']['logins'][$seen])) {
                $added[] = watchmanBump($book, 'door_key_moved', "door_key_moved:{$door['id']}:$ip", $time, 1,
                    ['id' => $door['id'], 'name' => $door['name'], 'ip' => $ip, 'fp' => (string) ($ev['fp'] ?? ''), 'how' => $door['how'],
                     'ips' => $door['ips'], 'users' => array_values(array_filter([$ev['user']]))]);
                continue;
            }
        }
        if ($ev['ok']) {
            if ($learn || isset($b['ips'][$ip])) {
                $k = $b['ips'][$ip] ?? ['first' => $time, 'last' => $time, 'users' => [], 'services' => []];
                $k['first'] = min((int) $k['first'], $time);
                $k['last'] = max((int) $k['last'], $time);
                $k['users'] = watchmanMerge(['x' => (array) $k['users']], ['x' => array_filter([$ev['user']])])['x'];
                $k['services'] = watchmanMerge(['x' => (array) $k['services']], ['x' => [$ev['service']]])['x'];
                $b['ips'][$ip] = $k;
                continue;
            }
            $added[] = watchmanBump($book, 'login_new_ip', "login_new_ip:$ip", $time, 1,
                ['ip' => $ip, 'users' => array_values(array_filter([$ev['user']])), 'services' => [$ev['service']]]);
            continue;
        }
        $prev = is_array($st['fails'][$ip] ?? null) ? $st['fails'][$ip] : null;
        $was = $prev && $prev['burst'] && $prev['t'] && $time - max($prev['t']) <= WATCH_FAIL_WINDOW;      // a burst going on
        $add = watchmanFailStep($st['fails'], $ev);
        if (!$add) {
            continue;
        }
        if ($learn || isset($b['fail_ips'][$ip])) {
            $k = $b['fail_ips'][$ip] ?? ['since' => $time, 'last' => $time, 'n' => 0, 'quiet' => 0];
            $k['last'] = max((int) $k['last'], $time);
            if ($learn) {
                $k['n'] += $add;
            } else {
                $k['quiet'] = (int) ($k['quiet'] ?? 0) + $add;      // known for failing: counted, not reported
            }
            $b['fail_ips'][$ip] = $k;
            continue;
        }
        $tr = $st['fails'][$ip];
        $p = $was ? ['ip' => $ip, 'users' => array_values(array_filter([$ev['user']])), 'services' => [$ev['service']], 'unknown' => $ev['user'] === null ? 1 : 0]
                  : ['ip' => $ip, 'users' => $tr['users'], 'services' => $tr['services'], 'unknown' => (int) $tr['unknown']];
        $added[] = watchmanBump($book, 'login_failures', "login_failures:$ip", $time, $add, $p);
    }
    return array_values(array_filter($added));
}

// ===================================================================== containers

/** Every container's rights, as docker run flags; null when Docker doesn't answer */
function watchmanContainers(): ?array
{
    [$exit, $ids] = run(['docker', 'ps', '-aq', '--no-trunc'], 20);
    if ($exit !== 0) {
        return null;
    }
    $ids = array_slice(array_values(array_filter(explode("\n", trim($ids)), fn ($x) => (bool) preg_match('/^[0-9a-f]{12,64}$/D', $x))), 0, 1000);
    if (!$ids) {
        return [];
    }
    [$exit, $text] = run(array_merge(['docker', 'inspect', '--format', WATCH_INSPECT], $ids), 60);
    if (trim($text) === '') {
        return $exit === 0 ? [] : null;
    }
    $out = [];
    foreach (explode("\n", trim($text)) as $line) {
        $f = explode("\t", $line);
        if (count($f) < 4) {
            continue;
        }
        $name = ltrim((string) json_decode($f[0]), '/');
        $hc = json_decode($f[2], true);
        $mounts = json_decode($f[3], true);
        if ($name === '' || !is_array($hc)) {
            continue;
        }
        $by = json_decode($f[5] ?? 'null');
        $drill = json_decode($f[8] ?? 'null');
        $created = json_decode($f[6] ?? 'null');
        $created = is_string($created) ? strtotime((string) preg_replace('/\.\d+/', '', $created)) : false;
        $gd = json_decode($f[9] ?? 'null', true);           // Docker on ZFS: {"Dataset": "<pool>/…/<id>", "Mountpoint": …}
        $project = json_decode($f[10] ?? 'null');
        $restart = $hc['RestartPolicy']['Name'] ?? null;
        $ds = is_array($gd) && is_string($gd['Dataset'] ?? null) && preg_match(WATCH_DATASET, $gd['Dataset']) ? $gd['Dataset'] : null;
        $addrs = $macs = [];
        foreach ((array) (json_decode($f[7] ?? 'null', true) ?: []) as $nw) {
            foreach (['IPAddress', 'GlobalIPv6Address'] as $k) {
                if (is_string($nw[$k] ?? null) && $nw[$k] !== '') {
                    $addrs[] = $nw[$k];
                }
            }
            if (is_string($nw['MacAddress'] ?? null) && $nw['MacAddress'] !== '') {
                $macs[] = $nw['MacAddress'];
            }
        }
        $out[$name] = ['image' => (string) json_decode($f[1]), 'tokens' => watchmanContainerTokens($hc, is_array($mounts) ? $mounts : []),
                       'pid' => (int) ($f[4] ?? 0), 'addrs' => array_slice($addrs, 0, 8), 'macs' => array_slice($macs, 0, 8)]
                    + (is_string($by) && preg_match('/^[a-z]{1,20}$/D', $by) ? ['by' => $by] : [])
                    + (is_string($drill) && preg_match('/^\d{8}-\d{6}-[0-9a-f]{4}$/D', $drill) ? ['drill' => $drill] : [])
                    + ($created !== false ? ['created' => $created] : [])
                    + ($ds !== null ? ['ds' => $ds] : [])
                    + (is_string($restart) && preg_match('/^[a-z-]{1,20}$/D', $restart) ? ['restart' => $restart] : [])
                    + (is_string($project) && $project !== '' && strlen($project) <= 200 ? ['compose' => $project] : []);
    }
    ksort($out);
    return $out;
}

/**
 * Whether a privileged container runs or comes up by itself ($containers: watchmanContainers(), with pid, restart,
 * compose): `start` = run (its main process lives), auto (stopped, but started when Docker comes up — Unraid's
 * autostart list, Docker's restart policy «always», or Compose Manager's autostart of its stack) or off (stopped,
 * nothing starts it). Not said (counts as running) when that isn't known: a stack Compose Manager doesn't know, or
 * whose folder lies under /mnt (never a disk woken for it). Kept in the round's look (seen.json), so a round Docker
 * doesn't answer keeps the last word; only privileged containers get it (inbox #10, 1.51.0: the posture tip
 * «containers run privileged» named one that was stopped and not on autostart).
 */
function watchmanContainerStarts(array $containers, array $paths): array
{
    $auto = $root = null;
    foreach ($containers as $name => $c) {
        if (!is_array($c) || !in_array('--privileged', (array) ($c['tokens'] ?? []), true)) {
            continue;
        }
        if ((int) ($c['pid'] ?? 0) > 0) {
            $containers[$name]['start'] = 'run';
            continue;
        }
        if (($c['restart'] ?? '') === 'always') {
            $on = true;                             // Docker starts it itself when it comes up
        } elseif (isset($c['compose'])) {
            if ($root === null) {
                $root = (string) (readCfg((string) ($paths['compose_cfg'] ?? ''))['PROJECTS_FOLDER'] ?? '');
                $root = $root !== '' && $root[0] === '/' ? $root : (string) ($paths['compose_projects'] ?? '');
            }
            $on = $root === '' || str_starts_with($root . '/', '/mnt/') ? null : houseComposeAutostart((string) $c['compose'], $root);
        } else {
            $auto ??= houseAutostart((string) $paths['docker_autostart']);
            $on = isset($auto[(string) $name]);
        }
        if ($on !== null) {
            $containers[$name]['start'] = $on ? 'auto' : 'off';
        }
    }
    return $containers;
}

/**
 * What a container may do beyond the usual, as the flags of docker run:
 * --privileged, --network/--pid/--ipc=host, --cap-add=X, --device=/dev/x,
 * -v /var/run/docker.sock, -v /, -p [ip:]host:container/proto. Sorted.
 */
function watchmanContainerTokens(array $hc, array $mounts): array
{
    $t = [];
    if (!empty($hc['Privileged'])) {
        $t[] = '--privileged';
    }
    foreach (['NetworkMode' => '--network', 'PidMode' => '--pid', 'IpcMode' => '--ipc'] as $key => $flag) {
        if (($hc[$key] ?? '') === 'host') {
            $t[] = "$flag=host";
        }
    }
    foreach ((array) ($hc['CapAdd'] ?? []) as $cap) {
        if (is_string($cap) && preg_match('/^(?:CAP_)?([A-Z0-9_]{1,40})$/iD', $cap, $m)) {
            $t[] = '--cap-add=' . strtoupper($m[1]);
        }
    }
    foreach ((array) ($hc['Devices'] ?? []) as $d) {
        $path = is_array($d) ? ($d['PathOnHost'] ?? null) : null;
        if (is_string($path) && $path !== '') {
            $t[] = '--device=' . substr($path, 0, 200);
        }
    }
    foreach ((array) ($hc['PortBindings'] ?? []) as $port => $binds) {
        foreach ((array) $binds as $bind) {
            if (!is_array($bind)) {
                continue;
            }
            $ip = (string) ($bind['HostIp'] ?? '');
            $host = (string) ($bind['HostPort'] ?? '');
            $at = in_array($ip, ['', '0.0.0.0', '::'], true) ? '' : (str_contains($ip, ':') ? "[$ip]:" : "$ip:");
            $t[] = '-p ' . substr($at . ($host !== '' ? "$host:" : '') . $port, 0, 120);
        }
    }
    foreach ($mounts as $m) {
        $src = is_array($m) ? (string) ($m['Source'] ?? '') : '';
        if (in_array($src, ['/var/run/docker.sock', '/run/docker.sock'], true) || $src === '/') {
            $t[] = "-v $src";
        }
    }
    $t = array_values(array_unique($t));
    sort($t);
    return $t;
}

/** Rights, not just published ports */
function watchmanRights(array $tokens): array
{
    return array_values(array_filter($tokens, fn ($t) => !str_starts_with((string) $t, '-p ')));
}

function watchmanTokenKind(string $t): string
{
    if ($t === '--privileged') {
        return 'container_privileged';
    }
    if (in_array($t, ['--network=host', '--pid=host', '--ipc=host'], true)) {
        return 'container_host';
    }
    return str_starts_with($t, '-p ') ? 'container_ports' : 'container_rights';
}

/**
 * Containers against what is normal. A new one is normal unless it has
 * rights; a known one with new rights gets an entry per kind; rights that went
 * are the new normal. Gone ones are remembered for WATCH_FORGET.
 */
function watchmanContainersCompare(?array &$known, ?array $seen, array &$book, int $now, array $office = []): array
{
    if ($seen === null) {
        return [];                  // Docker didn't answer: nothing to compare
    }
    if ($known === null) {           // Docker wasn't there when he took over: what he sees now is normal
        $known = array_map(fn ($c) => ['tokens' => $c['tokens'], 'seen' => $now], $seen);
        return [];
    }
    $added = [];
    foreach ($seen as $name => $c) {
        $tokens = (array) $c['tokens'];
        if (!isset($known[$name])) {
            if (watchmanDrillContainer($office, (string) $name, (array) $c)) {
                // Mr. Restori's drill made it and removes it again after its step: one quiet line per drill, never into
                // what is normal (the next drill's throwaways have other names)
                watchmanDrillNote($book, (string) $name, (array) $c, $office['drills'][$name]['id'], $now);
                continue;
            }
            if (!watchmanRights($tokens)) {
                $known[$name] = ['tokens' => $tokens, 'seen' => $now];
                continue;
            }
            if (watchmanOfficeContainer($office, (string) $name, (array) $c)) {
                // made from Unraid's form the consultant prepared: the office's own — noted, never told
                $known[$name] = ['tokens' => $tokens, 'seen' => $now];
                watchmanOfficeNote($book, 'container_new', "container_new:$name", $now, ['name' => (string) $name, 'image' => (string) ($c['image'] ?? ''), 'tokens' => $tokens]);
                continue;
            }
            $added[] = watchmanSet($book, 'container_new', "container_new:$name", $now,
                ['name' => (string) $name, 'image' => (string) ($c['image'] ?? ''), 'tokens' => $tokens]);
            continue;
        }
        $was = (array) ($known[$name]['tokens'] ?? []);
        $known[$name] = ['tokens' => array_values(array_intersect($was, $tokens)), 'seen' => $now];
        $by = [];
        foreach (array_diff($tokens, $was) as $t) {
            $by[watchmanTokenKind($t)][] = $t;
        }
        foreach ($by as $kind => $list) {
            $added[] = watchmanSet($book, $kind, "$kind:$name", $now,
                ['name' => (string) $name, 'image' => (string) ($c['image'] ?? ''), 'tokens' => array_values($list)]);
        }
    }
    foreach ($known as $name => $k) {
        if (!isset($seen[$name]) && $now - (int) ($k['seen'] ?? $now) > WATCH_FORGET) {
            unset($known[$name]);
        }
    }
    return array_values(array_filter($added));
}

// ===================================================================== what the office installed itself

/**
 * The consultant's record of what he installed (data/advisor/installs.json, advisorRecord()):
 * trusted only while its folder and the file are root's own and no one else may write them (folder
 * 0700, a plain file 0600 with one link) and only in exactly the shape he writes; older than
 * WATCH_OFFICE_KEEP left out.
 *
 * @return list<array{kind: string, name: string, t: int, image?: string}>
 */
function watchmanOfficeInstalls(?string $file, int $now): array
{
    if ($file === null) {
        return [];
    }
    $own = function (string $path, bool $dir): bool {
        $st = @lstat($path);
        return $st && ($st['mode'] & 0170000) === ($dir ? 0040000 : 0100000) && $st['uid'] === 0 && !($st['mode'] & 0077)
            && ($dir || ($st['nlink'] === 1 && $st['size'] <= 65536));
    };
    clearstatcache();
    if (!$own(dirname($file), true) || !$own($file, false)) {
        return [];
    }
    $out = [];
    foreach ((array) ((readJson($file) ?? [])['installs'] ?? []) as $r) {
        if (!is_array($r) || !in_array($r['kind'] ?? null, ['plugin', 'container'], true) || !is_string($r['name'] ?? null)
            || !preg_match('/^[A-Za-z0-9._+-]{1,100}$/D', $r['name']) || !is_int($r['t'] ?? null) || $r['t'] > $now + 60 || $r['t'] < $now - WATCH_OFFICE_KEEP
            || ($r['kind'] === 'container' && (!is_string($r['image'] ?? null) || $r['image'] === ''))) {
            continue;
        }
        $out[] = ['kind' => $r['kind'], 'name' => $r['name'], 't' => $r['t']] + ($r['kind'] === 'container' ? ['image' => $r['image']] : []);
    }
    return $out;
}

/**
 * What of this round's look the office installed itself: plugins the consultant installed (exactly
 * that name, its link in /var/log/plugins made within WATCH_OFFICE_PLUGIN after his job started) and
 * the /etc/cron.d file of that plugin's name made then too; the containers whose form he prepared
 * (matched in watchmanOfficeContainer()).
 *
 * @return array{plugins: array<string,int>, cron_d: array<string,int>, containers: array<string, list<array{image: string, t: int}>>}
 */
function watchmanOfficeLook(array $paths, int $now): array
{
    $out = ['plugins' => [], 'cron_d' => [], 'containers' => [], 'drills' => watchmanDrillRecord($paths['drill_record'] ?? null, $now)];
    foreach (watchmanOfficeInstalls($paths['office_installs'] ?? null, $now) as $r) {
        if ($r['kind'] === 'container') {
            $out['containers'][$r['name']][] = ['image' => $r['image'], 't' => $r['t']];
            continue;
        }
        $then = fn ($st, string $k): bool => is_array($st) && $st[$k] >= $r['t'] - 5 && $st[$k] <= $r['t'] + WATCH_OFFICE_PLUGIN;
        if (!isset($paths['plugins']) || !$then(@lstat($paths['plugins'] . "/{$r['name']}.plg"), 'mtime')) {
            continue;
        }
        $out['plugins'][$r['name']] = $r['t'];
        if (isset($paths['cron_d']) && $then(watchmanPlain($paths['cron_d'] . "/{$r['name']}"), 'ctime')) {
            $out['cron_d'][$r['name']] = $r['t'];
        }
    }
    return $out;
}

/** A new container the office made: the consultant prepared its form (that name, that image), it carries his label and was created soon after */
function watchmanOfficeContainer(array $office, string $name, array $c): bool
{
    foreach ((array) ($office['containers'][$name] ?? []) as $r) {
        $created = $c['created'] ?? null;
        if (($c['by'] ?? null) === 'consultant' && (string) ($c['image'] ?? '') === $r['image'] && is_int($created)
            && $created >= $r['t'] - 5 && $created <= $r['t'] + WATCH_OFFICE_FORM) {
            return true;
        }
    }
    return false;
}

/**
 * Mr. Restori's drill's record of the throwaways it made (data/restore-drill/record.json, drillRecord(): written before
 * each create) — trusted like the consultant's: its folder and the file root's own, no one else may write them, only
 * in exactly the shape the drill writes, within WATCH_DRILL_KEEP. By name.
 *
 * @return array<string, array{id: string, image: string, t: int}>
 */
function watchmanDrillRecord(?string $file, int $now): array
{
    if ($file === null) {
        return [];
    }
    $own = function (string $path, bool $dir): bool {
        $st = @lstat($path);
        return $st && ($st['mode'] & 0170000) === ($dir ? 0040000 : 0100000) && $st['uid'] === 0 && !($st['mode'] & 0077)
            && ($dir || ($st['nlink'] === 1 && $st['size'] <= 65536));
    };
    clearstatcache();
    if (!$own(dirname($file), true) || !$own($file, false)) {
        return [];
    }
    $out = [];
    foreach ((array) ((readJson($file) ?? [])['made'] ?? []) as $r) {
        if (!is_array($r) || ($r['kind'] ?? null) !== 'container' || !is_string($r['name'] ?? null) || !preg_match(WATCH_DRILL_NAME, $r['name'], $m)
            || ($r['id'] ?? null) !== $m[1] || !is_string($r['image'] ?? null) || !preg_match('/^sha256:[0-9a-f]{64}$/D', $r['image'])
            || !is_int($r['t'] ?? null) || $r['t'] > $now + 60 || $r['t'] < $now - WATCH_DRILL_KEEP) {
            continue;
        }
        $out[$r['name']] = ['id' => $m[1], 'image' => $r['image'], 't' => $r['t']];
    }
    return $out;
}

/**
 * A new container the drill made: its record names it, and the container agrees in everything — its name (the drill's
 * pattern, the recorded drill), its label uso.drill (that drill — a label alone anyone can set), its image (the
 * recorded id), created right after the record, no rights. Anything else stays what it is (reported when it has rights).
 */
function watchmanDrillContainer(array $office, string $name, array $c): bool
{
    $r = $office['drills'][$name] ?? null;
    $created = $c['created'] ?? null;
    return is_array($r) && preg_match(WATCH_DRILL_NAME, $name, $m) && $m[1] === $r['id'] && ($c['drill'] ?? null) === $r['id']
        && (string) ($c['image'] ?? '') === $r['image'] && !($c['tokens'] ?? []) && is_int($created)
        && $created >= $r['t'] - 5 && $created <= $r['t'] + WATCH_DRILL_CREATE;
}

/** The drill's throwaways in the book: one line per drill, noted by himself (`by` office), its names added as they come */
function watchmanDrillNote(array &$book, string $name, array $c, string $drill, int $now): void
{
    foreach ($book as $i => $e) {
        if (($e['key'] ?? '') === "drill_throwaway:$drill") {
            $names = (array) ($e['p']['names'] ?? []);
            if (!in_array($name, $names, true)) {
                $book[$i]['p']['names'] = array_slice([...$names, $name], -WATCH_LIST_MAX);
                $book[$i]['count'] = (int) ($e['count'] ?? 1) + 1;
                $book[$i]['last'] = $now;
            }
            return;
        }
    }
    $e = watchmanEntry('drill_throwaway', "drill_throwaway:$drill", $now, ['id' => $drill, 'names' => [$name], 'image' => (string) ($c['image'] ?? '')]);
    $e['noted'] = $now;
    $e['by'] = 'office';
    $book[] = $e;
}

/** A line of the office's own cron file exactly as officeJobSetSchedule() writes it: a cron time, then one of its jobs */
function watchmanOfficeCronLine(string $line): bool
{
    foreach (OFFICE_JOBS as $job) {
        $cmd = ' ' . officeJobCommand($job);
        if (str_ends_with($line, $cmd) && preg_match(WATCH_CRON_LINE, $line) && count(preg_split('/\s+/', substr($line, 0, -strlen($cmd)))) === 5) {
            return true;
        }
    }
    return false;
}

/**
 * The office changed its own schedule (a time set on a desk's page): a line in the book, noted by himself (`by`
 * schedule) — or, while an entry about that file is still open (from before 1.30), that one, closed
 */
function watchmanOfficeNoteSchedule(array &$book, string $file, int $now, array $p): void
{
    $closed = false;
    foreach ($book as $i => $e) {
        if (($e['key'] ?? '') === "cron_file:$file" && watchmanOpen($e)) {
            $book[$i]['p'] = $p + (array) $e['p'];
            $book[$i]['last'] = $now;
            $book[$i]['noted'] = $now;
            $book[$i]['by'] = 'schedule';
            $closed = true;
        }
    }
    if ($closed) {
        return;                     // that entry is the line
    }
    $e = watchmanEntry('cron_file', "cron_file:$file", $now, $p);
    $e['noted'] = $now;
    $e['by'] = 'schedule';
    $book[] = $e;
}

/** The office's own doing: a line in the book, noted by himself (`by` office) — never open, never told */
function watchmanOfficeNote(array &$book, string $kind, string $key, int $now, array $p): void
{
    $e = watchmanEntry($kind, $key, $now, $p + ['installed_by' => 'consultant']);
    $e['noted'] = $now;
    $e['by'] = 'office';
    $book[] = $e;
}

// ===================================================================== partner offices: the door

/*
 * A partner office knocks at this server's sshd with its pair's key; the line the pairing wrote into root's
 * authorized_keys (`restrict,from="<its address>",command="…/partner-door.sh <id>" ssh-ed25519 … uso-partner:<id>`)
 * lets it run nothing but the door (agent/partner-door.php). He knows that door from what the pairing and the door
 * leave behind (agent/lib/partnerlook.php — never a key, never the pairing's files changed):
 *
 *   the line   the office's own, written at the pairing (exactly partnerDoorLine()'s shape, the pair's key, its address
 *              in from=, the file written within WATCH_PARTNER_PAIRED of `paired`): its key adopted, one line noted by
 *              himself (`by` office); any other new line stays news (the flash watch's new key). A known pair's line
 *              changed — another from=, restrict gone, another command, key or option — is door_changed; taken out
 *              (the partnership ended) is normal.
 *   logins     sshd's "Accepted publickey … from <ip> … SHA256:<fp>": the pair's key from its address is the door at
 *              work; its key from another address, or its address with another key, is door_key_moved.
 *   refusals   the door's refused-<id>.json: WATCH_REFUSED_BURST within WATCH_REFUSED_WINDOW is door_refused.
 *   data flow  while the door receives (and the round after), the SSH bytes of the pairs' addresses and what is written
 *              into <pool>/UnraidSecretaryOffice-partners are the office's own; the sender's phase `partner` likewise.
 *   snapshots  what the door's retention destroyed (deletes.jsonl) is no snap_gone.
 */

/**
 * The partner door as this round sees it, outside the book's lock — RAM, the flash and the pairs file, no zfs, no ssh:
 * the office's lines in root's authorized_keys (and the file's time), the pairs (null: not looked at — the night shift
 * has no data folder), the door's transfers going on, its refusals. Null: no SSH keys looked at.
 */
function watchmanPartnerLook(array $paths): ?array
{
    if (!isset($paths['ssh'])) {
        return null;
    }
    $file = $paths['ssh'] . '/root/authorized_keys';
    clearstatcache(true, $file);
    $st = @stat($file);
    $run = $paths['partner_run'] ?? null;
    $pairs = isset($paths['partner_pairs']) ? partnerLookPairs((string) $paths['partner_pairs']) : null;
    return [
        'lines'   => partnerLookLines($file),
        'mtime'   => $st ? (int) $st['mtime'] : null,
        'pairs'   => $pairs,
        // restore tickets (stage 3): their lines, and the tickets this office gave (null: not looked at — the night)
        'ticket_lines' => partnerLookTicketLines($file),
        'tickets' => isset($paths['partner_tickets']) ? partnerLookTickets((string) $paths['partner_tickets'], (array) $pairs) : null,
        'doors'   => $run !== null ? partnerLookDoors((string) $run) : [],
        'refused' => $run !== null ? partnerLookRefused((string) $run) : [],
    ];
}

/** His baseline of the partner doors: the lines he knows, the pairs as the last day round saw them, address|key pairs noted */
function watchmanPartnerBase(mixed $b): array
{
    $b = is_array($b) ? $b : [];
    return ['lines' => (array) ($b['lines'] ?? []), 'pairs' => (array) ($b['pairs'] ?? []), 'logins' => (array) ($b['logins'] ?? []),
            'tickets' => (array) ($b['tickets'] ?? [])];
}

/** What he keeps of a line: what it allows and a hash of it — never the key */
function watchmanPartnerLineKeep(array $l): array
{
    return ['fp' => $l['fp'] ?? null, 'h' => (string) ($l['h'] ?? ''), 'restrict' => (bool) ($l['restrict'] ?? false), 'from' => $l['from'] ?? null,
            'command' => $l['command'] ?? null, 'others' => array_values((array) ($l['others'] ?? []))];
}

/**
 * The office's own line for a pair: exactly as the pairing writes it (partnerDoorLine()), once, the pair's key, its
 * address in from= (an IP address as it is; a host name: what it was resolved to — IP addresses only), and the file
 * written within WATCH_PARTNER_PAIRED after the pair was stored (the pairing stores the pair, then writes the line).
 */
function watchmanPartnerOwnLine(array $l, array $pair, ?int $mtime): bool
{
    $from = (string) ($l['from'] ?? '');
    $ips = partnerLookFromIps($from);
    return !empty($l['exact']) && empty($l['twice']) && ($pair['fp'] ?? null) !== null && ($l['fp'] ?? null) === $pair['fp']
        && $ips && count($ips) === count(explode(',', $from)) && ((array) $pair['ips'] === [] || $ips === array_values((array) $pair['ips']))
        && $mtime !== null && $mtime >= (int) $pair['paired'] - 5 && $mtime <= (int) $pair['paired'] + WATCH_PARTNER_PAIRED;
}

/**
 * Before the flash watch compares the keys: a new line that is the office's own (watchmanPartnerOwnLine()) is adopted —
 * its key and the line — and noted by himself (`by` office, «partner <name> paired»); a line whose key he knows already
 * (a baseline from before this watch, «I know, thanks» on the key) is known with it. Anything else stays news.
 */
function watchmanPartnerAdopt(array &$b, ?array $seen, array &$book, int $now, array $seenKeys = []): void
{
    if ($seen === null) {
        return;
    }
    $b['partner'] = watchmanPartnerBase($b['partner'] ?? null);
    foreach ((array) $seen['lines'] as $id => $l) {
        $id = (string) $id;
        $fp = $l['fp'] ?? null;
        if (isset($b['partner']['lines'][$id]) || !is_string($fp)) {
            continue;
        }
        if (isset($b['flash']['keys']['root'][$fp])) {
            $b['partner']['lines'][$id] = watchmanPartnerLineKeep($l);
            continue;
        }
        $pair = is_array($seen['pairs'] ?? null) ? ($seen['pairs'][$id] ?? null) : null;
        if (!is_array($pair) || !watchmanPartnerOwnLine($l, $pair, $seen['mtime'] ?? null)) {
            continue;
        }
        $b['flash']['keys']['root'][$fp] = $seenKeys[$fp] ?? ['type' => 'ssh-ed25519', 'comment' => "uso-partner:$id"];
        $b['partner']['lines'][$id] = watchmanPartnerLineKeep($l);
        $e = watchmanEntry('partner_paired', "partner_paired:$id", $now, ['id' => $id, 'name' => (string) $pair['name'], 'address' => (string) $pair['address'],
            'from' => (string) $l['from'], 'fp' => $fp, 'installed_by' => 'teamlead']);
        $e['noted'] = $now;
        $e['by'] = 'office';
        $book[] = $e;
    }
    // a restore ticket's line (uso-ticket:<id>, stage 3): the office's own like a pair's — exactly as the Team Lead writes
    // it, the ticket's key and address, written right after the ticket was made; noted by himself with kind ticket
    foreach ((array) ($seen['ticket_lines'] ?? []) as $id => $l) {
        $id = (string) $id;
        $fp = $l['fp'] ?? null;
        if (isset($b['partner']['tickets'][$id]) || !is_string($fp)) {
            continue;
        }
        if (isset($b['flash']['keys']['root'][$fp])) {
            $b['partner']['tickets'][$id] = watchmanPartnerLineKeep($l);
            continue;
        }
        $t = is_array($seen['tickets'] ?? null) ? ($seen['tickets'][$id] ?? null) : null;
        if (!is_array($t) || !is_int($l['expires'] ?? null) || gmdate('YmdHi', $l['expires']) !== gmdate('YmdHi', (int) $t['expires'])
            || !watchmanPartnerOwnLine($l, ['fp' => $t['fp'], 'ips' => $t['ips'], 'paired' => $t['created']], $seen['mtime'] ?? null)) {
            continue;
        }
        $b['flash']['keys']['root'][$fp] = $seenKeys[$fp] ?? ['type' => 'ssh-ed25519', 'comment' => "uso-ticket:$id"];
        $b['partner']['tickets'][$id] = watchmanPartnerLineKeep($l);
        $e = watchmanEntry('partner_paired', "partner_paired:ticket-$id", $now, ['id' => $id, 'name' => (string) $t['name'], 'address' => (string) $t['address'],
            'from' => (string) $l['from'], 'fp' => $fp, 'installed_by' => 'teamlead', 'kind' => 'ticket', 'of' => (string) $t['of_name'], 'expires' => (int) $t['expires']]);
        $e['noted'] = $now;
        $e['by'] = 'office';
        $book[] = $e;
    }
}

/** The restore tickets as doors for the logins: their key from their address is the office's own */
function watchmanTicketDoors(?array $seen): array
{
    $out = [];
    foreach ((array) ($seen['tickets'] ?? []) as $id => $t) {
        if (isset($seen['ticket_lines'][$id]) && is_string($t['fp'] ?? null)) {
            $out["ticket-$id"] = ['id' => "ticket-$id", 'name' => (string) $t['name'], 'ips' => array_values((array) $t['ips']), 'fp' => $t['fp']];
        }
    }
    return $out;
}

/**
 * The pairs whose door is here (they send to this office: their key is in my authorized_keys) with the addresses they
 * come from — the pair's address, and what the line he knows names in from= (a host name resolved at the pairing).
 * Not looked at (the night): as the last day round saw them.
 *
 * @return array<string, array{id: string, name: string, ips: list<string>, fp: string}>
 */
function watchmanPartnerDoors(array $known, ?array $seen): array
{
    if (!is_array($seen['pairs'] ?? null)) {
        return (array) ($known['pairs'] ?? []);
    }
    $out = [];
    foreach ($seen['pairs'] as $id => $p) {
        if (!is_string($p['fp'] ?? null)) {
            continue;
        }
        $ips = array_values(array_unique(array_merge((array) $p['ips'], partnerLookFromIps($known['lines'][$id]['from'] ?? null))));
        $out[(string) $id] = ['id' => (string) $id, 'name' => (string) $p['name'], 'ips' => $ips, 'fp' => $p['fp']];
    }
    return $out;
}

/**
 * A successful SSH login against the partner doors: null — none of theirs (a password, an address and key no pair
 * has); ok — a pair's key from its address; else which pair and how it differs (`address`: its key from elsewhere,
 * `key`: its address with another key).
 */
function watchmanPartnerLogin(array $doors, string $ip, ?string $fp): ?array
{
    if ($fp === null) {
        return null;
    }
    foreach ($doors as $d) {
        if (($d['fp'] ?? null) === $fp && (array) $d['ips']) {
            return in_array($ip, (array) $d['ips'], true) ? ['ok' => true]
                : ['ok' => false, 'id' => (string) $d['id'], 'name' => (string) $d['name'], 'how' => 'address', 'ips' => array_values((array) $d['ips'])];
        }
    }
    foreach ($doors as $d) {
        if (in_array($ip, (array) $d['ips'], true)) {
            return ['ok' => false, 'id' => (string) $d['id'], 'name' => (string) $d['name'], 'how' => 'key', 'ips' => array_values((array) $d['ips'])];
        }
    }
    return null;
}

/**
 * After the flash watch: a known pair's line changed (door_changed — what changed, the from= then and now; only
 * `restrict` added is safer and adopts itself), one taken out is forgotten (the partnership ended); the door's
 * refusals (door_refused: WATCH_REFUSED_BURST within WATCH_REFUSED_WINDOW, one of them new since the last round).
 *
 * @return list<string>  the kinds of new entries
 */
function watchmanPartnerCompare(array &$b, ?array $seen, array &$book, array &$st, int $now): array
{
    if ($seen === null) {
        return [];
    }
    $b['partner'] = watchmanPartnerBase($b['partner'] ?? null);
    $name = fn (string $id): string => (string) ($seen['pairs'][$id]['name'] ?? $b['partner']['pairs'][$id]['name'] ?? $id);
    $added = [];
    foreach (array_keys($b['partner']['tickets']) as $id) {
        if (!isset($seen['ticket_lines'][$id])) {
            unset($b['partner']['tickets'][$id]);       // a ticket's line gone (it expired, or ended by hand): normal
        }
    }
    foreach ($b['partner']['lines'] as $id => $k) {
        $id = (string) $id;
        $l = $seen['lines'][$id] ?? null;
        if ($l === null) {
            unset($b['partner']['lines'][$id]);
            continue;
        }
        if ($l['h'] === ($k['h'] ?? null)) {
            continue;
        }
        $what = [];
        foreach (['from' => 'from', 'restrict' => 'restrict', 'command' => 'command', 'fp' => 'key', 'others' => 'options'] as $f => $w) {
            if (($l[$f] ?? null) !== ($k[$f] ?? null)) {
                $what[] = $w;
            }
        }
        if (!$what || ($what === ['restrict'] && $l['restrict'])) {
            $b['partner']['lines'][$id] = watchmanPartnerLineKeep($l);       // the same (spacing), or narrower: normal
            continue;
        }
        $added[] = watchmanSet($book, 'door_changed', "door_changed:$id", $now, ['id' => $id, 'name' => $name($id), 'what' => $what,
            'from' => $l['from'], 'from_old' => $k['from'] ?? null, 'restrict' => (bool) $l['restrict'],
            'command' => ($l['command'] ?? null) === PARTNER_DOOR . " $id", 'others' => array_slice((array) $l['others'], 0, WATCH_LIST_MAX)]);
    }

    $st['partner_refused'] = (array) ($st['partner_refused'] ?? []);
    foreach ((array) $seen['refused'] as $id => $r) {
        $id = (string) $id;
        $times = array_values(array_filter((array) $r['times'], 'is_int'));
        $last = (int) ($st['partner_refused'][$id] ?? 0);
        $new = array_values(array_filter($times, fn ($t) => $t > $last));
        if (!$new) {
            continue;
        }
        $st['partner_refused'][$id] = max($times);
        $burst = 0;
        foreach ($new as $t) {
            $burst = max($burst, count(array_filter($times, fn ($x) => $x <= $t && $x > $t - WATCH_REFUSED_WINDOW)));
        }
        if ($burst >= WATCH_REFUSED_BURST) {
            // a new entry counts the burst's refusals; an open one counts on with the new ones
            $open = array_filter($book, fn ($e) => ($e['key'] ?? '') === "door_refused:$id" && watchmanOpen($e));
            $added[] = watchmanBump($book, 'door_refused', "door_refused:$id", max($new), $open ? count($new) : max($burst, count($new)),
                ['id' => $id, 'name' => $name($id), 'why' => array_values(array_filter([(string) $r['last']])), 'known' => isset($seen['pairs'][$id]) || isset($b['partner']['pairs'][$id])]);
        }
    }
    foreach (array_keys($st['partner_refused']) as $id) {
        if (!isset($seen['refused'][$id]) && (int) $st['partner_refused'][$id] < $now - 2 * 3600) {
            unset($st['partner_refused'][$id]);         // the door keeps an hour of them: long gone
        }
    }
    return array_values(array_filter($added));
}

/** «What I keep an eye on» of the partner doors: the pairs by name with whether their line is there, lines of no pair */
function watchmanPartnerSummary(?array $known, ?array $seen): ?array
{
    if ($seen === null) {
        return null;
    }
    $pairs = is_array($seen['pairs'] ?? null) ? $seen['pairs'] : null;
    $out = [];
    foreach ((array) $pairs as $id => $p) {
        $l = $seen['lines'][$id] ?? null;
        $out[] = ['id' => (string) $id, 'name' => (string) $p['name'], 'address' => (string) $p['address'], 'door' => is_string($p['fp'] ?? null),
                  'line' => $l === null ? 'missing' : (isset($known['lines'][$id]) && ($known['lines'][$id]['h'] ?? '') === $l['h'] ? 'ok' : 'changed'),
                  'sends' => (bool) $p['sends'], 'receives' => (bool) $p['receives'], 'trust' => (string) $p['trust']];
    }
    $strays = [];
    foreach ((array) $seen['lines'] as $id => $l) {
        if ($pairs !== null && !isset($pairs[$id])) {
            $strays[] = (string) $id;
        }
    }
    return ['pairs' => $out, 'looked' => $pairs !== null, 'strays' => $strays,
            'transfers' => count((array) ($seen['doors'] ?? []))];
}

// ===================================================================== plugins

/** Installed plugins (/var/log/plugins): name => source (host, on code hosts with the owner), a hash of the URL, version */
function watchmanPlugins(string $dir): array
{
    $out = [];
    foreach (glob("$dir/*.plg") ?: [] as $file) {
        $name = basename($file, '.plg');
        if (!preg_match('/^[A-Za-z0-9._+-]{1,100}$/D', $name)) {
            continue;
        }
        $head = (string) @file_get_contents($file, false, null, 0, 65536);
        [$url, $version] = watchmanPluginUrl($head);
        $out[$name] = ['source' => watchmanSource($url), 'url' => $url === null ? null : substr(hash('sha256', $url), 0, 16),
                       'version' => $version];
        if (count($out) >= 500) {
            break;
        }
    }
    ksort($out);
    return $out;
}

/**
 * The plugin's pluginURL (the attribute of <PLUGIN>, where the plugin
 * manager looks for updates) and version, its &entities; resolved from the
 * file's own <!ENTITY> lines — nothing else is fetched.
 * @return array{0: ?string, 1: ?string}
 */
function watchmanPluginUrl(string $xml): array
{
    $entities = [];
    if (preg_match_all('/<!ENTITY\s+([A-Za-z_][\w.-]*)\s+(?:"([^"]*)"|\'([^\']*)\')\s*>/', $xml, $m, PREG_SET_ORDER)) {
        foreach ($m as $e) {
            $entities[$e[1]] ??= ($e[2] ?? '') !== '' ? $e[2] : ($e[3] ?? '');
        }
    }
    if (!preg_match('/<PLUGIN\b([^>]*)>/i', $xml, $tag)) {
        return [null, null];
    }
    $attr = function (string $name) use ($tag, $entities): ?string {
        if (!preg_match('/\b' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag[1], $a)) {
            return null;
        }
        $v = ($a[1] ?? '') !== '' ? $a[1] : ($a[2] ?? '');
        for ($i = 0; $i < 10 && str_contains($v, '&'); $i++) {
            $v = (string) preg_replace_callback('/&([A-Za-z_][\w.-]*);/', fn ($x) => $entities[$x[1]] ?? $x[0], $v);
        }
        $v = trim($v);
        return $v === '' || strlen($v) > 2000 ? null : $v;
    };
    return [$attr('pluginURL'), $attr('version')];
}

/** Where a plugin comes from, in a few words: the host, and on code hosts the owner ("raw.githubusercontent.com/unraid") */
function watchmanSource(?string $url): string
{
    if ($url === null) {
        return '';
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (!preg_match('/^[a-z0-9.-]{1,253}$/D', $host)) {
        return '';
    }
    if (in_array($host, WATCH_CODE_HOSTS, true)) {
        $owner = explode('/', ltrim((string) parse_url($url, PHP_URL_PATH), '/'))[0] ?? '';
        if (preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $owner)) {
            return "$host/$owner";
        }
    }
    return $host;
}

/** Plugins against what is normal: a new one, or one whose source moved; a new version from the same place is normal */
function watchmanPluginsCompare(array &$known, array $seen, array &$book, int $now, array $office = []): array
{
    $added = [];
    foreach ($seen as $name => $p) {
        $k = $known[$name] ?? null;
        if ($k === null && isset($office['plugins'][$name])) {
            // the consultant installed exactly this one just then: the office's own — noted, never told
            $known[$name] = $p + ['seen' => $now];
            watchmanOfficeNote($book, 'plugin_new', "plugin_new:$name", $now, ['name' => (string) $name, 'source' => $p['source'], 'version' => $p['version']]);
            continue;
        }
        if ($k === null) {
            $added[] = watchmanSet($book, 'plugin_new', "plugin_new:$name", $now,
                ['name' => (string) $name, 'source' => $p['source'], 'version' => $p['version']]);
            continue;
        }
        if (($k['source'] ?? '') !== $p['source']) {
            $known[$name]['seen'] = $now;
            $added[] = watchmanSet($book, 'plugin_source', "plugin_source:$name", $now,
                ['name' => (string) $name, 'source' => $p['source'], 'old' => (string) ($k['source'] ?? '')]);
            continue;
        }
        $known[$name] = $p + ['seen' => $now];
    }
    foreach ($known as $name => $k) {
        if (!isset($seen[$name]) && $now - (int) ($k['seen'] ?? $now) > WATCH_FORGET) {
            unset($known[$name]);       // removed: gone after a while (an update doesn't make it new)
        }
    }
    return array_values(array_filter($added));
}

// ===================================================================== the flash

/**
 * What matters on the flash: go (a hash, and one per line — never its text; watchmanGoAdded() reads the lines added),
 * the files in /boot/extra, the users, a hash per user's password field
 * (never the field), the SSH keys (fingerprint, type, comment).
 */
function watchmanFlash(array $paths): array
{
    $go = null;
    $text = @file_get_contents($paths['go'], false, null, 0, 262144);
    if ($text !== false) {
        $lines = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $l) {
            $l = trim($l);
            if ($l !== '') {
                $lines[substr(sha1($l), 0, 12)] = true;
            }
        }
        $go = ['hash' => substr(hash('sha256', $text), 0, 16), 'lines' => array_keys($lines)];
    }
    unset($text);

    $extra = [];
    foreach (@scandir($paths['extra']) ?: [] as $f) {
        if ($f === '.' || $f === '..' || count($extra) >= 500) {
            continue;
        }
        $st = @lstat($paths['extra'] . "/$f");
        if ($st && ($st['mode'] & 0170000) === 0100000) {
            $extra[watchmanClean($f, 120)] = ['size' => (int) $st['size'], 'time' => (int) $st['mtime']];
        }
    }
    ksort($extra);

    $users = [];
    foreach (@file($paths['passwd'], FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        $n = strtok($l, ':');
        if (is_string($n) && preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,31}\$?$/D', $n) && count($users) < 500) {
            $users[] = $n;
        }
    }
    $users = array_values(array_unique($users));
    $pw = [];
    foreach (@file($paths['shadow'], FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        $f = explode(':', $l, 3);
        if (count($f) >= 2 && in_array($f[0], $users, true)) {
            $pw[$f[0]] = substr(hash('sha256', 'uso-watchman:' . $f[1]), 0, 16);
        }
        $f = null;
    }
    ksort($pw);

    $keys = [];
    foreach (glob($paths['ssh'] . '/*/authorized_keys') ?: [] as $file) {
        $user = basename(dirname($file));
        if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,31}$/D', $user)) {
            continue;
        }
        foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            $k = watchmanSshKey($l);
            if ($k && count($keys[$user] ?? []) < 100) {
                $keys[$user][$k['fp']] = ['type' => $k['type'], 'comment' => $k['comment']];
            }
        }
    }
    ksort($keys);
    return ['go' => $go, 'extra' => $extra, 'users' => $users, 'pw' => $pw, 'keys' => $keys];
}

/** One line of authorized_keys (options in front are fine): type, fingerprint as ssh-keygen -l shows it, comment */
function watchmanSshKey(string $line): ?array
{
    $line = trim($line);
    if ($line === '' || $line[0] === '#'
        || !preg_match('/(?:^|\s)((?:ssh|ecdsa|sk)-[a-z0-9@.-]{1,60})\s+([A-Za-z0-9+\/]{20,}={0,3})(?:\s+(.*))?$/D', $line, $m)) {
        return null;
    }
    $blob = base64_decode($m[2], true);
    if ($blob === false) {
        return null;
    }
    return ['type' => $m[1], 'fp' => 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '='),
            'comment' => watchmanClean((string) ($m[3] ?? ''), 60)];
}

/** Printable, single line, at most $max characters */
function watchmanClean(string $s, int $max): string
{
    $s = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s));
    return mb_substr(mb_convert_encoding($s, 'UTF-8', 'UTF-8'), 0, $max);
}

const WATCH_GO_SHOW  = 20;           // lines of go added that his book keeps (their text) …
const WATCH_GO_WIDTH = 200;          // … each cut at this many characters

/**
 * The lines of go added since what is normal: their text (the first WATCH_GO_SHOW, each cut at WATCH_GO_WIDTH), read
 * from the file now — the only text of go he keeps, in the entry of his book (the page shows it; never in a notification,
 * the syslog for a SIEM or a mirror). Lines removed: only their number — their text is kept nowhere.
 * @return list<string>
 */
function watchmanGoAdded(string $file, array $was, array $is): array
{
    $text = @file_get_contents($file, false, null, 0, 262144);
    if ($text === false) {
        return [];
    }
    $was = array_flip(array_map('strval', $was));
    $is = array_flip(array_map('strval', $is));
    $out = [];
    foreach (preg_split('/\r?\n/', $text) ?: [] as $l) {
        $l = trim($l);
        $h = substr(sha1($l), 0, 12);
        if ($l === '' || isset($was[$h]) || !isset($is[$h]) || isset($out[$h])) {
            continue;                   // known, or not what the round saw (changed again meanwhile), or the same line twice
        }
        $clean = watchmanClean($l, WATCH_GO_WIDTH + 1);
        $out[$h] = mb_strlen($clean) > WATCH_GO_WIDTH ? mb_substr($clean, 0, WATCH_GO_WIDTH) . '…' : $clean;
        if (count($out) >= WATCH_GO_SHOW) {
            break;
        }
    }
    unset($text);
    return array_values($out);
}

/**
 * The flash against what is normal: go changed, a file in /boot/extra new or changed, a new user, a changed password, a
 * new SSH key. $go: the go file — the added lines' text goes into the entry (watchmanGoAdded()).
 */
function watchmanFlashCompare(array &$known, array $seen, array &$book, int $now, ?string $go = null): array
{
    $added = [];
    $kg = $known['go'] ?? null;
    $sg = $seen['go'];
    if (($kg['hash'] ?? null) !== ($sg['hash'] ?? null)) {
        $was = (array) ($kg['lines'] ?? []);
        $is = (array) ($sg['lines'] ?? []);
        $new = array_diff($is, $was);
        $added[] = watchmanSet($book, 'flash_go', 'flash_go', $now,
            ['added' => count($new), 'removed' => count(array_diff($was, $is)), 'lines' => count($is), 'gone' => $sg === null]
            + ($go !== null && $sg !== null && $new ? ['text' => watchmanGoAdded($go, $was, $is)] : []));
    }

    foreach ($seen['extra'] as $file => $f) {
        $k = $known['extra'][$file] ?? null;
        if ($k === null || (int) $k['size'] !== $f['size'] || (int) $k['time'] !== $f['time']) {
            $added[] = watchmanSet($book, 'flash_extra', "flash_extra:$file", $now,
                ['file' => (string) $file, 'size' => $f['size'], 'time' => $f['time'], 'new' => $k === null]);
        }
    }
    foreach (array_keys((array) ($known['extra'] ?? [])) as $file) {
        if (!isset($seen['extra'][$file])) {
            unset($known['extra'][$file]);
        }
    }

    $knownUsers = (array) ($known['users'] ?? []);
    foreach ($seen['users'] as $u) {
        if (!in_array($u, $knownUsers, true)) {
            $added[] = watchmanSet($book, 'flash_user', "flash_user:$u", $now, ['user' => $u]);
        }
    }
    $known['users'] = array_values(array_intersect($knownUsers, $seen['users']));
    foreach ($known['users'] as $u) {
        if (($known['pw'][$u] ?? null) !== ($seen['pw'][$u] ?? null)) {
            $added[] = watchmanSet($book, 'flash_password', "flash_password:$u", $now, ['user' => $u, '_h' => $seen['pw'][$u] ?? null]);
        }
    }
    foreach (array_keys((array) ($known['pw'] ?? [])) as $u) {
        if (!in_array($u, $known['users'], true)) {
            unset($known['pw'][$u]);
        }
    }

    foreach ($seen['keys'] as $user => $keys) {
        foreach ($keys as $fp => $k) {
            if (!isset($known['keys'][$user][$fp])) {
                $added[] = watchmanSet($book, 'flash_ssh_key', "flash_ssh_key:$user:$fp", $now,
                    ['user' => (string) $user, 'fp' => (string) $fp, 'type' => $k['type'], 'comment' => $k['comment']]);
            }
        }
    }
    foreach ((array) ($known['keys'] ?? []) as $user => $keys) {
        foreach (array_keys((array) $keys) as $fp) {
            if (!isset($seen['keys'][$user][$fp])) {
                unset($known['keys'][$user][$fp]);          // a key removed: safer, normal
            }
        }
        if (empty($known['keys'][$user])) {
            unset($known['keys'][$user]);
        }
    }
    return array_values(array_filter($added));
}

// ===================================================================== shares

/**
 * How open every share is to guests, as Unraid applies it (emhttp's sec.ini
 * and sec_nfs.ini, RAM): 0 not, 1 they read (secure), 2 they read and write
 * (public) — only when exported and SMB/NFS is switched on. Null when emhttp
 * has no list (it always has one while it runs).
 */
function watchmanShares(array $paths): ?array
{
    if (!isset($paths['sec'], $paths['sec_nfs'], $paths['share_cfg'])) {
        return null;                // not looked at (the night shift: no shares are exported while the array is stopped)
    }
    $smb = readCfg($paths['sec'], true);
    if (!$smb) {
        return null;
    }
    $nfs = readCfg($paths['sec_nfs'], true);
    $g = readCfg($paths['share_cfg']);
    $smbOn = ($g['shareSMBEnabled'] ?? 'yes') !== 'no';
    $nfsOn = ($g['shareNFSEnabled'] ?? 'no') === 'yes';
    $level = fn (array $s): int => match ((string) ($s['security'] ?? 'public')) { 'public' => 2, 'secure' => 1, default => 0 };
    $out = [];
    foreach ($smb as $name => $s) {
        if (!is_array($s) || !preg_match('/^[^\x00-\x1F\/\\\\]{1,100}$/D', (string) $name) || count($out) >= 1000) {
            continue;
        }
        $n = is_array($nfs[$name] ?? null) ? $nfs[$name] : [];
        $out[$name] = ['smb' => $smbOn && str_starts_with((string) ($s['export'] ?? '-'), 'e') ? $level($s) : 0,
                       'nfs' => $nfsOn && ($n['export'] ?? '-') === 'e' ? $level($n) : 0];
    }
    ksort($out);
    return $out;
}

/** Shares against what is normal: more open to guests than before is an entry; less open is the new normal */
function watchmanSharesCompare(?array &$known, ?array $seen, array &$book, int $now): array
{
    if ($seen === null) {
        return [];
    }
    if ($known === null) {
        $known = array_map(fn ($s) => $s + ['seen' => $now], $seen);
        return [];
    }
    $added = [];
    foreach ($seen as $share => $s) {
        $k = $known[$share] ?? null;
        foreach (['smb', 'nfs'] as $proto) {
            $was = (int) ($k[$proto] ?? 0);
            $is = (int) $s[$proto];
            if ($is > $was) {
                $added[] = watchmanSet($book, 'share_public', "share_public:$share:$proto", $now,
                    ['share' => (string) $share, 'proto' => $proto, 'level' => $is === 2 ? 'public' : 'secure']);
            } elseif ($k !== null && $is < $was) {
                $known[$share][$proto] = $is;
            }
        }
        if ($k !== null) {
            $known[$share]['seen'] = $now;
        } elseif (!$s['smb'] && !$s['nfs']) {
            $known[$share] = $s + ['seen' => $now];      // a new share, closed to guests: normal
        }
    }
    foreach ($known as $share => $k) {
        if (!isset($seen[$share]) && $now - (int) ($k['seen'] ?? $now) > WATCH_FORGET) {
            unset($known[$share]);
        }
    }
    return array_values(array_filter($added));
}

// ===================================================================== the host itself (what a SOC watches on Linux)

/*
 * What a security operations centre watches on a Linux server beyond logins and schedules — only what says
 * something on Unraid and costs little each round (RAM and /proc, no disk woken, nothing switched on):
 * logs emptied or replaced outside their rotation (T1070.002), accounts that exist only in RAM or got a
 * login (T1136.001), a program that newly listens on a network port (T1133), programs running from a scratch
 * folder or from memory (T1105). Like everything else: what is there at his first round is normal; later a
 * new one is an entry; «I know, thanks» makes it normal. Not done, and why — help.host_not.
 */
const WATCH_HOST_LOGS   = ['syslog' => '/var/log/syslog', 'wtmp' => '/var/log/wtmp', 'btmp' => '/var/log/btmp', 'lastlog' => '/var/log/lastlog'];
const WATCH_HOST_MAX    = 200;          // listeners, odd programs, accounts — each
// a scratch folder (also in a container), a hidden folder on the way, a program in memory only
const WATCH_ODD_EXE     = '#^/(?:tmp|dev/shm|var/tmp|run)/|/\.[^/]+/|^/memfd:#';
const WATCH_ODD_OK      = '#^/memfd:runc_cloned:#';      // Docker's runc runs a copy of itself from memory at every container start
// listeners that are someone else's to watch: Docker's port forwarders (his container_ports), the VMs' consoles
const WATCH_LISTEN_SKIP = '/^(?:docker-proxy|qemu-system-.*)$/D';

/**
 * The host as this round sees it — null parts were not looked at ($paths without them: the tests' older copies).
 * $containers: name => [pid …] (the main process: a program's container is found by its PID namespace). $prev: what
 * the last round saw of the host (an API key file the same as then isn't read again).
 */
function watchmanHost(array $paths, ?array $containers, ?array $prev = null): ?array
{
    if (!isset($paths['proc'])) {
        return null;
    }
    $boot = isset($paths['boot_id']) ? trim((string) @file_get_contents($paths['boot_id'], false, null, 0, 64)) : '';
    $api = watchmanApiKeys($paths, is_array($prev['api_files'] ?? null) ? $prev['api_files'] : []);
    return [
        'boot'   => $boot !== '' ? $boot : null,
        'logs'   => isset($paths['logs']) ? watchmanHostLogs((array) $paths['logs'], $paths['logrotate'] ?? null) : null,
        'users'  => watchmanHostUsers($paths),
        'listen' => watchmanHostListen($paths),
        'procs'  => watchmanHostProcs((string) $paths['proc'], $containers),
        'doors'  => watchmanHostDoors($paths),
        'api'    => $api['keys'] ?? null,
        'api_files' => $api['files'] ?? null,
    ];
}

/** What the last round saw stays for the parts not looked at this time (like Docker not answering) */
function watchmanHostObserved(?array $seen, ?array $old): ?array
{
    if ($seen === null) {
        return $old;
    }
    foreach (['logs', 'users', 'listen', 'procs', 'doors', 'api', 'api_files'] as $k) {
        if (($seen[$k] ?? null) === null && is_array($old[$k] ?? null) && ($k !== 'logs' || ($old['boot'] ?? null) === $seen['boot'])) {
            $seen[$k] = $old[$k];
        }
    }
    return $seen;
}

/**
 * The logs a cleaner of tracks empties: per log its inode and size, the inodes of its rotated copies
 * (<log>.1 …) and logrotate's date for it — a rotation (Unraid's logrotate renames, size 1M for the
 * syslog, monthly for wtmp/btmp) gives a new file whose old inode lives on as .1, or a new date.
 *
 * @return array<string, ?array{ino: int, size: int, old: list<int>, rot: ?string}>
 */
function watchmanHostLogs(array $logs, ?string $status): array
{
    $rotated = [];
    foreach (explode("\n", (string) ($status !== null ? @file_get_contents($status, false, null, 0, 1 << 20) : '')) as $line) {
        if (preg_match('/^"([^"]+)"\s+(\S+)/', trim($line), $m)) {
            $rotated[$m[1]] = $m[2];
        }
    }
    $out = [];
    clearstatcache();
    foreach ($logs as $id => $file) {
        $st = @lstat((string) $file);
        if (!$st || ($st['mode'] & 0170000) !== 0100000) {
            $out[$id] = null;
            continue;
        }
        $old = [];
        foreach (glob($file . '.[0-9]') ?: [] as $f) {
            $o = @lstat($f);
            if ($o) {
                $old[] = (int) $o['ino'];
            }
        }
        $out[$id] = ['ino' => (int) $st['ino'], 'size' => (int) $st['size'], 'old' => $old, 'rot' => $rotated[$file] ?? null];
    }
    return $out;
}

/**
 * The accounts in RAM (/etc/passwd, /etc/shadow — Unraid builds them from the flash at boot): uid, whether its
 * shell lets it log in, and of the password only its state — a hash, none at all (empty: no password needed), or
 * locked (!, *, x). Never the hash.
 *
 * @return array<string, array{uid: int, shell: bool, pw: string}>|null
 */
function watchmanHostUsers(array $paths): ?array
{
    $text = isset($paths['etc_passwd']) ? @file_get_contents($paths['etc_passwd'], false, null, 0, 1 << 20) : false;
    if (!is_string($text) || $text === '') {
        return null;
    }
    $pw = [];
    $shadow = isset($paths['etc_shadow']) ? @file_get_contents($paths['etc_shadow'], false, null, 0, 1 << 20) : false;
    foreach (explode("\n", is_string($shadow) ? $shadow : '') as $line) {
        $f = explode(':', $line);
        if (count($f) >= 2 && $f[0] !== '') {
            $pw[$f[0]] = $f[1] === '' ? 'none' : (preg_match('/^[!*x]/', $f[1]) ? 'locked' : 'hash');
        }
    }
    $out = [];
    foreach (explode("\n", $text) as $line) {
        $f = explode(':', trim($line));
        if (count($f) < 7 || !preg_match('/^[a-z_][a-z0-9_.-]{0,31}\$?$/iD', $f[0]) || !ctype_digit($f[2]) || count($out) >= WATCH_HOST_MAX) {
            continue;
        }
        // an empty shell field means /bin/sh; nologin, false and the like don't let anyone in
        $shell = trim($f[6]);
        $out[$f[0]] = ['uid' => (int) $f[2], 'shell' => !preg_match('#(?:^|/)(?:nologin|false|true|sync|shutdown|halt)$#D', $shell),
                       'pw' => $pw[$f[0]] ?? ($f[1] === '' ? 'none' : 'locked')];
    }
    return $out;
}

/** The TCP ports programs of the server listen on (ss, the host's network) — not loopback, not Docker's or the VMs' */
function watchmanHostListen(array $paths): ?array
{
    if (!isset($paths['ss']) || ($paths['ss'] === 'ss' && bin('ss') === null)) {
        return null;
    }
    [$exit, $out] = hostNet([(string) $paths['ss'], '-H', '-tlnp'], 20);
    if ($exit !== 0) {
        return null;
    }
    $low = (int) (preg_split('/\s+/', trim((string) @file_get_contents($paths['port_range'] ?? '', false, null, 0, 64)))[0] ?? 0);
    return watchmanListenParse($out, $low > 1024 ? $low : 32768);
}

/**
 * `ss -H -tlnp` (State Recv-Q Send-Q Local Peer Process) → "tcp:<port>" => program, port, addresses: the port is
 * what is new (ss names the first process holding it — Samba's smbd one round, smbd-scavenger the next). A port
 * from the dynamic range (ip_local_port_range: rpc.statd and the like pick one at every start) counts as
 * "<program>:*" — there the program is what is new, not its port.
 *
 * @return array<string, array{prog: string, port: ?int, addr: list<string>}>
 */
function watchmanListenParse(string $text, int $low): array
{
    $out = [];
    foreach (explode("\n", $text) as $line) {
        $f = preg_split('/\s+/', trim($line));
        if (count($f) < 5 || $f[0] !== 'LISTEN' || !preg_match('/^(.+):(\d{1,5})$/D', $f[3], $m)) {
            continue;
        }
        $addr = trim((string) preg_replace('/%.*$/', '', $m[1]), '[]');
        if (str_starts_with($addr, '127.') || $addr === '::1' || $addr === '::ffff:127.0.0.1' || $addr === 'localhost') {
            continue;               // only this server itself reaches it
        }
        $prog = preg_match('/users:\(\("([^"]{1,64})"/', $line, $p) ? watchmanClean($p[1], 32) : '?';
        if (preg_match(WATCH_LISTEN_SKIP, $prog)) {
            continue;
        }
        $port = (int) $m[2];
        $dynamic = $port >= $low;
        $key = $dynamic ? "$prog:*" : "tcp:$port";
        if (!isset($out[$key])) {
            if (count($out) >= WATCH_HOST_MAX) {
                break;
            }
            $out[$key] = ['prog' => $prog, 'port' => $dynamic ? null : $port, 'addr' => []];
        }
        $where = in_array($addr, ['*', '0.0.0.0', '::'], true) ? '*' : $addr;
        if (!in_array($where, $out[$key]['addr'], true) && count($out[$key]['addr']) < WATCH_LIST_MAX) {
            $out[$key]['addr'][] = $where;
        }
    }
    ksort($out);
    return $out;
}

/**
 * Programs running from a scratch folder, a hidden folder or from memory — on the server itself or inside a
 * container (its path as the container sees it; the container by its PID namespace). One readlink per process
 * (~1'600 on nostromo), the namespaces only for the few that match. A binary replaced while it runs (a plugin
 * update) shows "(deleted)" — that alone is no news.
 *
 * @return array<string, array{where: ?string, exe: string, prog: string}>|null  "host:<exe>" / "ct:<container>:<exe>"
 */
function watchmanHostProcs(string $proc, ?array $containers): ?array
{
    $init = @readlink("$proc/1/ns/mnt");
    if ($init === false || !is_dir($proc) || $containers === null) {
        return null;                // Docker didn't answer: a container's program would look like no container's — not looked
    }
    $pidns = null;
    $out = [];
    foreach (scandir($proc) ?: [] as $pid) {
        if (!ctype_digit($pid)) {
            continue;
        }
        $exe = @readlink("$proc/$pid/exe");
        if ($exe === false || $exe === '') {
            continue;               // a kernel thread, or gone meanwhile
        }
        $path = str_ends_with($exe, ' (deleted)') ? substr($exe, 0, -10) : $exe;
        if (!preg_match(WATCH_ODD_EXE, watchmanOddPath($path)) || preg_match(WATCH_ODD_OK, $path)) {
            continue;
        }
        $where = null;
        if (@readlink("$proc/$pid/ns/mnt") !== $init) {
            if ($pidns === null) {
                $pidns = [];
                $hostPid = @readlink("$proc/1/ns/pid");
                foreach ((array) $containers as $name => $c) {
                    $main = (int) ($c['pid'] ?? 0);
                    $ns = $main > 1 ? @readlink("$proc/$main/ns/pid") : false;
                    if ($ns !== false && $ns !== $hostPid) {        // --pid=host: the server's own namespace is no container's
                        $pidns[$ns] ??= (string) $name;
                    }
                }
            }
            $where = $pidns[(string) @readlink("$proc/$pid/ns/pid")] ?? '?';
        }
        $clean = watchmanClean($path, 200);
        $key = ($where === null ? 'host' : 'ct:' . watchmanClean($where, 100)) . ':' . watchmanOddKey($clean);
        if (!isset($out[$key])) {
            if (count($out) >= WATCH_HOST_MAX) {
                break;
            }
            // the program by its file's name: a process's own name changes (Firefox's «Privileged Cont», «Web Content» …)
            $out[$key] = ['where' => $where === null ? null : watchmanClean($where, 100), 'exe' => $clean,
                          'prog' => watchmanClean(basename($path), 32) ?: '?'];
        }
    }
    ksort($out);
    return $out;
}

/**
 * A program's path as the «odd place» rule reads it: the office's own hidden folders (Ms. Dustdevil's storeroom, Mr.
 * Restori's set-aside folder — hidden since issue #7) are no hidden folder of an intruder's; a scratch folder still is
 */
function watchmanOddPath(string $path): string
{
    return str_replace(['/' . OFFICE_STOREROOM, '/' . OFFICE_ASIDE], ['/' . OFFICE_STOREROOM_OLD, '/' . OFFICE_ASIDE_OLD], $path);
}

/**
 * The ways into the server from outside that Unraid's settings open — read from the flash only (ident.cfg, Unraid
 * Connect's connect.json and oidc.json, the WireGuard tunnels): SSH and its port, UPnP (the server opens ports on
 * the router by itself), Connect's remote access (its type and port), the WebGUI's single sign-ons (provider and
 * issuer), per WireGuard tunnel a fingerprint of each peer's public key. Never a private key, a token or a client
 * secret — those lines aren't even kept.
 *
 * @return array<string, array{what: string, name: string, on: bool, port?: ?int, peers?: list<string>, issuer?: string}>|null
 */
function watchmanHostDoors(array $paths): ?array
{
    if (!isset($paths['ident'])) {
        return null;
    }
    $ident = readCfg($paths['ident']);
    if (!$ident) {
        return null;
    }
    $out = [];
    $out['ssh'] = ['what' => 'ssh', 'name' => 'SSH', 'on' => ($ident['USE_SSH'] ?? 'no') === 'yes', 'port' => (int) ($ident['PORTSSH'] ?? 22) ?: 22];
    $out['upnp'] = ['what' => 'upnp', 'name' => 'UPnP', 'on' => ($ident['USE_UPNP'] ?? 'no') === 'yes'];
    $connect = isset($paths['connect']) ? readJson($paths['connect']) : null;
    if (is_array($connect)) {
        $type = strtoupper((string) ($connect['dynamicRemoteAccessType'] ?? 'DISABLED'));
        $type = preg_match('/^[A-Z_]{1,20}$/D', $type) ? $type : 'OTHER';
        $out['connect'] = ['what' => 'connect', 'name' => $type, 'on' => $type !== 'DISABLED', 'port' => (int) ($connect['wanport'] ?? 0) ?: null];
    }
    $oidc = isset($paths['oidc']) ? readJson($paths['oidc']) : null;
    foreach (array_slice((array) ($oidc['providers'] ?? []), 0, 20) as $pv) {
        $id = is_array($pv) ? (string) ($pv['id'] ?? '') : '';
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $id)) {
            $host = (string) (parse_url((string) ($pv['issuer'] ?? ''), PHP_URL_HOST) ?? '');
            $out["oidc:$id"] = ['what' => 'oidc', 'name' => watchmanClean((string) ($pv['name'] ?? $id), 60) ?: $id, 'on' => true,
                                'issuer' => watchmanClean($host, 120)];
        }
    }
    foreach (isset($paths['wireguard']) ? (glob($paths['wireguard'] . '/*.conf') ?: []) : [] as $file) {
        $tunnel = basename($file, '.conf');
        if (!preg_match('/^[A-Za-z0-9_.-]{1,32}$/D', $tunnel) || !watchmanPlain($file)) {
            continue;
        }
        $peers = [];
        foreach (explode("\n", (string) @file_get_contents($file, false, null, 0, 1 << 20)) as $line) {
            if (preg_match('/^\s*PublicKey\s*=\s*([A-Za-z0-9+\/=]{40,60})\s*$/', $line, $m) && count($peers) < 200) {
                $peers[] = watchmanHash('wg-peer:' . $m[1]);
            }
        }
        sort($peers);
        $out["wg:$tunnel"] = ['what' => 'wg', 'name' => $tunnel, 'on' => true, 'peers' => array_values(array_unique($peers))];
    }
    return $out + (isset($paths['api_cfg']) ? watchmanApiDoors((string) $paths['api_cfg']) : []);
}

// ===================================================================== Unraid's API as a door

/*
 * Unraid's own API (7.2+: a node service, GraphQL on /var/run/unraid-api.sock; nginx hands /graphql to it without
 * Unraid's login in front — the API checks a browser's session or an API key itself). Its keys are its locks: whoever
 * holds one reaches the API from the network with that key's rights (an ADMIN key: what root can in the web UI). He
 * watches them like his other doors, from the flash only, never asking the API: a new key, more rights for one (both
 * important), a key revoked is the new normal by itself; an ADMIN key (or one that can make itself one) is a posture tip.
 * Of each key file he keeps only its id, name, roles and a fingerprint of its permissions with a few of them in words —
 * never the key's value (not even a hash of it): the file is read, those fields taken, the rest dropped at once; a file
 * the same as the last round's isn't read again. Of the API's settings (configs/api.json) only the three that open
 * doors: the developer sandbox, extra origins, Unraid.net accounts that may log in — door_new like his other doors.
 */
const WATCH_API_KEY_MAX   = 64 * 1024;      // a key file is a few hundred bytes; a bigger one isn't read
const WATCH_API_KEYS_MAX  = 200;            // key files looked at
const WATCH_API_PERMS_MAX = 300;            // permissions per key (30 resources × 8 actions is all there is)
const WATCH_API_ID        = '/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/D';      // the API's ids are UUIDs
const WATCH_API_VERBS     = ['create', 'read', 'update', 'delete'];

/**
 * The API's keys in its store (a file per key; the API itself loads every name containing ".json"), stat first: a
 * file whose size and times are as the last round saw ($prev: file => m, c, s, h, k) isn't read again. Plain files
 * only (a link is never followed). Null: not looked at ($paths without the place).
 *
 * @return array{keys: array<string, array>, files: array<string, array>}|null  keys by id (watchmanApiKeyRead())
 */
function watchmanApiKeys(array $paths, array $prev): ?array
{
    if (!isset($paths['api_keys'])) {
        return null;
    }
    $dir = (string) $paths['api_keys'];
    $keys = $files = [];
    clearstatcache();
    foreach (is_dir($dir) ? (@scandir($dir) ?: []) : [] as $f) {
        if (!str_contains($f, '.json') || count($files) >= WATCH_API_KEYS_MAX) {
            continue;
        }
        $st = watchmanPlain("$dir/$f");
        if ($st === null) {
            continue;
        }
        $name = watchmanClean($f, 120);
        $p = $prev[$name] ?? null;
        $kept = is_array($p) && array_key_exists('k', $p)
            && ($p['k'] === null || (is_array($p['k']) && is_string($p['k']['id'] ?? null) && preg_match(WATCH_API_ID, $p['k']['id'])));
        if ($kept && watchmanSameFile($p, $st)) {
            $k = $p['k'];
        } else {
            $k = $st['size'] <= WATCH_API_KEY_MAX ? watchmanApiKeyRead("$dir/$f") : null;
        }
        $files[$name] = ['m' => (int) $st['mtime'], 'c' => (int) $st['ctime'], 's' => (int) $st['size'],
                         'h' => $k === null ? '-' : watchmanHash('api-key:' . jsonEncode($k)), 'k' => $k];
        if ($k !== null && !isset($keys[$k['id']])) {
            $keys[$k['id']] = $k;
        }
    }
    ksort($keys);
    ksort($files);
    return ['keys' => $keys, 'files' => $files];
}

/**
 * One key file, as little of it as says something: id, name, roles (as the API reads them: upper case), its
 * permissions as a set (RESOURCE:ACTION, the API's legacy forms normalised like normalizeLegacyAction()) — kept as a
 * fingerprint, their number, the first few in words — and whether it can do everything. The file's text and
 * everything else in it (the key's value above all) are dropped right after the four fields are taken. Null: not a
 * key in the API's shape (the API wouldn't load it either).
 *
 * @return array{id: string, name: string, roles: list<string>, perm: string, perms: int, rights: list<string>, full: bool}|null
 */
function watchmanApiKeyRead(string $file): ?array
{
    $raw = @file_get_contents($file, false, null, 0, WATCH_API_KEY_MAX + 1);
    $j = is_string($raw) && strlen($raw) <= WATCH_API_KEY_MAX ? json_decode($raw, true) : null;
    $raw = null;                            // the file's text — with the key's value — gone at once
    $f = is_array($j) ? array_intersect_key($j, ['id' => 1, 'name' => 1, 'roles' => 1, 'permissions' => 1]) : [];
    $j = null;                              // likewise everything but the four fields
    $id = $f['id'] ?? null;
    $name = is_string($f['name'] ?? null) ? watchmanClean($f['name'], 60) : '';
    if (!is_string($id) || !preg_match(WATCH_API_ID, $id) || $name === '') {
        return null;
    }
    $roles = [];
    foreach (is_array($f['roles'] ?? null) ? $f['roles'] : [] as $r) {
        $r = is_string($r) ? strtoupper(trim($r)) : '';
        if (preg_match('/^[A-Z_]{1,32}$/D', $r) && count($roles) < 10) {
            $roles[] = $r;
        }
    }
    $roles = array_values(array_unique($roles));
    sort($roles);
    $set = [];
    foreach (is_array($f['permissions'] ?? null) ? $f['permissions'] : [] as $p) {
        $res = is_array($p) && is_string($p['resource'] ?? null) ? strtoupper(trim($p['resource'])) : '';
        if (!preg_match('/^(?:[A-Z][A-Z_]{0,39}|\*)$/D', $res)) {
            continue;
        }
        foreach (is_array($p['actions'] ?? null) ? $p['actions'] : [] as $a) {
            $a = is_string($a) ? watchmanApiAction($a) : null;
            if ($a !== null && count($set) < WATCH_API_PERMS_MAX) {
                $set["$res:$a"] = true;
            }
        }
    }
    $set = array_keys($set);
    sort($set);
    return ['id' => $id, 'name' => $name, 'roles' => $roles, 'perm' => watchmanHash('api-perms:' . implode(',', $set)), 'perms' => count($set),
            'rights' => array_slice($set, 0, WATCH_LIST_MAX), 'full' => watchmanApiFull($roles, $set)];
}

/** An action as the API reads it (normalizeLegacyAction()): "read" → READ_ANY, "read:own" → READ_OWN, "READ_ANY" stays; null: none */
function watchmanApiAction(string $a): ?string
{
    $a = strtolower(trim($a));
    if ($a === '*') {
        return '*';
    }
    if (!str_contains($a, ':')) {
        $a = str_contains($a, '_') ? preg_replace('/_/', ':', $a, 1) : (in_array($a, WATCH_API_VERBS, true) ? "$a:any" : $a);
    }
    return preg_match('/^(create|read|update|delete):(any|own)$/D', (string) $a, $m) ? strtoupper("{$m[1]}_{$m[2]}") : null;
}

/**
 * Can this key do everything? The ADMIN role (casbin: *:*), any permission on every resource (*), or the right to
 * make or change API keys and permissions — such a key can give itself ADMIN (the API's create and addRole ask only
 * for API_KEY create/update).
 */
function watchmanApiFull(array $roles, array $set): bool
{
    if (in_array('ADMIN', $roles, true)) {
        return true;
    }
    foreach ($set as $p) {
        if (str_starts_with($p, '*:') || preg_match('/^(?:API_KEY|PERMISSION):(?:CREATE_|UPDATE_|\*)/', $p)) {
            return true;
        }
    }
    return false;
}

/**
 * The doors the API's own settings open (configs/api.json — only these three fields are taken): the developer
 * sandbox (the GraphQL playground and the schema for anyone who reaches /graphql), extra origins (other web pages
 * may call the API with a logged-in browser's session), Unraid.net accounts that may log in to the WebGUI
 * (ssoSubIds: a fingerprint of each, like a WireGuard peer). Nothing when the file isn't there.
 *
 * @return array<string, array{what: string, name: string, on: bool, peers?: list<string>}>
 */
function watchmanApiDoors(string $file): array
{
    if (watchmanPlain($file) === null) {
        return [];
    }
    $j = readJson($file);
    $cfg = is_array($j) ? array_intersect_key($j, ['sandbox' => 1, 'extraOrigins' => 1, 'ssoSubIds' => 1]) : [];
    $j = null;
    if (!$cfg) {
        return [];
    }
    $out = ['api_sandbox' => ['what' => 'api_sandbox', 'name' => 'GraphQL sandbox', 'on' => ($cfg['sandbox'] ?? false) === true]];
    foreach (array_slice(is_array($cfg['extraOrigins'] ?? null) ? $cfg['extraOrigins'] : [], 0, 20) as $o) {
        $u = is_string($o) ? parse_url(trim($o)) : false;
        $host = is_array($u) ? strtolower((string) ($u['host'] ?? '')) : '';
        if ($host === '' || !preg_match('/^[a-z0-9.:\[\]_-]{1,200}$/D', $host)) {
            continue;
        }
        $origin = strtolower((string) ($u['scheme'] ?? 'http')) . '://' . $host . (isset($u['port']) ? ':' . (int) $u['port'] : '');
        $origin = watchmanClean($origin, 120);
        $out["api_origin:$origin"] = ['what' => 'api_origin', 'name' => $origin, 'on' => true];
    }
    $subs = [];
    foreach (array_slice(is_array($cfg['ssoSubIds'] ?? null) ? $cfg['ssoSubIds'] : [], 0, 200) as $s) {
        if (is_string($s) && trim($s) !== '') {
            $subs[] = watchmanHash('api-sso:' . trim($s));
        }
    }
    $subs = array_values(array_unique($subs));
    sort($subs);
    $out['api_sso'] = ['what' => 'api_sso', 'name' => 'Unraid.net', 'on' => $subs !== [], 'peers' => $subs];
    return $out;
}

/** An API key's words in an entry: its name, roles, how many permissions and the first few, whether it can do everything */
function watchmanApiKeyWords(array $k): array
{
    return ['id' => (string) $k['id'], 'name' => (string) $k['name'], 'roles' => array_values((array) ($k['roles'] ?? [])),
            'perms' => (int) ($k['perms'] ?? 0), 'rights' => array_values((array) ($k['rights'] ?? [])), 'full' => !empty($k['full'])];
}

/** More than before: a role it didn't have, or other permissions (a fingerprint can't tell fewer from different — told) */
function watchmanApiKeyGrew(array $was, array $now): bool
{
    return array_diff((array) ($now['roles'] ?? []), (array) ($was['roles'] ?? [])) !== [] || ($now['perm'] ?? '') !== ($was['perm'] ?? '')
        || (!empty($now['full']) && empty($was['full']));
}

/**
 * A program's path as his memory keeps it: parts a program picks anew at every start become * — an AppImage's
 * mount (/tmp/.mount_firefoAb12Cd/…, Unraid's GUI mode runs Firefox so), a folder of mktemp (tmp.Xy12Ab) or another
 * name of letters and digits mixed. The entry still shows the real path.
 */
function watchmanOddKey(string $path): string
{
    $path = (string) preg_replace('#^/tmp/\.mount_([^/]*?)[A-Za-z0-9]{6}/#', '/tmp/.mount_$1*/', $path);
    $parts = explode('/', $path);
    foreach ($parts as $i => $part) {
        if ($i < count($parts) - 1 && preg_match('/^(?:tmp\.)?(?=[A-Za-z0-9]*\d)(?=[A-Za-z0-9]*[A-Za-z])[A-Za-z0-9]{6,}$/D', $part)) {
            $parts[$i] = '*';
        }
    }
    return implode('/', $parts);
}

/**
 * The host against what is normal ($known: baseline host — users, listen, procs; null: everything seen is normal,
 * his first look). Logs: this round against the last ($prev), only within one boot (/var/log is new at every boot).
 * $flash: the users on the flash — their changes are the flash watch's.
 *
 * @return list<string>  the kinds of new entries
 */
function watchmanHostCompare(?array &$known, ?array $seen, ?array $prev, array $flash, array &$book, int $now): array
{
    if ($seen === null) {
        return [];
    }
    $first = !is_array($known);
    $known = is_array($known) ? $known : [];
    $added = [];
    // logs: emptied (the same file, smaller), replaced or gone — unless logrotate did it
    if (!$first && is_array($seen['logs']) && is_array($prev['logs'] ?? null) && $seen['boot'] !== null && ($prev['boot'] ?? null) === $seen['boot']) {
        foreach ($seen['logs'] as $id => $cur) {
            $was = $prev['logs'][$id] ?? null;
            if (!is_array($was)) {
                continue;
            }
            $rotated = (is_array($cur) && in_array((int) $was['ino'], (array) $cur['old'], true)) || (($cur['rot'] ?? null) !== ($was['rot'] ?? null) && ($cur['rot'] ?? null) !== null);
            $how = null;
            if ($cur === null) {
                $how = $rotated ? null : 'gone';
            } elseif ((int) $cur['ino'] === (int) $was['ino']) {
                $how = (int) $cur['size'] < (int) $was['size'] ? 'cut' : null;
            } elseif (!$rotated) {
                $how = 'replaced';
            }
            if ($how !== null) {
                $added[] = watchmanBump($book, 'log_cleared', "log_cleared:$id", $now, 1,
                    ['log' => (string) (WATCH_HOST_LOGS[$id] ?? $id), 'how' => [$how],
                     'sizes' => [watchmanSize((int) $was['size']) . ' → ' . ($cur === null ? '–' : watchmanSize((int) $cur['size']))]]);
            }
        }
    }
    // accounts: a new one the flash doesn't have, a second root, a system account that got a login shell or a password
    if (is_array($seen['users'])) {
        $knownUsers = is_array($known['users'] ?? null) ? $known['users'] : null;
        foreach ($seen['users'] as $name => $u) {
            $k = $knownUsers[$name] ?? null;
            $onFlash = in_array($name, $flash, true);
            $why = [];
            if ($knownUsers !== null && !$first) {
                if ($k === null && !$onFlash) {
                    $why[] = 'new';
                }
                if ($u['uid'] === 0 && $name !== 'root' && (int) ($k['uid'] ?? -1) !== 0) {
                    $why[] = 'uid0';
                }
                if ($k !== null && !$onFlash) {
                    if ($u['shell'] && empty($k['shell'])) {
                        $why[] = 'shell';
                    }
                    if ($u['pw'] !== ($k['pw'] ?? 'locked') && $u['pw'] !== 'locked') {
                        $why[] = $u['pw'] === 'none' ? 'pw_none' : 'pw';
                    }
                }
            }
            if ($why) {
                $added[] = watchmanSet($book, 'user_ram', "user_ram:$name", $now, ['user' => (string) $name, 'uid' => $u['uid'], 'why' => $why]);
            } else {
                $knownUsers[$name] = $u;        // normal — or safer than before (a shell taken away, a password locked)
            }
        }
        $known['users'] = $knownUsers;
    }
    // Unraid's API came to his watch in 1.34: the first look at its keys and at the doors its settings open is normal
    // (a baseline of before, or a mirror of before for the night shift) — like his first look at anything
    $apiFirst = !$first && is_array($seen['api'] ?? null) && !is_array($known['api'] ?? null);
    // the ways in: one opened (switched on, another port, another type, a new single sign-on, a new tunnel or peer) is new;
    // one closed or a peer gone is the new normal by itself
    if (is_array($seen['doors'] ?? null)) {
        if ($first || !is_array($known['doors'] ?? null)) {
            $known['doors'] = $seen['doors'];
        } else {
            foreach ($apiFirst ? $seen['doors'] : [] as $key => $d) {
                if (str_starts_with((string) $key, 'api_')) {
                    $known['doors'][$key] = $d;
                }
            }
            foreach ($seen['doors'] as $key => $d) {
                $k = $known['doors'][$key] ?? null;
                $opened = $d['on'] && ($k === null || !$k['on'] || ($d['port'] ?? null) !== ($k['port'] ?? null)
                    || ($d['what'] === 'connect' && $d['name'] !== ($k['name'] ?? null)) || ($d['issuer'] ?? null) !== ($k['issuer'] ?? null));
                $newPeers = array_values(array_diff((array) ($d['peers'] ?? []), (array) ($k['peers'] ?? [])));
                if ($opened || ($k !== null && $newPeers)) {
                    $added[] = watchmanSet($book, 'door_new', "door_new:$key", $now, ['key' => (string) $key, 'door' => $d['what'], 'name' => $d['name'],
                        'port' => $d['port'] ?? null, 'issuer' => $d['issuer'] ?? null, 'peers' => count((array) ($d['peers'] ?? [])),
                        'new_peers' => $k === null ? count((array) ($d['peers'] ?? [])) : count($newPeers)]);
                } else {
                    $known['doors'][$key] = $d;     // the same, or closed: normal
                }
            }
            foreach (array_diff_key($known['doors'], $seen['doors']) as $key => $_) {
                unset($known['doors'][$key]);       // gone (a tunnel removed, a provider dropped): safer
            }
        }
    }
    // the API's keys: a new one, or more rights for one, is an entry; one revoked, renamed or with fewer roles is normal by itself
    if (is_array($seen['api'] ?? null)) {
        if ($first || $apiFirst) {
            $known['api'] = $seen['api'];
        } else {
            foreach ($seen['api'] as $id => $k) {
                $was = $known['api'][$id] ?? null;
                if (!is_array($was)) {
                    $added[] = watchmanSet($book, 'api_key_new', "api_key_new:$id", $now, watchmanApiKeyWords($k));
                } elseif (watchmanApiKeyGrew($was, $k)) {
                    $added[] = watchmanSet($book, 'api_key_changed', "api_key_changed:$id", $now, watchmanApiKeyWords($k)
                        + ['old_roles' => array_values((array) ($was['roles'] ?? [])), 'old_perms' => (int) ($was['perms'] ?? 0)]);
                } else {
                    $known['api'][$id] = $k;
                }
            }
            foreach (array_diff_key($known['api'], $seen['api']) as $id => $_) {
                unset($known['api'][$id]);          // revoked: safer
            }
        }
    }
    // ports and programs: what wasn't there is new; what stays away for long is forgotten
    foreach (['listen' => 'listen_new', 'procs' => 'proc_odd'] as $part => $kind) {
        if (!is_array($seen[$part])) {
            continue;
        }
        if ($first || !is_array($known[$part] ?? null)) {
            $known[$part] = array_map(fn () => $now, $seen[$part]);
            continue;
        }
        foreach ($seen[$part] as $key => $x) {
            if (isset($known[$part][$key])) {
                $known[$part][$key] = $now;
                continue;
            }
            // a program from an odd place counts once the round before saw it too: a container restarted between his
            // look at Docker and at /proc (the backup does that) would show for one round as no container's (ct:?)
            if ($part === 'procs' && !isset($prev['procs'][$key])) {
                continue;
            }
            $added[] = watchmanSet($book, $kind, "$kind:$key", $now, $x + ['key' => (string) $key]);
        }
        foreach ($known[$part] as $key => $t) {
            if (!isset($seen[$part][$key]) && $now - (int) $t > WATCH_FORGET) {
                unset($known[$part][$key]);
            }
        }
    }
    return array_values(array_filter($added));
}

// ===================================================================== how secure: his posture tips

/*
 * How secure the server stands now — not what changed, but what he would set differently, each with
 * why and where in Unraid. Every round, from RAM and the flash only (the shares and the containers as
 * the round saw them). A tip carries a signature of what it is about: one you said «I know, thanks» to
 * comes back once that changes, and is forgotten once it goes. Advice, not findings: never in the book,
 * never told to Unraid's notifications; the team lead gets one hint with how many there are.
 * Left to Fix Common Problems (its own checks): a root password, a plugin not known to Community
 * Applications, Unraid's FTP server once it has users — while FCP is installed it warns about that one,
 * so he keeps quiet then.
 */

/**
 * The facts his tips need beyond what a round sees anyway: Telnet (ident.cfg), Unraid's FTP server
 * (inetd.conf) and whether Fix Common Problems warns about it already, the CPU's protection, how many VMs
 * there are, and which names in sec.ini are disks, pools or the flash (for the link). A place missing
 * from $paths stays null — not looked at.
 */
function watchmanPostureLook(array $paths): array
{
    $inetd = isset($paths['inetd']) ? (string) @file_get_contents($paths['inetd'], false, null, 0, 65536) : null;
    $ftp = $inetd === null ? null : preg_match('/^\s*ftp\s/m', $inetd) === 1;
    $ident = isset($paths['ident']) ? readCfg($paths['ident']) : null;
    $connect = isset($paths['connect']) ? readJson($paths['connect']) : null;
    $remote = is_array($connect) ? strtoupper((string) ($connect['dynamicRemoteAccessType'] ?? 'DISABLED')) : null;
    return [
        'telnet'  => $ident === null ? null : ($ident['USE_TELNET'] ?? 'no') === 'yes',
        'upnp'    => $ident === null ? null : ($ident['USE_UPNP'] ?? 'no') === 'yes',
        // Unraid Connect's remote access: the WebGUI reachable from the internet (STATIC: a port on the router, UPNP: opened by UPnP)
        'remote'  => $remote === null || $remote === 'DISABLED' || !preg_match('/^[A-Z_]{1,20}$/D', $remote) ? null
                     : ['type' => $remote, 'port' => (int) ($connect['wanport'] ?? 0) ?: null],
        'ftp'     => $ftp,
        // Fix Common Problems warns about the FTP server itself once it has users (FTPrunning()): then it is its check
        'ftp_fcp' => $ftp === true && isset($paths['plugins'], $paths['ftp_users'])
                     && is_file($paths['plugins'] . '/fix.common.problems.plg') && is_file($paths['ftp_users']),
        'cpu'     => watchmanCpu($paths),
        'vms'     => watchmanVms($paths),
        'disks'   => isset($paths['disks_ini']) ? array_map('strval', array_keys(readCfg($paths['disks_ini'], true))) : [],
    ];
}

/**
 * How many VMs there are, from libvirt itself (`virsh list --all`: it keeps them in memory — never a look
 * into libvirt.img, which may lie on the array). The VM service off: 0, no VM can run. Null when libvirt
 * didn't answer (not looked: the last round's word stands).
 */
function watchmanVms(array $paths): ?int
{
    if (!isset($paths['libvirt_sock'], $paths['virsh'])) {
        return null;
    }
    if (!file_exists($paths['libvirt_sock'])) {
        return 0;
    }
    $virsh = str_contains($paths['virsh'], '/') ? $paths['virsh'] : bin($paths['virsh']);
    if ($virsh === null) {
        return null;
    }
    [$exit, $out] = run([$virsh, 'list', '--all', '--name'], 15);
    return $exit === 0 ? count(array_filter(array_map('trim', explode("\n", $out)), fn ($n) => $n !== '')) : null;
}

/**
 * The CPU's protection against speculative-execution flaws (Spectre & co.): switched off at boot
 * (mitigations=off) or not, and per flaw what the kernel says — still open ("Vulnerable") or covered
 * ("Mitigation: …"); the ones the CPU isn't affected by are left out. Null without the places.
 */
function watchmanCpu(array $paths): ?array
{
    if (!isset($paths['cmdline'], $paths['cpuinfo'], $paths['cpu_vulns'])) {
        return null;
    }
    $cmdline = (string) @file_get_contents($paths['cmdline'], false, null, 0, 8192);
    $info = (string) @file_get_contents($paths['cpuinfo'], false, null, 0, 65536);
    $vendor = preg_match('/^vendor_id\s*:\s*(\S+)/m', $info, $m) ? $m[1] : '';
    $open = $covered = [];
    foreach (glob($paths['cpu_vulns'] . '/*') ?: [] as $file) {
        $name = basename($file);
        if (!preg_match('/^[a-z0-9_]{1,40}$/D', $name)) {
            continue;
        }
        $state = trim((string) @file_get_contents($file, false, null, 0, 1024));
        if (str_starts_with($state, 'Vulnerable')) {
            $open[] = $name;
        } elseif (str_starts_with($state, 'Mitigation')) {
            $covered[] = $name;
        }
    }
    sort($open);
    sort($covered);
    $model = preg_match('/^model name\s*:\s*(.+)$/m', $info, $m) ? watchmanClean(trim($m[1]), 80) : null;
    return [
        'vendor'  => match ($vendor) { 'GenuineIntel' => 'Intel', 'AuthenticAMD' => 'AMD', default => $vendor !== '' ? watchmanClean($vendor, 20) : null },
        'model'   => $model,
        'off'     => preg_match('/(^|\s)mitigations=off(\s|$)/', $cmdline) === 1,
        'open'    => $open,
        'covered' => $covered,
    ];
}

/** At most a few names, and how many more ("a, b, c +2") — the same in every language */
function watchmanNames(array $names, int $max = 5): string
{
    $names = array_values(array_map('strval', $names));
    return implode(', ', array_slice($names, 0, $max)) . (count($names) > $max ? ' +' . (count($names) - $max) : '');
}

/** Where in Unraid a share's security is set: a user share's page, a disk's or a pool's, the boot device's for the flash */
function watchmanShareLink(string $name, array $disks): string
{
    if ($name === 'flash') {
        return '/Main/Boot?name=flash';
    }
    return (in_array($name, $disks, true) ? '/Shares/Disk?name=' : '/Shares/Share?name=') . rawurlencode($name);
}

/**
 * His posture tips, as this round sees it ($f: watchmanPostureLook(), $seen: the round's look with the
 * shares and containers; $prev: the last round's tips, for what libvirt didn't answer this time): id,
 * level, the words' params (names, numbers — the page puts them into its language), a link into Unraid
 * and a signature of what it is about. In the order of WATCH_POSTURE.
 *
 * @return list<array{id: string, level: string, p: array, link: ?array{to: string, path: string, name?: string}, sig: string}>
 */
function watchmanPosture(array $f, array $seen, array $prev = []): array
{
    $tips = [];
    $add = function (string $id, array $p, string $about, ?array $link) use (&$tips): void {
        $tips[$id] = ['id' => $id, 'level' => WATCH_POSTURE[$id], 'p' => $p, 'link' => $link, 'sig' => watchmanHash("$id|$about")];
    };
    // the flash exported to guests — reading alone is too much there: /boot/config/shadow (the password hashes), ssh/ (the keys)
    $flash = [];
    foreach (['smb' => 'SMB', 'nfs' => 'NFS'] as $proto => $label) {
        $level = (int) ($seen['shares']['flash'][$proto] ?? 0);
        if ($level >= 1) {
            $flash[$label] = $level;
        }
    }
    if ($flash) {
        $add('flash', ['proto' => implode(', ', array_keys($flash)), 'level' => max($flash) >= 2 ? 'public' : 'secure'],
            implode(',', array_map(fn ($k, $v) => "$k:$v", array_keys($flash), $flash)), ['to' => 'flash', 'path' => watchmanShareLink('flash', [])]);
    }
    // shares guests may read, write and delete (security «Public», exported) — user shares, disks, pools (the flash: above)
    $open = [];
    foreach ((array) ($seen['shares'] ?? []) as $name => $s) {
        foreach (['smb' => 'SMB', 'nfs' => 'NFS'] as $proto => $label) {
            if ((int) ($s[$proto] ?? 0) >= 2 && (string) $name !== 'flash') {
                $open[] = [(string) $name, $label];
            }
        }
    }
    if ($open) {
        $first = $open[0][0];
        $add('public', ['names' => watchmanNames(array_map(fn ($x) => "{$x[0]} ({$x[1]})", $open)), 'n' => count(array_unique(array_column($open, 0)))],
            implode(',', array_map(fn ($x) => "{$x[0]}:{$x[1]}", $open)),
            ['to' => 'share', 'name' => $first, 'path' => watchmanShareLink($first, (array) ($f['disks'] ?? []))]);
    }
    if (!empty($f['telnet'])) {
        $add('telnet', [], '', ['to' => 'access', 'path' => '/Settings/ManagementAccess']);
    }
    if (!empty($f['upnp'])) {
        $add('upnp', [], '', ['to' => 'access', 'path' => '/Settings/ManagementAccess']);
    }
    if (is_array($f['remote'] ?? null)) {
        $add('remote_access', ['type' => (string) $f['remote']['type'], 'port' => (string) ($f['remote']['port'] ?? '–')],
            $f['remote']['type'] . ':' . ($f['remote']['port'] ?? ''), ['to' => 'access', 'path' => '/Settings/ManagementAccess']);
    }
    if (!empty($f['ftp']) && empty($f['ftp_fcp'])) {
        $add('ftp', [], '', ['to' => 'ftp', 'path' => '/Settings/FTP']);
    }
    // an API key that can do everything (ADMIN, or it may make itself one): Unraid keeps its keys under Management Access → API Keys
    $full = [];
    foreach ((array) ($seen['host']['api'] ?? []) as $id => $k) {
        if (is_array($k) && !empty($k['full'])) {
            $full[(string) $id] = (string) ($k['name'] ?? $id);
        }
    }
    if ($full) {
        $add('api_admin', ['names' => watchmanNames(array_values($full)), 'n' => count($full)], implode(',', array_keys($full)),
            ['to' => 'access', 'path' => '/Settings/ManagementAccess']);
    }
    $cpu = is_array($f['cpu'] ?? null) ? $f['cpu'] : null;
    $boot = ['to' => 'boot', 'path' => '/Settings/BootParameters'];
    if ($cpu !== null && $cpu['off']) {
        $add('mitigations_off', ['cpu' => $cpu['model'] ?? $cpu['vendor'] ?? 'CPU', 'open' => $cpu['open']], implode(',', $cpu['open']), $boot);
        $vms = $f['vms'] ?? null;
        foreach ($vms === null ? $prev : [] as $t) {
            if (is_array($t) && ($t['id'] ?? null) === 'vmscape') {
                $vms = (int) ($t['p']['n'] ?? 0);       // libvirt didn't answer: as the last round saw it
            }
        }
        if (in_array('vmscape', $cpu['open'], true) && (int) $vms > 0) {
            $add('vmscape', ['n' => (int) $vms], 'vmscape', $boot);
        }
    } elseif ($cpu !== null && $cpu['covered']) {
        $add('mitigations_on', ['cpu' => $cpu['model'] ?? $cpu['vendor'] ?? 'CPU'], implode(',', $cpu['covered']), $boot);
    }
    // privileged containers that run or start by themselves; one stopped and without autostart is only a quiet line in the tip
    $privileged = $quiet = [];
    foreach ((array) ($seen['containers'] ?? []) as $name => $c) {
        if (in_array('--privileged', (array) ($c['tokens'] ?? []), true)) {
            if (($c['start'] ?? null) === 'off') {
                $quiet[] = (string) $name;
            } else {
                $privileged[] = (string) $name;
            }
        }
    }
    if ($privileged) {
        $add('privileged', ['names' => watchmanNames($privileged), 'n' => count($privileged)]
            + ($quiet ? ['quiet' => watchmanNames($quiet), 'quiet_n' => count($quiet)] : []), implode(',', $privileged), ['to' => 'docker', 'path' => '/Docker']);
    }
    // partner doors (the Team Lead's partner offices): the lines in authorized_keys, the pairs (not looked at: no tip of theirs)
    $partner = is_array($seen['partner'] ?? null) ? $seen['partner'] : null;
    $lead = ['to' => 'caretaker', 'path' => '#/caretaker'];
    $pairs = is_array($partner['pairs'] ?? null) ? $partner['pairs'] : null;
    $pairName = fn (string $id): string => (string) ($pairs[$id]['name'] ?? $id);
    $wide = $unknown = [];
    foreach ((array) ($partner['lines'] ?? []) as $id => $l) {
        if (empty($l['restrict']) || ($l['from'] ?? null) === null) {
            $wide[(string) $id] = $pairName((string) $id) . ' (' . implode(', ', array_merge(empty($l['restrict']) ? ['restrict'] : [], ($l['from'] ?? null) === null ? ['from='] : [])) . ')';
        }
        if ($pairs !== null && !isset($pairs[$id])) {
            $unknown[] = (string) $id;
        }
    }
    if ($wide) {
        $add('partner_wide', ['names' => watchmanNames(array_values($wide)), 'n' => count($wide)], implode(',', array_keys($wide)) . '|' . implode(',', $wide), $lead);
    }
    if ($unknown) {
        $add('partner_unknown', ['names' => watchmanNames(array_map(fn ($id) => "uso-partner:$id", $unknown)), 'n' => count($unknown)], implode(',', $unknown), $lead);
    }
    $public = $friend = [];
    foreach ((array) $pairs as $id => $p) {
        if (!empty($p['public'])) {
            $public[(string) $id] = "{$p['name']} ({$p['address']})";
        }
        if (($p['trust'] ?? '') === 'friend' && !empty($p['sends'])) {
            $friend[(string) $id] = (string) $p['name'];
        }
    }
    if ($public) {
        $add('partner_public', ['names' => watchmanNames(array_values($public)), 'n' => count($public)], implode(',', $public), $lead);
    }
    if ($friend) {
        $add('partner_friend', ['names' => watchmanNames(array_values($friend)), 'n' => count($friend)], implode(',', array_keys($friend)), $lead);
    }
    // the router's clock (the network, watchnetClock()): its lines' time off their arrival — every correlation would be off with it
    $clock = is_array($seen['net']['clock'] ?? null) ? $seen['net']['clock'] : [];
    if ($clock) {
        $c = $clock[0];
        $add('router_clock', ['router' => (string) $c['router'], 'minutes' => abs((int) $c['minutes']), 'zone' => !empty($c['zone']) ? 1 : 0, 'n' => count($clock)],
            implode(',', array_map(fn ($x) => $x['sender'] . ':' . (int) round($x['minutes'] / 15), $clock)), ['to' => 'advisor', 'path' => '#/advisor']);
    }
    return array_values(array_filter(array_map(fn ($id) => $tips[$id] ?? null, array_keys(WATCH_POSTURE))));
}

/**
 * His «I know, thanks» on posture tips (posture.json), kept only for tips that are still there — one
 * that went is forgotten, so it is told again when it comes back. Each with what the tip said then (`text`,
 * watchmanPostureText()): a note from before 1.44 has none — it gets today's (taken over as given for what the tip
 * says now: nothing comes back by this change itself). Null: no file and nothing to keep. $texts for the tests.
 */
function watchmanPostureKnown(?array $file, array $tips, ?callable $texts = null): ?array
{
    $texts ??= 'watchmanPostureText';
    $ids = array_column($tips, 'id');
    $acks = [];
    foreach ((array) ($file['acks'] ?? []) as $id => $a) {
        if (in_array((string) $id, $ids, true) && is_array($a) && is_string($a['sig'] ?? null)) {
            $acks[(string) $id] = ['sig' => $a['sig'], 'time' => (int) ($a['time'] ?? 0),
                                   'text' => is_string($a['text'] ?? null) ? $a['text'] : $texts((string) $id)];
        }
    }
    return $file === null && !$acks ? null : ['acks' => $acks];
}

/**
 * A tip you know about: thanked for, still about the same (its sig) and still saying the same (upgrade audit proposal
 * 9: an update that changes what the tip says or advises brings it back once; a note without `text` — from before
 * 1.44, until his next round takes it over — counts as the same)
 */
function watchmanPostureIsKnown(array $tip, ?array $file, ?callable $texts = null): bool
{
    $a = $file['acks'][$tip['id']] ?? null;
    if (!is_array($a) || ($a['sig'] ?? null) !== $tip['sig']) {
        return false;
    }
    return !is_string($a['text'] ?? null) || $a['text'] === ($texts ?? 'watchmanPostureText')((string) $tip['id']);
}

/** What a posture tip says: a short hash of its English posture.<id>.title and .why (the page's words for it) */
function watchmanPostureText(string $id): string
{
    static $en = null, $stamp = null;
    $file = OFFICE_WEB . '/desks/watchman/lang/en.json';
    $now = (int) @filemtime($file);
    if ($en === null || $stamp !== $now) {
        [$en, $stamp] = [readJson($file) ?? [], $now];
    }
    return substr(sha1(jsonEncode([$en["posture.$id.title"] ?? null, $en["posture.$id.why"] ?? null])), 0, 16);
}

/**
 * «I know, thanks» on one of his posture tips ($on true) or «Show again» (false) — for every browser.
 * Only a tip of his last round; what it is about is kept, so it comes back when that changes.
 */
function watchmanPostureAck(mixed $id, mixed $on, ?string $dir = null, ?int $now = null, bool $page = true): array
{
    if (!is_string($id) || !isset(WATCH_POSTURE[$id]) || !is_bool($on)) {
        throw new Problem('bad_request');
    }
    $dir ??= watchmanDir();
    $now ??= time();
    watchmanLocked($dir, function () use ($dir, $id, $on, $now): void {
        $d = watchmanLoad($dir);
        $tip = null;
        foreach ((array) ($d['state']['posture']['tips'] ?? []) as $t) {
            if (is_array($t) && ($t['id'] ?? null) === $id && is_string($t['sig'] ?? null)) {
                $tip = $t;
            }
        }
        if ($tip === null && $on) {
            throw new Problem('watch_tip_gone');
        }
        $old = readJson("$dir/posture.json");
        $new = ['acks' => (array) ($old['acks'] ?? [])];
        if ($on) {
            $new['acks'][$id] = ['sig' => $tip['sig'], 'time' => $now, 'text' => watchmanPostureText($id)];
        } else {
            unset($new['acks'][$id]);
        }
        watchmanSave($dir, ['posture' => $old], ['posture' => $new]);
    });
    if (!$page) {
        return ['ok' => true];
    }
    logLine("Night watchman: posture tip $id " . ($on ? 'noted («I know, thanks»)' : 'shown again'));
    return ['ok' => true, 'state' => watchmanPageState()];
}

/** What the page shows of his posture tips: each with whether you know it, how many of his advice are open */
function watchmanPosturePage(?array $posture, ?array $file): ?array
{
    if (!is_array($posture) || !is_array($posture['tips'] ?? null)) {
        return null;
    }
    $tips = [];
    foreach ($posture['tips'] as $t) {
        if (is_array($t) && isset(WATCH_POSTURE[$t['id'] ?? ''])) {
            $tips[] = ['id' => $t['id'], 'level' => WATCH_POSTURE[$t['id']], 'p' => (array) ($t['p'] ?? []), 'link' => $t['link'] ?? null,
                       'known' => watchmanPostureIsKnown($t, $file)];
        }
    }
    return ['time' => (int) ($posture['time'] ?? 0), 'tips' => $tips,
            'open' => count(array_filter($tips, fn ($t) => $t['level'] === 'advice' && !$t['known']))];
}

// ===================================================================== scheduled and auto-starting

/*
 * What starts on its own as root — where an intruder settles in (MITRE ATT&CK T1053) and where a
 * plugin can quietly double the server's jobs. RAM and flash only; a file is read again only when
 * its size or time moved (the last round's look, seen.json).
 *
 *   crontabs  root's own crontab (/var/spool/cron/crontabs/root: what `crontab -l` and `crontab -`
 *             use) next to Unraid's /etc/cron.d/root (update_cron builds it from the .cron files
 *             below). Unraid's crond reads both: a line in both runs twice. Reported: new lines,
 *             lines that run twice, the office's own lines (they belong in its cron file only — an
 *             old copy starts a second backup at its old time). With the evidence: the file's time
 *             and what the syslog said around it (who wrote it). New lines of other users' crontabs
 *             and /etc/cron.d's other files count too. Lines whose program went with its plugin are
 *             Ms. Dustdevil's (order, not security). He never changes a crontab — the fix stands in
 *             the entry as information.
 *   .cron     the plugins' cron files on the flash (what survives a reboot): a new file or new
 *             lines; one in the folder of no installed plugin counts more (update_cron leaves it
 *             out — until a plugin of that name comes)
 *   scripts   User Scripts: a new script, its content or schedule changed
 *   at        jobs waiting in atd's queue that aren't the office's own (hostLaunch() marks those); a User
 *             Script started «in the background» (the User Scripts plugin goes through `at NOW`) is only a
 *             line in the book, noted by itself — when the job is exactly that and nothing else
 *   agents    Unraid's notification agents: every file there runs as root with each notification.
 *             New or changed; their content holds tokens — only a fingerprint is kept.
 */
const WATCH_CRON_OFFICE   = '/plugins/' . OFFICE_PLUGIN . '/scripts/job.sh';
const WATCH_CRON_SAVE     = '/boot/config/crontab-root-before-cleanup.txt';
const WATCH_SCHED_MAX     = 500;            // lines, files, scripts, jobs — each
// User Scripts' «Run in background» (backgroundScript.sh): echo <launcher> "/tmp/…/tmpScripts/<name>/script" | at NOW -M
const WATCH_US_LAUNCHER   = '/usr/local/emhttp/plugins/user.scripts/startBackground.php';
const WATCH_US_TMP        = '/tmp/user.scripts/tmpScripts/';
// what such a job's environment must not set (it would run something else than the script), and where PATH may point
const WATCH_AT_ENV_BAD    = '/^(?:LD_\w*|BASH_ENV|ENV|BASH_FUNC_.*|PHPRC|PHP_INI_SCAN_DIR|PHP_\w*|PERL5OPT|PERL5LIB|PERLLIB|PYTHON\w*|RUBYOPT|RUBYLIB|NODE_OPTIONS|GCONV_PATH|GLIBC_TUNABLES|IFS|PS4|SHELLOPTS|BASHOPTS|PROMPT_COMMAND|CDPATH|GLOBIGNORE)$/D';
const WATCH_AT_PATH       = ['/usr/local/sbin', '/usr/local/bin', '/usr/sbin', '/usr/bin', '/sbin', '/bin'];
const WATCH_AT_SHELLS     = ['/bin/sh', '/bin/bash', '/usr/bin/sh', '/usr/bin/bash'];
const WATCH_EVIDENCE_SPAN = 120;            // syslog lines this many seconds around the crontab's time …
const WATCH_EVIDENCE_MAX  = 12;             // … at most so many, the closest
const WATCH_EVIDENCE_LINE = '#/plugins/|\bplugins?\b|crontab|update_cron|crond|\batd\b|\batq\b|\.(?:sh|php|py|plg|cron)\b|user\.scripts|unraid-secretary-office#i';

/** A short fingerprint */
function watchmanHash(string $s): string
{
    return substr(sha1($s), 0, 12);
}

/**
 * dcron's signal to reload (`crontab -c <dir> -` — update_cron — writes it with the user's name, crond
 * deletes it at its next minute): in /etc/cron.d and in the crontabs folder, never a crontab
 */
const WATCH_CRON_SIGNAL = 'cron.update';
/** A cron line's shape: five time fields (numbers, *, ranges, steps, lists, month and day names) or an @keyword, then a command */
define('WATCH_CRON_LINE', (function (): string {
    $atom = '(?:\*|\d{1,2}|(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec|sun|mon|tue|wed|thu|fri|sat))';
    $field = "$atom(?:-$atom)?(?:/\\d{1,3})?";
    $field = "$field(?:,$field)*";
    return "#^(?:@(?:reboot|yearly|annually|monthly|weekly|daily|midnight|hourly|noauto)|$field $field $field $field $field) \\S#i";
})());

/** @return list<string> a crontab's job lines, whitespace normalised — only what has a cron line's shape (no comments, VAR=value, stray text) */
function watchmanCronJobs(string $text): array
{
    $jobs = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim((string) preg_replace('/\s+/', ' ', $line));
        if ($line !== '' && $line[0] !== '#' && preg_match(WATCH_CRON_LINE, $line)) {
            $jobs[] = $line;
        }
    }
    return $jobs;
}

/** A job line's command: what follows the five time fields (or @daily & co.) */
function watchmanCronCommand(string $line): string
{
    return preg_match('/^(?:@\w+|\S+ \S+ \S+ \S+ \S+) (.+)$/', trim((string) preg_replace('/\s+/', ' ', $line)), $m) ? $m[1] : '';
}

/** Secrets out of a line that is shown: a URL's path and user, values of password/token/key settings, long tokens */
function watchmanScrub(string $s): string
{
    $s = (string) preg_replace('#\b([a-z][a-z0-9+.-]{1,15}://)(?:[^\s/@\'"]*@)?([^\s/\'"?\#]+)[^\s\'"]*#i', '$1$2/…', $s);
    $s = (string) preg_replace('/\b([\w.-]*(?:pass|pwd|token|secret|key|auth|api)[\w.-]*)(=|:\s*)("[^"]*"|\'[^\']*\'|\S+)/i', '$1$2…', $s);
    $s = (string) preg_replace('/(\s(?:-u|--user)[\s=]+)\S+|(\s-p)(?=\S*[^A-Za-z\s])\S+/', '$1$2…', $s);    // curl -u user:pw, mysql -pSecret
    return (string) preg_replace('/[A-Za-z0-9_+=-]{28,}/', '…', $s);
}

/** A job line, short and without secrets: its time fields, its command without redirections and without the plugins' folders in front */
function watchmanCronShort(string $line): string
{
    $line = trim((string) preg_replace('/\s+/', ' ', $line));
    $cmd = watchmanCronCommand($line);
    $when = $cmd === '' ? '' : trim(substr($line, 0, strlen($line) - strlen($cmd)));
    $cmd = $cmd === '' ? $line : $cmd;
    $cmd = (string) preg_replace('/\s*(?:\d?>>?|&>>?)\s*\S+|\s+\d>&\d|\s*\|\s*logger\b.*$/', '', $cmd);
    $cmd = str_replace(['/usr/local/emhttp/plugins/', '/boot/config/plugins/'], '', $cmd);
    return mb_strimwidth(watchmanClean(trim($when . ' ' . watchmanScrub($cmd)), 400), 0, 110, '…');
}

/** @return array{lines:int, jobs:list<string>, _h:list<string>} lines (hash => short) as an entry's words */
function watchmanJobs(array $lines): array
{
    return ['lines' => count($lines), 'jobs' => array_slice(array_values($lines), 0, WATCH_LIST_MAX), '_h' => array_keys($lines)];
}

/** What the office would type to undo it (never runs it): a copy of root's crontab to the flash first */
function watchmanCronFix(string $kind): string
{
    $save = 'crontab -l > ' . WATCH_CRON_SAVE . '; crontab -l | ';
    return match ($kind) {
        'cron_twice'  => $save . "grep -v -x -F -f <(grep -v '^#' /etc/cron.d/root | grep -v '^\\s*$') | crontab -",
        'cron_office' => $save . "grep -v -F '" . WATCH_CRON_OFFICE . "' | crontab -",
        default       => '',
    };
}

/**
 * Everything scheduled and auto-starting, as this round sees it; parts whose place isn't in $paths
 * are null. $prev: the last round's look (a file whose size and time are the same isn't read again).
 */
function watchmanSched(array $paths, array $prev, int $now): array
{
    $crontab = watchmanCrontabs($paths);
    if ($crontab !== null) {
        $crontab['evidence'] = watchmanEvidence($paths['syslog'], $crontab['mtime'], $prev['crontab']['evidence'] ?? null, $now);
    }
    return [
        'crontab' => $crontab,
        'files'   => watchmanCronFiles($paths, (array) ($prev['files'] ?? [])),
        'scripts' => watchmanUserScripts($paths, (array) ($prev['scripts'] ?? [])),
        'at'      => watchmanAtJobs($paths, is_array($prev['at'] ?? null) ? $prev['at'] : null),
        'agents'  => watchmanAgents($paths, (array) ($prev['agents'] ?? [])),
    ];
}

/** The same file as the last round saw (size, time and change time alike)? Then it isn't read again. */
function watchmanSameFile(?array $prev, array $st): bool
{
    return is_array($prev) && (int) ($prev['m'] ?? -1) === (int) $st['mtime'] && (int) ($prev['c'] ?? -1) === (int) $st['ctime']
        && (int) ($prev['s'] ?? -1) === (int) $st['size'] && is_string($prev['h'] ?? null);
}

/** Is it a plain file (no link, no folder)? */
function watchmanPlain(string $file): ?array
{
    $st = @lstat($file);
    return $st && ($st['mode'] & 0170000) === 0100000 ? $st : null;
}

/**
 * The crontabs crond reads besides Unraid's /etc/cron.d/root: root's own (and other users',
 * /etc/cron.d's other files — their lines named by where they are) — against that one.
 *
 * @return array{mtime: ?int, lines: array<string,string>, twice: array<string,string>, office: array<string,string>}|null
 *         lines by hash => short
 */
function watchmanCrontabs(array $paths): ?array
{
    if (!isset($paths['crontabs'], $paths['cron_d'])) {
        return null;
    }
    $system = array_flip(watchmanCronJobs((string) @file_get_contents($paths['cron_d'] . '/root', false, null, 0, 1 << 20)));
    $sources = [];
    foreach (glob($paths['crontabs'] . '/*') ?: [] as $f) {
        if (preg_match('/^[a-z_][a-z0-9_.-]{0,31}$/D', basename($f)) && basename($f) !== WATCH_CRON_SIGNAL) {
            $sources[] = [basename($f) === 'root' ? '' : basename($f) . ': ', $f, basename($f) === 'root', null];
        }
    }
    foreach (glob($paths['cron_d'] . '/*') ?: [] as $f) {
        if (basename($f) !== 'root' && basename($f) !== WATCH_CRON_SIGNAL) {
            $sources[] = ['cron.d/' . watchmanClean(basename($f), 60) . ': ', $f, false, basename($f)];
        }
    }
    // cron_d: /etc/cron.d's other files — name => their lines' hashes (a file that came with a plugin the office installed: watchmanOfficeLook())
    $out = ['mtime' => null, 'lines' => [], 'twice' => [], 'office' => [], 'cron_d' => []];
    foreach ($sources as [$label, $file, $root, $cronD]) {
        $st = watchmanPlain($file);
        if (!$st) {
            continue;
        }
        if ($root) {
            $out['mtime'] = (int) $st['mtime'];
        }
        foreach (watchmanCronJobs((string) @file_get_contents($file, false, null, 0, 1 << 20)) as $line) {
            if (count($out['lines']) >= WATCH_SCHED_MAX) {
                break 2;
            }
            $h = watchmanHash($label . $line);
            $short = $label . watchmanCronShort($line);
            $out['lines'][$h] = $short;
            if ($cronD !== null && preg_match('/^[A-Za-z0-9._+-]{1,100}$/D', $cronD)) {
                $out['cron_d'][$cronD][] = $h;
            }
            if (!$root) {
                continue;
            }
            if (str_contains($line, WATCH_CRON_OFFICE)) {
                $out['office'][$h] = $short;
            } elseif (isset($system[$line])) {
                $out['twice'][$h] = $short;
            }
        }
    }
    return $out;
}

/**
 * Who wrote root's crontab: the syslog lines around its time that name a plugin, a script or cron.
 * Looked up again only when its time moved, or the last look was before the span after it ended.
 *
 * @return array{mtime: int, lines: list<string>, complete: bool}|null
 */
function watchmanEvidence(string $syslog, ?int $mtime, ?array $prev, int $now): ?array
{
    if ($mtime === null) {
        return null;
    }
    if (is_array($prev) && (int) ($prev['mtime'] ?? -1) === $mtime && !empty($prev['complete'])) {
        return $prev;
    }
    return ['mtime' => $mtime, 'lines' => watchmanSyslogAround($syslog, $mtime, $now), 'complete' => $now - $mtime > WATCH_EVIDENCE_SPAN + 30];
}

/**
 * Syslog lines within $span (WATCH_EVIDENCE_SPAN) of $t (syslog.1, then syslog; found by halving, read
 * from there at most 4 MB) that name a plugin, a script or cron ($pattern; the snapshot watch: zfs, btrfs,
 * snapshots) — never a login line (a password may stand in it). The closest WATCH_EVIDENCE_MAX, by time,
 * each as "HH:MM:SS process: text", scrubbed.
 *
 * @return list<string>
 */
function watchmanSyslogAround(string $syslog, int $t, int $now, string $pattern = WATCH_EVIDENCE_LINE, int $span = WATCH_EVIDENCE_SPAN): array
{
    $hits = [];
    foreach (["$syslog.1", $syslog] as $file) {
        $h = @fopen($file, 'r');
        if (!$h) {
            continue;
        }
        $time = function (int $at) use ($h, $now): ?int {       // the time of the first whole line from $at
            fseek($h, $at);
            if ($at > 0) {
                fgets($h);
            }
            for ($i = 0; $i < 5 && ($l = fgets($h)) !== false; $i++) {
                $lt = watchmanLineTime($l, $now);
                if ($lt !== null) {
                    return $lt;
                }
            }
            return null;
        };
        $lo = 0;
        $hi = (int) (fstat($h)['size'] ?? 0);
        while ($hi - $lo > 65536) {
            $mid = intdiv($lo + $hi, 2);
            $lt = $time($mid);
            if ($lt === null || $lt >= $t - $span) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }
        fseek($h, $lo);
        if ($lo > 0) {
            fgets($h);
        }
        for ($read = 0; $read < 4 << 20 && ($line = fgets($h)) !== false; $read += strlen($line)) {
            $lt = watchmanLineTime($line, $now);
            if ($lt === null || $lt < $t - $span) {
                continue;
            }
            if ($lt > $t + $span) {
                break;
            }
            if (!preg_match($pattern, $line) || preg_match(WATCH_WEB, $line) || preg_match('/\ssshd[\w-]*(?:\[\d+\])?:/', $line)
                || preg_match(WATCH_SYSLOG_OWN, $line)) {
                continue;
            }
            $text = (string) preg_replace(['/^[A-Z][a-z]{2}\s+\d{1,2}\s+(\d\d:\d\d:\d\d)\s+\S+\s+/', '/^\d{4}-\d\d-\d\d[T ](\d\d:\d\d:\d\d)\S*\s+\S+\s+/'], '$1 ', rtrim($line));
            $hits[] = [abs($lt - $t), $lt, mb_strimwidth(watchmanClean(watchmanScrub($text), 400), 0, 220, '…')];
        }
        fclose($h);
    }
    usort($hits, fn ($a, $b) => $a[0] <=> $b[0]);
    $hits = array_slice($hits, 0, WATCH_EVIDENCE_MAX);
    usort($hits, fn ($a, $b) => $a[1] <=> $b[1]);
    return array_values(array_unique(array_column($hits, 2)));
}

/** The plugins' cron files on the flash: "<plugin>/<file>" => size, time, fingerprint, its lines (hash => short) */
function watchmanCronFiles(array $paths, array $prev): ?array
{
    if (!isset($paths['cron_files'])) {
        return null;
    }
    $out = [];
    foreach (glob($paths['cron_files'] . '/*/*.cron') ?: [] as $file) {
        $name = basename(dirname($file)) . '/' . basename($file);
        $st = watchmanPlain($file);
        if (!$st || count($out) >= WATCH_SCHED_MAX || !preg_match('#^[A-Za-z0-9._+-]{1,100}/[^/\x00-\x1F]{1,120}$#D', $name)) {
            continue;
        }
        $p = $prev[$name] ?? null;
        $office = $name === OFFICE_PLUGIN . '/' . OFFICE_PLUGIN . '.cron';
        if (watchmanSameFile($p, $st) && is_array($p['lines'] ?? null) && (!$office || is_array($p['own'] ?? null))) {
            $out[$name] = $p;
            continue;
        }
        $text = (string) @file_get_contents($file, false, null, 0, 1 << 20);
        $lines = $own = [];
        foreach (array_slice(watchmanCronJobs($text), 0, WATCH_SCHED_MAX) as $l) {
            $lines[watchmanHash($l)] = watchmanCronShort($l);
            if ($office && watchmanOfficeCronLine($l)) {
                $own[] = watchmanHash($l);
            }
        }
        $out[$name] = ['m' => (int) $st['mtime'], 'c' => (int) $st['ctime'], 's' => (int) $st['size'], 'h' => watchmanHash($text), 'lines' => $lines]
                    + ($office ? ['own' => $own] : []);
    }
    ksort($out);
    return $out;
}

/** User Scripts: name => size, time, fingerprint of its script, its schedule (a cron line, or User Scripts' word: daily, start …) */
function watchmanUserScripts(array $paths, array $prev): ?array
{
    if (!isset($paths['userscripts'])) {
        return null;
    }
    $dir = $paths['userscripts'];
    $schedule = [];
    foreach ((array) json_decode((string) @file_get_contents("$dir/schedule.json", false, null, 0, 1 << 20), true) as $key => $s) {
        if (is_string($key) && is_array($s)) {
            $f = (string) ($s['frequency'] ?? 'disabled');
            $schedule[basename(dirname($key))] = $f === 'custom' ? trim((string) ($s['custom'] ?? '')) : $f;
        }
    }
    $out = [];
    foreach (glob("$dir/scripts/*/script") ?: [] as $file) {
        $folder = basename(dirname($file));
        $name = watchmanClean($folder, 100);
        $st = watchmanPlain($file);
        if (!$st || count($out) >= WATCH_SCHED_MAX || $name === '') {
            continue;
        }
        $cron = watchmanClean($schedule[$folder] ?? 'disabled', 60) ?: 'disabled';
        $p = $prev[$name] ?? null;
        $h = watchmanSameFile($p, $st) ? $p['h'] : watchmanHash((string) @file_get_contents($file, false, null, 0, 4 << 20));
        $out[$name] = ['m' => (int) $st['mtime'], 'c' => (int) $st['ctime'], 's' => (int) $st['size'], 'h' => $h, 'cron' => $cron];
    }
    ksort($out);
    return $out;
}

/**
 * atd's queue: job file => whether it is the office's own (hostLaunch() marks it), when it is due,
 * whose (uid), what it runs (its first command, without the environment at puts in front: that may
 * hold secrets), the installed plugin whose own file it runs (watchmanAtPlugin()). Read again only when
 * the folder or the installed plugins changed.
 */
function watchmanAtJobs(array $paths, ?array $prev): ?array
{
    if (!isset($paths['atjobs'])) {
        return null;
    }
    $st = @stat($paths['atjobs']);
    if (!$st) {
        return ['m' => null, 'jobs' => []];
    }
    $pst = isset($paths['plugins']) ? @stat($paths['plugins']) : false;
    $pm = $pst ? (int) $pst['mtime'] : null;
    if (is_array($prev) && ($prev['m'] ?? null) === (int) $st['mtime'] && ($prev['pm'] ?? null) === $pm && ($prev['v'] ?? null) === 2
        && is_array($prev['jobs'] ?? null)) {
        return $prev;
    }
    $jobs = [];
    foreach (@scandir($paths['atjobs']) ?: [] as $f) {
        if (!preg_match('/^[a-zA-Z=][0-9a-f]{5}([0-9a-f]{8})$/D', $f, $m) || count($jobs) >= 200 || !watchmanPlain($paths['atjobs'] . "/$f")) {
            continue;
        }
        $text = (string) @file_get_contents($paths['atjobs'] . "/$f", false, null, 0, 65536);
        $ours = str_contains($text, "\n" . HOST_LAUNCH_MARK . "\n");
        $jobs[$f] = ['ours' => $ours, 'when' => hexdec($m[1]) * 60, 'uid' => preg_match('/^# atrun uid=(\d+)/m', $text, $u) ? (int) $u[1] : null,
                     'cmd' => $ours ? '' : watchmanAtCommand($text), 'us' => $ours ? null : watchmanAtUserScript($text, $paths['userscripts'] ?? null)]
                   + (!$ours && watchmanAtUnraid($text) ? ['unraid' => true] : []);
        if (!$ours && ($plugin = watchmanAtPlugin($text, $paths['plugins'] ?? null)) !== null) {
            $jobs[$f]['plugin'] = $plugin;
            $jobs[$f]['pname'] = watchmanAtPluginName($plugin, $paths['emhttp_plugins'] ?? null);
            $jobs[$f]['file'] = watchmanAtPluginFile((string) watchmanAtOnlyCommand($text));
        }
    }
    ksort($jobs);
    return ['v' => 2, 'm' => (int) $st['mtime'], 'pm' => $pm, 'jobs' => $jobs];
}

/**
 * A plugin's own at job (Benj, 2026-10-09: Fix Common Problems queues its scan with
 * `echo "/usr/local/emhttp/plugins/fix.common.problems/scripts/scan.php" | at now +10 min -M` from its
 * disks_mounted event, and its extended test the same way): exactly one command that runs a file of
 * /usr/local/emhttp/plugins/<name>/ — optionally after php, /usr/bin/php, bash or /bin/bash, with plain
 * arguments (no character the shell would act on) —, as root, with an environment that runs nothing else
 * (watchmanAtOnlyCommand()), where <name> is an installed plugin (its .plg in /var/log/plugins, as for
 * his plugin watch). The match's first group is the plugin, the second the file in its folder.
 */
const WATCH_AT_PLUGIN = '#^(?:(?:/usr/bin/php|php|/bin/bash|bash) )?/usr/local/emhttp/plugins/([A-Za-z0-9._+-]{1,100})/([A-Za-z0-9._+-]{1,100}(?:/[A-Za-z0-9._+-]{1,100}){0,8})(?: [A-Za-z0-9._+/=:,@%-]{1,200}){0,8}$#D';

/** The installed plugin whose own file an at job runs (WATCH_AT_PLUGIN), or null */
function watchmanAtPlugin(string $text, ?string $pluginsDir): ?string
{
    if ($pluginsDir === null || preg_match('/^# atrun uid=0 /m', $text) !== 1) {
        return null;
    }
    $cmd = watchmanAtOnlyCommand($text);
    if ($cmd === null || !preg_match(WATCH_AT_PLUGIN, trim($cmd), $m) || in_array($m[1], ['.', '..'], true)
        || preg_match('#(?:^|/)\.\.?(?:/|$)#', $m[2])) {
        return null;
    }
    return is_file("$pluginsDir/{$m[1]}.plg") ? $m[1] : null;
}

/** The file an at job of a plugin's runs, shortened to <plugin>/<file> like the cron lines (watchmanCronShort()) */
function watchmanAtPluginFile(string $cmd): string
{
    return preg_match(WATCH_AT_PLUGIN, trim($cmd), $m) ? "{$m[1]}/{$m[2]}" : '';
}

/**
 * A plugin's name as Unraid's plugin page shows it: its README.md's first line («####Fix Common Problems####»,
 * «**Fix Common Problems**»), else the plugin's file name
 */
function watchmanAtPluginName(string $plugin, ?string $dir): string
{
    $file = $dir === null ? null : "$dir/$plugin/README.md";
    $head = $file !== null && watchmanPlain($file) ? (string) @file_get_contents($file, false, null, 0, 512) : '';
    $line = trim(trim((string) strtok($head, "\n")), "#* \t\r");
    return $line !== '' && mb_check_encoding($line, 'UTF-8') && mb_strlen($line) <= 60 && !preg_match('/[\x00-\x1F\x7F<>]/', $line)
        ? $line : $plugin;
}

/**
 * The User Script an at job runs «in the background» (User Scripts' backgroundScript.sh pipes
 * "startBackground.php /tmp/user.scripts/tmpScripts/<name>/script" into `at NOW`), or null. Only when
 * that is all the job does — one command, exactly that, for a script that exists in $dir/scripts/<name>/
 * (a name with no character the shell would act on) — and its environment can't make it run something
 * else (no LD_PRELOAD and the like, PATH and SHELL only the system's: at runs the commands with $SHELL).
 */
function watchmanAtUserScript(string $text, ?string $dir): ?string
{
    if ($dir === null) {
        return null;
    }
    $cmd = watchmanAtOnlyCommand($text);
    $head = WATCH_US_LAUNCHER . ' ' . WATCH_US_TMP;
    if ($cmd === null || !str_starts_with($cmd, $head) || !str_ends_with($cmd, '/script')) {
        return null;
    }
    $name = substr($cmd, strlen($head), -strlen('/script'));
    if ($name === '' || $name === '.' || $name === '..' || strlen($name) > 200 || !mb_check_encoding($name, 'UTF-8')
        || preg_match('/[\x00-\x1F\x7F\/;&|`$<>()\\\\\'"*?\[\]{}~#!]/', $name) || !is_file("$dir/scripts/$name/script")) {
        return null;
    }
    return $name;
}

/**
 * Unraid's own at job: after an array start (or a network change) emhttp queues `sleep N;
 * /usr/local/emhttp/webGui/scripts/reload_services` — exactly that one command, as root, with an
 * environment that runs nothing else (watchmanAtOnlyCommand()). Never news.
 */
const WATCH_AT_UNRAID = '#^sleep [0-9]{1,3}; /usr/local/emhttp/webGui/scripts/reload_services$#D';

function watchmanAtUnraid(string $text): bool
{
    $cmd = watchmanAtOnlyCommand($text);
    return $cmd !== null && preg_match(WATCH_AT_UNRAID, $cmd) === 1 && preg_match('/^# atrun uid=0 /m', $text) === 1;
}

/**
 * The one command an at job runs, when that is all it does and its environment can't make it run
 * something else (no LD_PRELOAD and the like, PATH and SHELL only the system's: at runs the commands
 * with $SHELL) — else null. At's head (#!/bin/sh, # atrun …, umask, NAME=value; export NAME, the
 * `cd … || { … }`) and its optional `${SHELL:-/bin/sh} << 'marcinDELIMITER…'` wrapper are left out.
 */
function watchmanAtOnlyCommand(string $text): ?string
{
    $lines = explode("\n", rtrim($text, "\n"));
    $n = count($lines);
    // the head at writes: #!/bin/sh, # atrun …, # mail …, umask, the environment as NAME=value; export NAME
    for ($i = 0; $i < $n && !preg_match('/^cd\s.*\|\|\s*\{\s*$/', $lines[$i]); $i++) {
        $l = $lines[$i];
        if ($l === '' || $l[0] === '#' || preg_match('/^umask [0-7]+$/D', $l)) {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*); export \1$/D', $l, $m) || preg_match(WATCH_AT_ENV_BAD, $m[1])) {
            return null;            // anything else (a value over several lines too)
        }
        $value = stripslashes($m[2]);
        if (($m[1] === 'PATH' && array_diff(explode(':', $value), WATCH_AT_PATH)) || ($m[1] === 'SHELL' && !in_array($value, WATCH_AT_SHELLS, true))) {
            return null;
        }
    }
    for ($i++; $i < $n && trim($lines[$i]) !== '}'; $i++) {
        // the "cd … || { echo …; exit 1; }" at puts in front
    }
    $cmds = [];
    $delim = null;
    for ($i++; $i < $n; $i++) {
        $l = $lines[$i];
        if ($delim === null && !$cmds && preg_match("/^\\$\\{SHELL:-\\/bin\\/sh\\} << '(marcinDELIMITER[0-9a-f]+)'$/D", $l, $m)) {
            $delim = $m[1];
        } elseif ($delim !== null && $l === $delim) {
            $delim = '';
        } elseif (trim($l) !== '' && ltrim($l)[0] !== '#') {
            $cmds[] = $l;
        }
    }
    return count($cmds) === 1 ? $cmds[0] : null;
}

/** An at job's first command: after the "cd … || { … }" at puts in front (never the environment above it) */
function watchmanAtCommand(string $text): string
{
    $lines = explode("\n", $text);
    $start = null;
    foreach ($lines as $i => $l) {
        if (preg_match('/^cd\s.*\|\|\s*\{\s*$/', $l)) {
            $start = $i;
        } elseif ($start !== null && trim($l) === '}') {
            $start = $i + 1;
            break;
        }
    }
    foreach (array_slice($lines, $start ?? count($lines)) as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#' || preg_match('/^\$\{SHELL[^}]*\}\s*<</', $l)) {
            continue;
        }
        return mb_strimwidth(watchmanClean(watchmanScrub($l), 400), 0, 110, '…');
    }
    return '';
}

/** Unraid's notification agents (every file in their folder runs): name => size, time, a fingerprint (never the content: tokens) */
function watchmanAgents(array $paths, array $prev): ?array
{
    if (!isset($paths['agents'])) {
        return null;
    }
    $out = [];
    foreach (@scandir($paths['agents']) ?: [] as $f) {
        $file = $paths['agents'] . "/$f";
        $st = @lstat($file);
        if ($f === '.' || $f === '..' || !$st || count($out) >= 100) {
            continue;
        }
        $name = watchmanClean($f, 80);
        $p = $prev[$name] ?? null;
        if (watchmanSameFile($p, $st)) {
            $out[$name] = $p;
            continue;
        }
        $what = is_link($file) ? 'link:' . (string) @readlink($file) : (string) @file_get_contents($file, false, null, 0, 1 << 20);
        $out[$name] = ['m' => (int) $st['mtime'], 'c' => (int) $st['ctime'], 's' => (int) $st['size'], 'h' => substr(hash('sha256', 'uso-watchman:' . $what), 0, 16)];
    }
    ksort($out);
    return $out;
}

/**
 * Scheduled and auto-starting things against what is normal ($known: the baseline's part; a part
 * it doesn't have yet is learned as it is now). What went is the new normal (back again, it is
 * told again); $installed: the installed plugins (a .cron of none of them counts more).
 *
 * @return list<string>  the kinds of new entries
 */
function watchmanSchedCompare(?array &$known, ?array $seen, array $installed, array &$book, int $now, array $office = []): array
{
    if ($seen === null) {
        return [];
    }
    $known = is_array($known) ? $known : [];
    $added = [];

    $c = $seen['crontab'] ?? null;
    if (is_array($c)) {
        $k = $known['crontab'] ?? null;
        if (!is_array($k)) {
            // jobs that run twice and stray copies of the office's own lines are never "normal": not
            // learned here, so the next round reports them even when they were there before he came
            $known['crontab'] = ['lines' => $c['lines'], 'twice' => [], 'office' => []];
        } else {
            $k += ['lines' => [], 'twice' => [], 'office' => []];
            $ev = ['mtime' => $c['mtime'], 'evidence' => (array) ($c['evidence']['lines'] ?? [])];
            $new = array_diff_key($c['lines'], $c['twice'], $c['office'], (array) $k['lines']);
            // a cron.d file that came with a plugin the consultant installed (its name, then): the office's own — noted, never told
            foreach ((array) ($c['cron_d'] ?? []) as $file => $hashes) {
                $ours = isset($office['cron_d'][$file], $installed[$file]) ? array_intersect_key($new, array_flip((array) $hashes)) : [];
                if ($ours) {
                    $k['lines'] = (array) $k['lines'] + $ours;
                    $new = array_diff_key($new, $ours);
                    watchmanOfficeNote($book, 'cron_new', "cron_new:office:$file", $now, ['file' => "cron.d/$file", 'plugin' => (string) $file] + watchmanJobs($ours));
                }
            }
            $lists = [
                'cron_office' => array_diff_key($c['office'], (array) $k['office']),
                'cron_twice'  => array_diff_key($c['twice'], (array) $k['twice']),
                'cron_new'    => $new,
            ];
            foreach ($lists as $kind => $list) {
                if ($list) {
                    $added[] = watchmanSet($book, $kind, $kind, $now, watchmanJobs($list) + $ev);
                }
            }
            // (up to 1.28 also 'dead': lines whose program went with its plugin — Ms. Dustdevil's now; dropped here)
            $known['crontab'] = ['lines' => array_intersect_key((array) $k['lines'], $c['lines']), 'twice' => array_intersect_key((array) $k['twice'], $c['twice']),
                                 'office' => array_intersect_key((array) $k['office'], $c['office'])];
        }
    }

    $files = $seen['files'] ?? null;
    if (is_array($files)) {
        if (!is_array($known['files'] ?? null)) {
            $known['files'] = array_map(fn ($f) => ['h' => $f['h'], 'lines' => $f['lines']], $files);
        } else {
            foreach ($files as $name => $f) {
                $k = $known['files'][$name] ?? null;
                if ($k !== null && $k['h'] === $f['h']) {
                    continue;
                }
                $new = $k === null ? $f['lines'] : array_diff_key($f['lines'], (array) $k['lines']);
                if (!$new) {
                    $known['files'][$name] = ['h' => $f['h'], 'lines' => $f['lines']];      // lines gone, or no lines at all: normal
                    continue;
                }
                $plugin = (string) strtok((string) $name, '/');
                if ($plugin === OFFICE_PLUGIN && !array_diff_key($new, array_flip((array) ($f['own'] ?? [])))) {
                    // the office's own schedule, exactly as it writes it (a time changed on a desk's page): noted by himself
                    $known['files'][$name] = ['h' => $f['h'], 'lines' => $f['lines']];
                    watchmanOfficeNoteSchedule($book, (string) $name, $now, ['file' => (string) $name, 'plugin' => $plugin, 'new' => $k === null, 'office' => true] + watchmanJobs($new));
                    continue;
                }
                $kind = $plugin === 'dynamix' || isset($installed[$plugin]) ? 'cron_file' : 'cron_file_foreign';
                $added[] = watchmanSet($book, $kind, "$kind:$name", $now,
                    ['file' => (string) $name, 'plugin' => $plugin, 'new' => $k === null, 'office' => $plugin === OFFICE_PLUGIN] + watchmanJobs($new) + ['_f' => $f['h']]);
            }
            $known['files'] = array_intersect_key($known['files'], $files);
        }
    }

    $scripts = $seen['scripts'] ?? null;
    if (is_array($scripts)) {
        if (!is_array($known['scripts'] ?? null)) {
            $known['scripts'] = array_map(fn ($s) => ['h' => $s['h'], 'cron' => $s['cron']], $scripts);
        } else {
            foreach ($scripts as $name => $s) {
                $k = $known['scripts'][$name] ?? null;
                if ($k === null) {
                    $added[] = watchmanSet($book, 'script_new', "script_new:$name", $now, ['name' => (string) $name, 'cron' => $s['cron'], '_f' => $s['h']]);
                    continue;
                }
                $content = $k['h'] !== $s['h'];
                if (!$content && $k['cron'] === $s['cron']) {
                    continue;
                }
                if (!$content && $s['cron'] === 'disabled') {
                    $known['scripts'][$name]['cron'] = 'disabled';      // switched off: safer, normal
                    continue;
                }
                $added[] = watchmanSet($book, 'script_changed', "script_changed:$name", $now,
                    ['name' => (string) $name, 'content' => $content, 'cron' => $s['cron'], 'old' => (string) $k['cron'], '_f' => $s['h']]);
            }
            $known['scripts'] = array_intersect_key($known['scripts'], $scripts);
        }
    }

    $at = $seen['at'] ?? null;
    if (is_array($at)) {
        // the office's own (hostLaunch()) and Unraid's own (reload_services after an array start) are never news
        $foreign = array_filter((array) $at['jobs'], fn ($j) => empty($j['ours']) && empty($j['unraid']));
        watchmanAtUnraidClose($book, $now);
        watchmanAtPluginClose($book, $installed, $now);
        foreach ($foreign as $f => $j) {
            if (is_string($j['us'] ?? null) && $j['us'] !== '') {
                unset($foreign[$f]);
                watchmanAtUserScriptNote($book, (string) $f, $j, $now);      // nothing to tell: a line in the book, noted
            } elseif (is_string($j['plugin'] ?? null) && $j['plugin'] !== '') {
                unset($foreign[$f]);
                watchmanAtPluginNote($book, (string) $f, $j, $now);          // a plugin's own job: likewise
            }
        }
        if (!is_array($known['at'] ?? null)) {
            $known['at'] = array_fill_keys(array_keys($foreign), true);
        } else {
            foreach ($foreign as $f => $j) {
                if (!isset($known['at'][$f])) {
                    $added[] = watchmanSet($book, 'at_job', "at_job:$f", $now, ['job' => (string) $f, 'when' => (int) $j['when'], 'cmd' => (string) $j['cmd'], 'uid' => $j['uid']]);
                }
            }
            $known['at'] = array_intersect_key($known['at'], $foreign);
        }
    }

    $agents = $seen['agents'] ?? null;
    if (is_array($agents)) {
        if (!is_array($known['agents'] ?? null)) {
            $known['agents'] = array_map(fn ($a) => $a['h'], $agents);
        } else {
            foreach ($agents as $name => $a) {
                $k = $known['agents'][$name] ?? null;
                if ($k !== $a['h']) {
                    $added[] = watchmanSet($book, 'notify_agent', "notify_agent:$name", $now, ['name' => (string) $name, 'new' => $k === null, '_f' => $a['h']]);
                }
            }
            $known['agents'] = array_intersect_key($known['agents'], $agents);
        }
    }
    return array_values(array_filter($added));
}

/**
 * A User Script started «in the background»: a line in the book, noted by itself (never open, never
 * told, nothing for the team lead) — once per job, which keeps its number while atd runs it
 * (a<number> waiting, =<number> running).
 */
/** Entries up to 1.30 that were only Unraid's own reload_services job: closed, noted by himself (`by` unraid) */
function watchmanAtUnraidClose(array &$book, int $now): void
{
    foreach ($book as $i => $e) {
        if (($e['kind'] ?? '') === 'at_job' && watchmanOpen($e) && preg_match(WATCH_AT_UNRAID, (string) ($e['p']['cmd'] ?? '')) && ($e['p']['uid'] ?? null) === 0) {
            $book[$i]['noted'] = $now;
            $book[$i]['by'] = 'unraid';
        }
    }
}

/**
 * Open entries that were only a plugin's own job (Fix Common Problems' scan before 1.48): closed, noted by himself
 * (`by` plugin) — the command as kept (watchmanAtCommand(), whole: no «…») a file of a plugin installed now, as root
 */
function watchmanAtPluginClose(array &$book, array $plugins, int $now): void
{
    foreach ($book as $i => $e) {
        $cmd = (string) ($e['p']['cmd'] ?? '');
        if (($e['kind'] ?? '') === 'at_job' && watchmanOpen($e) && ($e['p']['uid'] ?? null) === 0 && preg_match(WATCH_AT_PLUGIN, $cmd, $m)
            && isset($plugins[$m[1]]) && !preg_match('#(?:^|/)\.\.?(?:/|$)#', $m[2])) {
            $book[$i]['noted'] = $now;
            $book[$i]['by'] = 'plugin';
        }
    }
}

/** A plugin's own at job: a line in the book naming the plugin, noted by himself (`by` plugin) — once per job */
function watchmanAtPluginNote(array &$book, string $job, array $j, int $now): void
{
    $key = 'at_plugin:' . substr($job, 1);
    foreach ($book as $e) {
        if (($e['key'] ?? '') === $key) {
            return;
        }
    }
    $e = watchmanEntry('at_plugin', $key, $now, ['job' => $job, 'plugin' => watchmanClean((string) $j['plugin'], 100),
                                                 'name' => watchmanClean((string) ($j['pname'] ?? $j['plugin']), 60),
                                                 'file' => watchmanClean((string) ($j['file'] ?? ''), 200), 'when' => (int) ($j['when'] ?? 0),
                                                 'uid' => $j['uid'] ?? null]);
    $e['noted'] = $now;
    $e['by'] = 'plugin';
    $book[] = $e;
}

function watchmanAtUserScriptNote(array &$book, string $job, array $j, int $now): void
{
    $key = 'at_userscript:' . substr($job, 1);
    foreach ($book as $e) {
        if (($e['key'] ?? '') === $key) {
            return;
        }
    }
    $e = watchmanEntry('at_userscript', $key, $now, ['job' => $job, 'name' => watchmanClean((string) $j['us'], 100), 'when' => (int) ($j['when'] ?? 0),
                                                     'uid' => $j['uid'] ?? null]);
    $e['noted'] = $now;
    $e['by'] = 'auto';
    $book[] = $e;
}

/** «I know, thanks» on one of these kinds: what it names, as the last round saw it, becomes normal */
function watchmanSchedAdopt(array &$b, string $kind, array $p, array $seen): void
{
    $s = (array) ($seen['sched'] ?? []);
    $b['sched'] = is_array($b['sched'] ?? null) ? $b['sched'] : [];
    $part = fn (string $k, array $empty) => is_array($b['sched'][$k] ?? null) ? $b['sched'][$k] : $empty;
    $h = array_flip(array_map('strval', (array) ($p['_h'] ?? [])));
    switch ($kind) {
        case 'cron_new':
        case 'cron_twice':
        case 'cron_office':
            $field = ['cron_new' => 'lines', 'cron_twice' => 'twice', 'cron_office' => 'office'][$kind];
            $c = $part('crontab', ['lines' => [], 'twice' => [], 'office' => []]);
            $c[$field] = (array) ($c[$field] ?? []) + array_intersect_key((array) ($s['crontab'][$field] ?? []), $h);
            $b['sched']['crontab'] = $c;
            break;
        case 'cron_file':
        case 'cron_file_foreign':
            $f = $s['files'][$p['file'] ?? ''] ?? null;
            if (is_array($f)) {
                $b['sched']['files'] = $part('files', []);
                $b['sched']['files'][(string) $p['file']] = ['h' => $f['h'], 'lines' => $f['lines']];
            }
            break;
        case 'script_new':
        case 'script_changed':
            $x = $s['scripts'][$p['name'] ?? ''] ?? null;
            if (is_array($x)) {
                $b['sched']['scripts'] = $part('scripts', []);
                $b['sched']['scripts'][(string) $p['name']] = ['h' => $x['h'], 'cron' => $x['cron']];
            }
            break;
        case 'at_job':
            if (isset($s['at']['jobs'][$p['job'] ?? ''])) {
                $b['sched']['at'] = $part('at', []);
                $b['sched']['at'][(string) $p['job']] = true;
            }
            break;
        case 'notify_agent':
            $a = $s['agents'][$p['name'] ?? ''] ?? null;
            if (is_array($a)) {
                $b['sched']['agents'] = $part('agents', []);
                $b['sched']['agents'][(string) $p['name']] = $a['h'];
            }
            break;
    }
}

// ===================================================================== data flow

/*
 * Who pulls how much — read only, cheap, from what the kernel and Samba already count; nothing is
 * switched on for it (no Samba auditing, no conntrack accounting) and no disk wakes up.
 *
 *   clients     the established TCP connections of the server's file services (ss -tin: SMB 445/139,
 *               NFS 2049, SSH with SFTP/scp/rsync, the WebGUI with its File Manager — the ports from
 *               var.ini): the bytes each connection delivered (tcp_info bytes_acked, else bytes_sent),
 *               diffed against the same connection in the last round, summed per client address and
 *               service. A connection that opened and closed between two rounds is lost.
 *   SMB         smbstatus -b: a new user, a new machine, a session started at an hour of the week
 *               that machine never used (after its learning time)
 *   containers  per container what its network namespace sent (/proc/<pid>/net/dev, every kind of
 *               network: bridge, macvlan, ipvlan); containers on the host's network can't be told
 *               apart from the server itself; containers sharing one namespace count once
 *   shares      per share of an awake ZFS pool (or ZFS array disk) the bytes written (ZFS `written`
 *               of its datasets: since their latest snapshot — rewritten files count, which is what
 *               encrypting ransomware does; without a snapshot only growth shows)
 *   gone        what vanished between two rounds (deleted — or moved somewhere else): per user share
 *               the drop of ZFS `referenced` of its datasets on every awake ZFS pool (pools asleep are
 *               named; `usedbysnapshots` rising tells how much its snapshots still hold); per awake
 *               XFS/btrfs disk or pool the drop of its used space (statfs — a disk, not a share: the
 *               shares with a folder there are named; while its btrfs snapshots changed lately, its
 *               used space says nothing). Not a loss: the office's own work (the engine's lock), what
 *               moves data (watchmanFlowMovers(): the mover, EmbyCache, the gather, rsync
 *               --remove-source-files, Ms. Dustdevil emptying her storeroom — seen this round or the
 *               last), a dataset renamed into her storeroom, snapshots pruned (they change
 *               usedbysnapshots, not referenced). Told while learning: one round over WATCH_GONE_NEW
 *               or WATCH_GONE_PART of the share; with who was connected over SMB, NFS or SSH then —
 *               or nobody: it came from the server itself
 *
 * Learned per client/service, container and share: hourly sums for WATCH_FLOW_KEEP; unusual is an
 * hour far above (WATCH_FLOW_FACTOR) what it was at that time of the week (±1 h) or a quarter of its
 * busiest hour, and at least WATCH_FLOW_MIN — only after WATCH_FLOW_LEARN; before that only very clear
 * cases (one round over WATCH_FLOW_NEW, a share's written over WATCH_FLOW_PART of its size). «I know,
 * thanks» raises that one's normal (`ack`). While the engine's lock is held (a backup, a check, a
 * restore) the office's own work is kept apart (`o`), never learned, never told: the Kopia container's
 * traffic, what is written into the backup place's share (packages, dumps), during a restore all that
 * is written. What its snapshots do to `written` is no write at all (a new one: counted from there; one
 * deleted: that round is left out). Media servers stream — that is their job: learned, shown, never told.
 */
const WATCH_FLOW_LEARN    = 7 * 86400;          // learning time per client, container, share
const WATCH_FLOW_KEEP     = 14 * 86400;         // hourly sums kept this long
const WATCH_FLOW_FACTOR   = 4;                  // unusual: more than this many times the normal at this time of the week …
const WATCH_FLOW_MIN      = 2 * 1024 ** 3;      // … and at least this much in the hour (2 GB)
const WATCH_FLOW_NEW      = 50 * 1024 ** 3;     // still learning: one round over this (50 GB) …
const WATCH_FLOW_PART     = 0.2;                // … or into a share over this part of its size,
const WATCH_FLOW_WRITE    = 1024 ** 3;          //     at least this much (1 GB)
const WATCH_FLOW_TINY     = 1024 ** 2;          // a past hour under this (1 MB) isn't kept
const WATCH_FLOW_GOING    = 2 * WATCH_EVERY + 120;   // rounds this close: the same pull going on
const WATCH_FLOW_STALE    = 1800;               // counters older than this: start counting anew
const WATCH_FLOW_CLIENTS  = 64;                 // client/service pairs followed
const WATCH_FLOW_CTS      = 100;                // containers followed
const WATCH_FLOW_SHARES   = 200;                // shares followed
const WATCH_FLOW_CONNS    = 5000;               // connections whose counters are kept until the next round (RAM)
const WATCH_FLOW_DATASETS = 5000;               // ZFS datasets likewise
const WATCH_FLOW_SMB_MAX  = 200;                // SMB users and machines known
const WATCH_FLOW_TOP      = 8;                  // shares in the metrics
const WATCH_FLOW_MEDIA    = '/(?:^|[\/_.:-])(?:emby|jellyfin|plex)/i';
const WATCH_FLOW_OFFICE   = ['backup', 'check', 'dryrun', 'restore'];     // holders of the engine's lock whose traffic is the office's own
const WATCH_FLOW_SERVICES = ['smb' => 'SMB', 'nfs' => 'NFS', 'ssh' => 'SSH', 'web' => 'WebGUI'];
const WATCH_GONE_NEW      = 100 * 1024 ** 3;    // gone, still learning: one round over this (100 GB) …
const WATCH_GONE_PART     = 0.1;                // … or over this part of the share (and at least WATCH_FLOW_WRITE)
const WATCH_GONE_SNAPS    = 1800;               // a btrfs disk whose snapshots changed this recently: its used space says nothing
const WATCH_DATASET       = '#^[A-Za-z0-9_.:-]+(?:/[^\x00-\x1F@/]+)*$#D';     // a ZFS dataset's name as zfs prints it
const WATCH_DOCKER_LAYER  = '/^([0-9a-f]{64})(-init)?$/D';      // Docker on ZFS: a layer's or a container's dataset (its -init beside it)
const WATCH_FLOW_BY       = 40;                 // sources of a share's pull kept (the smallest go into «the rest»)
const WATCH_FLOW_SOURCES  = 5;                  // sources an entry names, then «the rest»
const WATCH_FLOW_LAYERS   = 600;                // Docker layers whose image is remembered (per agent run)
const WATCH_FLOW_LAYERS_AGAIN = 600;            // a layer no image named: asked again after this long

/** The counters of the last round (RAM: they mean nothing after a reboot), per data folder */
function watchmanFlowCountersFile(string $dir): string
{
    @mkdir(RUN_DIR, 0700, true);
    return RUN_DIR . '/watchman-flow-' . substr(md5(dataPathUser($dir)), 0, 8) . '.json';
}

/** The last round's counters, or null (none, or too old to diff against) */
function watchmanFlowCounters(string $dir, int $now): ?array
{
    $c = readJson(watchmanFlowCountersFile($dir));
    $t = (int) ($c['time'] ?? 0);
    return $c !== null && $t > 0 && $t < $now && $now - $t <= WATCH_FLOW_STALE ? $c : null;
}

/** The ports of the file services: SMB, NFS, SSH and the WebGUI as var.ini has them */
function watchmanFlowPorts(array $var): array
{
    $ports = [445 => 'smb', 139 => 'smb', 2049 => 'nfs'];
    foreach (['PORTSSH' => ['ssh', 22], 'PORT' => ['web', 80], 'PORTSSL' => ['web', 443]] as $key => [$svc, $default]) {
        $p = (int) ($var[$key] ?? $default);
        $ports[$p >= 1 && $p <= 65535 ? $p : $default] ??= $svc;
    }
    ksort($ports);
    return $ports;
}

/**
 * What the data flow sees now (outside the book's lock): the connections, SMB's sessions, every
 * running container's sent bytes, the awake ZFS shares' written bytes, who holds the engine's lock.
 * A part that can't be looked at is null; the page says so.
 */
function watchmanFlowLook(array $paths, ?array $containers): array
{
    $var = readCfg($paths['var_ini']);
    $ports = watchmanFlowPorts($var);
    $conns = null;
    if (bin('ss') !== null) {
        $filter = '( ' . implode(' or ', array_map(fn ($p) => "sport = :$p", array_keys($ports))) . ' )';
        [$exit, $out] = hostNet(['ss', '-tinH', 'state', 'established', $filter], 20);
        $conns = $exit === 0 ? watchmanSsParse($out, $ports) : null;
    }
    $smb = null;
    if (($var['shareSMBEnabled'] ?? 'yes') === 'no') {
        $smb = ['on' => false, 'sessions' => []];
    } elseif (bin('smbstatus') !== null) {
        [, $out] = run(['smbstatus', '-b', '--json'], 20);
        $sessions = watchmanSmbParse($out);
        if ($sessions === null) {
            [, $out] = run(['smbstatus', '-b'], 20);      // a Samba without --json
            $sessions = watchmanSmbParse($out);
        }
        $smb = $sessions === null ? null : ['on' => true, 'sessions' => $sessions];
    }
    $cts = null;
    if (is_array($containers)) {
        $host = (string) @readlink('/proc/1/ns/net');
        $cts = [];
        foreach ($containers as $name => $c) {
            $pid = (int) ($c['pid'] ?? 0);
            if ($pid <= 1) {
                continue;               // not running
            }
            $ns = (string) @readlink("/proc/$pid/ns/net");
            $tx = watchmanNetDevTx((string) @file_get_contents("/proc/$pid/net/dev"));
            if ($ns === '' || $tx === null) {
                continue;
            }
            $cts[(string) $name] = ['pid' => $pid, 'ns' => $ns, 'tx' => $tx, 'host' => $host !== '' && $ns === $host, 'image' => (string) ($c['image'] ?? '')];
        }
    }
    $holder = function_exists('backupLockHolder') ? backupLockHolder() : null;
    $settings = function_exists('backupReadSettings') ? backupReadSettings(BACKUP_DATA_DIR . '/settings.ini') : [];
    $kopia = (string) backupSetting($settings, 'kopia', 'container', '');
    $place = (string) backupSetting($settings, 'general', 'dumps_share', '');
    $snapDirs = array_values(array_unique(['.btrfs-snap', basename((string) backupSetting($settings, 'general', 'btrfs_snap_dir', '.btrfs-snap'))]));
    // who wrote into a share (watchmanFlowSources()): every container's writable layer by its dataset (Docker on ZFS), the
    // image layers named only when an entry asks for them (watchmanFlowLayers()), the folder libvirt's image lies in
    $dockerDs = null;
    if (is_array($containers)) {
        $dockerDs = [];
        foreach ($containers as $name => $c) {
            if (is_string($c['ds'] ?? null)) {
                $dockerDs[$c['ds']] = (string) $name;
            }
        }
    }
    return ['conns' => $conns, 'smb' => $smb, 'containers' => $cts, 'zfs' => watchmanFlowZfs($paths), 'nfs' => ($var['shareNFSEnabled'] ?? 'no') === 'yes',
            'docker_ds' => $dockerDs, 'layers' => fn (array $ids): array => watchmanFlowLayers($ids),
            'libvirt' => isset($paths['domain_cfg']) ? watchmanFlowLibvirt(readCfg((string) $paths['domain_cfg'])) : null,
            'holder' => $holder['holder'] ?? null, 'kopia' => $kopia !== '' ? $kopia : null,
            'office_shares' => array_values(array_unique(array_filter([BACKUP_OFFICE_SHARE, $place]))),
            'disks' => watchmanFlowDisks($paths, $snapDirs), 'moving' => watchmanFlowMovers($paths['proc'] ?? '/proc'),
            'partner' => watchmanFlowPartner($paths, $holder['holder'] ?? null)];
}

/**
 * The partner offices in the data flow (agent/lib/partnerlook.php): the pairs' addresses (and what their door lines name
 * in from=), whether the door receives now (RUN_DIR/partner/door-*.json), when it last received (received/<id>.json),
 * whether this office sends now (the engine's lock held by a backup whose status.json says phase `partner`).
 *
 * @return array{ips: list<string>, door: bool, received: int, sending: bool}
 */
function watchmanFlowPartner(array $paths, ?string $holder): array
{
    $ips = [];
    if (isset($paths['partner_pairs'])) {
        $lines = isset($paths['ssh']) ? partnerLookLines($paths['ssh'] . '/root/authorized_keys') : [];
        foreach (partnerLookPairs((string) $paths['partner_pairs']) as $id => $p) {
            $ips = array_merge($ips, $p['ips'], partnerLookFromIps($lines[$id]['from'] ?? null));
        }
    }
    $received = isset($paths['partner_data']) ? partnerLookReceived($paths['partner_data'] . '/received') : [];
    $status = $holder === 'backup' && isset($paths['engine']) ? readJson($paths['engine'] . '/state/status.json') : null;
    return ['ips' => array_values(array_unique($ips)), 'door' => isset($paths['partner_run']) && partnerLookDoors((string) $paths['partner_run']) !== [],
            'received' => $received ? max($received) : 0, 'sending' => ($status['phase'] ?? null) === 'partner'];
}

/**
 * The used space of every awake XFS/btrfs array disk and pool (statfs — no disk is woken: the
 * sleeping ones aren't asked), the newest time of its btrfs snapshot folders (.btrfs-snap: the
 * engine's and Ms. Snapshotini's — their snapshots going frees space that was deleted long ago) and the
 * user shares with a folder on it. Null: no disks.ini.
 * @return array{disks: array<string, array{fs: string, used: int, snap: ?int, shares: list<string>}>, asleep: list<string>}|null
 */
function watchmanFlowDisks(array $paths, array $snapDirs = ['.btrfs-snap']): ?array
{
    $ini = readCfg($paths['disks_ini'], true);
    if (!$ini) {
        return null;
    }
    $mnt = rtrim($paths['mnt'] ?? '/mnt', '/');
    $sleep = [];
    foreach ($ini as $section => $v) {
        $sleep[(string) ($v['name'] ?? $section)] = diskAsleep($v);       // spun down AND rotating (lib/mounts.php)
    }
    $out = $asleep = [];
    foreach ($ini as $section => $v) {
        $name = (string) ($v['name'] ?? $section);
        $fs = preg_replace('/^luks:/', '', strtolower((string) ($v['fsType'] ?? '')));
        if (!in_array($fs, ['xfs', 'btrfs', 'reiserfs'], true) || !in_array($v['type'] ?? '', ['Data', 'Cache'], true)
            || ($v['fsStatus'] ?? 'Mounted') !== 'Mounted' || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $name)) {
            continue;
        }
        if (baseAsleep($name, $sleep)) {
            $asleep[] = $name;
            continue;
        }
        $dir = "$mnt/$name";
        $total = @disk_total_space($dir);
        $free = @disk_free_space($dir);
        if ($total === false || $free === false || $total <= 0) {
            continue;
        }
        $snap = null;
        foreach ($fs === 'btrfs' ? $snapDirs : [] as $sd) {
            clearstatcache(true, "$dir/$sd");
            $t = @filemtime("$dir/$sd");
            $snap = $t !== false ? max($snap ?? 0, (int) $t) : $snap;
        }
        $shares = [];
        foreach (@scandir($dir) ?: [] as $f) {
            if ($f[0] !== '.' && !storeroomName($f) && count($shares) < 50 && is_dir("$dir/$f")) {
                $shares[] = watchmanClean($f, 100);
            }
        }
        $out[$name] = ['fs' => $fs, 'used' => (int) round($total - $free), 'snap' => $snap, 'shares' => $shares];
    }
    ksort($out);
    return ['disks' => $out, 'asleep' => $asleep];
}

/**
 * What moves data right now — so what vanishes in one place is somewhere else: Unraid's mover (also
 * Mover Tuning's age_mover), the office's EmbyCache and gather, rsync with --remove-source-files,
 * Ms. Dustdevil emptying her storeroom (rm of a storeroom: a deletion you asked for in the office).
 * Not unbalanced's own process: it runs all the time (its web page). From /proc; null when it can't be read.
 * @return list<string>|null
 */
function watchmanFlowMovers(string $proc = '/proc'): ?array
{
    $pids = @scandir($proc);
    if (!$pids) {
        return null;
    }
    $out = [];
    foreach ($pids as $pid) {
        if (ctype_digit($pid)) {
            $what = watchmanFlowMover(explode("\0", rtrim((string) @file_get_contents("$proc/$pid/cmdline", false, null, 0, 8192), "\0")));
            if ($what !== null) {
                $out[$what] = true;
            }
        }
    }
    ksort($out);
    return array_keys($out);
}

/** Which kind of mover a process is (its program, or the script an interpreter runs), or null */
function watchmanFlowMover(array $argv): ?string
{
    $names = array_map(fn ($a) => basename((string) $a), array_slice($argv, 0, 3));
    return match (true) {
        (bool) array_intersect($names, ['mover', 'age_mover'])                                  => 'mover',
        (bool) array_intersect($names, ['embycache_run.py'])                                   => 'embycache',
        (bool) array_intersect($names, ['consolidate_master.sh'])                              => 'gather',
        $names[0] === 'rsync' && in_array('--remove-source-files', $argv, true)               => 'rsync',
        $names[0] === 'rm' && (bool) array_filter($argv, fn ($a) => str_contains((string) $a, '/') && storeroomIn((string) $a)) => 'storeroom',
        default => null,
    };
}

/**
 * ZFS `written`, `used`, `referenced`, `usedbysnapshots`, `creation` and `snapshots_changed` of every dataset of the
 * awake ZFS pools and ZFS array disks (disks.ini: a pool sleeps when any of its disks does — those are
 * never asked). Null: no ZFS here.
 * @return array{datasets: array<string, array{w:int, u:int, s:?int, r?:int, b?:int, c?:int}>, pools: list<string>, asleep: list<string>}|null
 */
function watchmanFlowZfs(array $paths): ?array
{
    if (bin('zfs') === null) {
        return null;
    }
    $disks = readCfg($paths['disks_ini'], true);
    $sleep = [];
    foreach ($disks as $section => $v) {
        $sleep[(string) ($v['name'] ?? $section)] = diskAsleep($v);       // spun down AND rotating (lib/mounts.php)
    }
    $pools = $asleep = [];
    foreach ($disks as $section => $v) {
        $name = (string) ($v['name'] ?? $section);
        if (!str_contains(strtolower((string) ($v['fsType'] ?? '')), 'zfs') || in_array($v['type'] ?? '', ['Boot', 'Flash'], true)
            || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $name)) {
            continue;
        }
        if (baseAsleep($name, $sleep)) {
            $asleep[] = $name;
        } else {
            $pools[] = $name;
        }
    }
    if (!$pools) {
        return ['datasets' => [], 'pools' => [], 'asleep' => $asleep];
    }
    $cmd = ['zfs', 'get', '-H', '-p', '-o', 'name,property,value', '-t', 'filesystem,volume', '-r',
            'written,used,referenced,usedbysnapshots,creation,snapshots_changed', ...$pools];
    [$exit, $out] = run($cmd, 60);
    if ($exit !== 0 && !str_contains($out, "\twritten\t")) {
        $cmd[9] = 'written,used,referenced,usedbysnapshots,creation';          // an older ZFS without snapshots_changed
        [, $out] = run($cmd, 60);
    }
    return ['datasets' => watchmanZfsParse($out), 'pools' => $pools, 'asleep' => $asleep];
}

/** "[::ffff:192.0.2.7]:445", "192.0.2.7:445", "[2001:db8::7%br0]:445" → [address, port], or null */
function watchmanAddrPort(string $s): ?array
{
    $at = strrpos($s, ':');
    if ($at === false || !ctype_digit(substr($s, $at + 1))) {
        return null;
    }
    $ip = watchmanIp(substr($s, 0, $at));
    $port = (int) substr($s, $at + 1);
    return $ip === null || $port < 1 || $port > 65535 ? null : [$ip, $port];
}

/**
 * ss -tinH state established '( sport = :445 or … )': per connection the server's address and port,
 * the client's, its service and what the server sent (bytes_acked — what the client got, without
 * retransmissions — else bytes_sent) and received.
 * @return list<array{local:string, lport:int, peer:string, pport:int, service:string, sent:int, rcvd:int}>
 */
function watchmanSsParse(string $text, array $ports): array
{
    $out = [];
    $cur = null;
    foreach (explode("\n", $text) as $line) {
        if (trim($line) === '') {
            continue;
        }
        if ($line[0] !== ' ' && $line[0] !== "\t") {
            $cur = null;
            if (count($out) < WATCH_FLOW_CONNS && preg_match('/^(?:[A-Z][A-Z-]*\s+)?\d+\s+\d+\s+(\S+)\s+(\S+)/', $line, $m)) {
                $l = watchmanAddrPort($m[1]);
                $p = watchmanAddrPort($m[2]);
                if ($l !== null && $p !== null && isset($ports[$l[1]])) {
                    $cur = count($out);
                    $out[] = ['local' => $l[0], 'lport' => $l[1], 'peer' => $p[0], 'pport' => $p[1], 'service' => $ports[$l[1]], 'sent' => 0, 'rcvd' => 0, '_a' => null];
                }
            }
        }
        if ($cur === null) {
            continue;
        }
        foreach (['bytes_acked' => '_a', 'bytes_sent' => 'sent', 'bytes_received' => 'rcvd'] as $field => $k) {
            if (preg_match('/\b' . $field . ':(\d+)/', $line, $m)) {
                $out[$cur][$k] = (int) $m[1];
            }
        }
    }
    foreach ($out as $i => $c) {
        if ($c['_a'] !== null) {
            $out[$i]['sent'] = $c['_a'];
        }
        unset($out[$i]['_a']);
    }
    return $out;
}

/**
 * smbstatus -b --json (Samba 4.16+), or the plain table of an older one: per session its id, user,
 * the client's address, its machine name (what Samba knows), when it started (null: not known).
 * Null when it is neither.
 * @return list<array{id:string, user:string, ip:string, machine:string, start:?int}>|null
 */
function watchmanSmbParse(string $text): ?array
{
    $clean = fn (mixed $s, int $max) => watchmanClean(is_string($s) ? $s : '', $max);
    $j = json_decode($text, true);
    if (is_array($j) && array_key_exists('sessions', $j)) {
        $out = [];
        foreach ((array) $j['sessions'] as $id => $s) {
            if (!is_array($s) || count($out) >= 1000) {
                continue;
            }
            $ip = preg_match('/^ipv[46]:(.+):\d+$/D', (string) ($s['hostname'] ?? ''), $m) ? watchmanIp($m[1]) : watchmanIp((string) ($s['remote_machine'] ?? ''));
            if ($ip === null) {
                continue;
            }
            $start = is_string($s['creation_time'] ?? null) ? strtotime($s['creation_time']) : false;
            $out[] = ['id' => $clean((string) ($s['session_id'] ?? $id), 40), 'user' => $clean($s['username'] ?? '', 64) ?: '?', 'ip' => $ip,
                      'machine' => $clean($s['remote_machine'] ?? '', 64), 'start' => $start === false ? null : $start];
        }
        return $out;
    }
    if (!preg_match('/^PID\s+Username\s+Group\s+Machine/m', $text)) {
        return null;
    }
    $out = [];
    foreach (explode("\n", $text) as $line) {
        if (count($out) < 1000 && preg_match('/^\s*(\d+)\s+(\S+)\s+(\S+)\s+(.+?)\s+\(ipv[46]:(.+):\d+\)/', $line, $m) && ($ip = watchmanIp($m[5])) !== null) {
            $out[] = ['id' => 'pid' . $m[1], 'user' => $clean($m[2], 64), 'ip' => $ip, 'machine' => $clean($m[4], 64), 'start' => null];
        }
    }
    return $out;
}

/** /proc/<pid>/net/dev: the bytes sent by every interface but lo, or null */
function watchmanNetDevTx(string $text): ?int
{
    $tx = null;
    foreach (explode("\n", $text) as $line) {
        if (!preg_match('/^\s*([^:\s]+):\s*(.*)$/', $line, $m) || $m[1] === 'lo') {
            continue;
        }
        $f = preg_split('/\s+/', trim($m[2])) ?: [];
        if (count($f) >= 9 && ctype_digit($f[8])) {
            $tx = ($tx ?? 0) + (int) $f[8];
        }
    }
    return $tx;
}

/**
 * zfs get -Hp -o name,property,value written,used,referenced,usedbysnapshots,creation,snapshots_changed → dataset =>
 * [w, u, s (null: never a snapshot, or not known), r, b, c (when it was created)]
 */
function watchmanZfsParse(string $text): array
{
    $out = [];
    foreach (explode("\n", $text) as $line) {
        $f = explode("\t", $line);
        if (count($f) !== 3 || !preg_match(WATCH_DATASET, $f[0])) {
            continue;
        }
        if (!isset($out[$f[0]]) && count($out) >= WATCH_FLOW_DATASETS) {
            continue;
        }
        $out[$f[0]] ??= ['w' => 0, 'u' => 0, 's' => null];
        $v = trim($f[2]);
        match ($f[1]) {
            'creation' => $out[$f[0]]['c'] = ctype_digit($v) ? (int) $v : 0,
            'written' => $out[$f[0]]['w'] = ctype_digit($v) ? (int) $v : 0,
            'used'    => $out[$f[0]]['u'] = ctype_digit($v) ? (int) $v : 0,
            'referenced' => $out[$f[0]]['r'] = ctype_digit($v) ? (int) $v : 0,
            'usedbysnapshots' => $out[$f[0]]['b'] = ctype_digit($v) ? (int) $v : 0,
            'snapshots_changed' => $out[$f[0]]['s'] = ctype_digit($v) ? (int) $v : null,
            default   => null,
        };
    }
    return $out;
}

/** Monday 00:00 = 0 … Sunday 23:00 = 167, in the server's time */
function watchmanHourOfWeek(int $t): int
{
    return ((int) date('N', $t) - 1) * 24 + (int) date('G', $t);
}

/** Is $h within $span hours (around the week) of one of $hours? */
function watchmanHourNear(array $hours, int $h, int $span = 1): bool
{
    foreach ($hours as $x) {
        $d = abs((int) $x - $h) % 168;
        if (min($d, 168 - $d) <= $span) {
            return true;
        }
    }
    return false;
}

/**
 * What is normal per hour now, from hourly sums (hour index => bytes; the hour going on left out):
 * the most at this time of the week (±1 h), or a quarter of the busiest hour, whichever is more.
 */
function watchmanFlowUsual(array $hours, int $now): int
{
    static $week = [];           // hour index => hour of the week (the same hours for every series)
    $cur = intdiv($now, 3600);
    $how = watchmanHourOfWeek($now);
    $same = $max = 0;
    foreach ($hours as $idx => $bytes) {
        if ((int) $idx === $cur) {
            continue;
        }
        $max = max($max, (int) $bytes);
        if (count($week) > 4096) {
            $week = [];
        }
        if ((int) $bytes > $same && watchmanHourNear([$week[(int) $idx] ??= watchmanHourOfWeek((int) $idx * 3600)], $how)) {
            $same = (int) $bytes;
        }
    }
    return max($same, intdiv($max, 4));
}

/**
 * Is it unusual? Learned (WATCH_FLOW_LEARN since first seen): this hour so far over FACTOR × its
 * normal (and over $floor). Still learning: only one round over $clear (and over FACTOR × what «I
 * know, thanks» made normal).
 * @return array{hour:int, usual:int, limit:int, learning:bool}|null
 */
function watchmanFlowJudge(array $s, int $round, int $now, int $ack, int $floor, int $clear): ?array
{
    $hour = (int) ($s['h'][intdiv($now, 3600)] ?? 0);
    if ($now - (int) ($s['first'] ?? $now) >= WATCH_FLOW_LEARN) {
        $normal = max(watchmanFlowUsual((array) ($s['h'] ?? []), $now), $ack);
        $limit = max($floor, WATCH_FLOW_FACTOR * $normal);
        return $hour > $limit ? ['hour' => $hour, 'usual' => $normal, 'limit' => $limit, 'learning' => false] : null;
    }
    $limit = max($clear, WATCH_FLOW_FACTOR * $ack);
    return $round > $limit ? ['hour' => $hour, 'usual' => $ack, 'limit' => $limit, 'learning' => true] : null;
}

/** A series (client/service, container, share), new */
function watchmanFlowSeries(int $now, array $more = []): array
{
    return $more + ['first' => $now, 'last' => 0, 'h' => [], 'o' => [], 'run' => null];
}

/**
 * Bytes of a round into its hour ('h' learned, 'o' the office's own work) and into the pull going on
 * ('run': from the round before its first, as long as every round has some).
 */
function watchmanFlowAdd(array &$s, int $bytes, int $now, ?int $prevTime, bool $office, array $by = []): void
{
    $idx = intdiv($now, 3600);
    $part = $office ? 'o' : 'h';
    $s[$part] = (array) ($s[$part] ?? []);
    $s[$part][$idx] = (int) ($s[$part][$idx] ?? 0) + $bytes;
    $s['last'] = $now;
    if ($office) {
        $s['run'] = null;           // the office's own work is no pull of anybody's
        return;
    }
    $run = is_array($s['run'] ?? null) ? $s['run'] : null;
    if ($run !== null && $prevTime !== null && (int) $run['last'] >= $prevTime) {
        $run['bytes'] = (int) $run['bytes'] + $bytes;
        $run['last'] = $now;
    } else {
        $run = ['from' => $prevTime ?? $now, 'bytes' => $bytes, 'last' => $now];
    }
    if ($by) {
        $run['by'] = watchmanFlowByMerge((array) ($run['by'] ?? []), $by);      // a share's: where the pull's bytes were written
    }
    $s['run'] = $run;
}

/**
 * Where a share's bytes were written, added up over a pull (source key => [bytes, new]): `own` (the share's dataset),
 * `ct:<container>` (its writable layer and its -init), `ly:<path>` (a Docker layer of no container — an image's, named
 * when an entry asks), `ds:<path>` (any other dataset below the share); at most WATCH_FLOW_BY, the smallest into `*`.
 */
function watchmanFlowByMerge(array $a, array $b): array
{
    foreach ($b as $k => $v) {
        $x = is_array($a[$k] ?? null) ? $a[$k] : [0, false];
        $a[(string) $k] = [(int) $x[0] + (int) ($v[0] ?? 0), !empty($x[1]) || !empty($v[1])];
    }
    if (count($a) > WATCH_FLOW_BY) {
        $rest = (int) ($a['*'][0] ?? 0);
        unset($a['*']);
        uasort($a, fn ($x, $y) => (int) $y[0] <=> (int) $x[0]);
        foreach (array_slice($a, WATCH_FLOW_BY - 1, null, true) as $k => $x) {
            $rest += (int) $x[0];
            unset($a[$k]);
        }
        $a['*'] = [$rest, false];
    }
    return $a;
}

/**
 * A dataset below a share as a source key (watchmanFlowByMerge()). $rel: its path below the share ('' = the share's own);
 * $dockerDs: the containers' writable layers (dataset => container, from docker inspect).
 */
function watchmanFlowByKey(string $share, string $rel, array $dockerDs): string
{
    if ($rel === '') {
        return 'own';
    }
    if (preg_match(WATCH_DOCKER_LAYER, basename($rel), $m)) {
        $rw = $share . '/' . (isset($m[2]) ? substr($rel, 0, -5) : $rel);       // a container's -init belongs to it
        return isset($dockerDs[$rw]) ? 'ct:' . $dockerDs[$rw] : "ly:$rel";
    }
    return "ds:$rel";
}

/**
 * libvirt's image (domain.cfg IMAGE_FILE, or its folder): the share it lies in and its folder there ('' = the share's top),
 * or null — so the dataset that holds it is named libvirt's.
 * @return array{share: string, dir: string}|null
 */
function watchmanFlowLibvirt(array $cfg): ?array
{
    $path = rtrim(trim((string) ($cfg['IMAGE_FILE'] ?? ''), '"'), '/');
    if (str_ends_with($path, '.img')) {
        $path = dirname($path);
    }
    if (!preg_match('#^/mnt/[^/]+/([^/]+)(?:/(.+))?$#D', $path, $m) || preg_match('#(?:^|/)\.\.?(?:/|$)|[\x00-\x1F]#', $path)) {
        return null;
    }
    return ['share' => $m[1], 'dir' => (string) ($m[2] ?? '')];
}

/**
 * Docker on ZFS: the images whose layers these datasets are (layer id => image name, '' = none known). Asked of Docker
 * only for ids not known yet (watchmanFlowLayersAsk(): docker image inspect, its layer database) — once in
 * WATCH_FLOW_LAYERS_AGAIN for ids no image named — and remembered for the agent's run. $ask, $cache: the tests'.
 */
function watchmanFlowLayers(array $ids, ?callable $ask = null, ?int $now = null, ?array &$cache = null): array
{
    static $mine = ['known' => [], 'asked' => 0];
    if ($cache === null) {
        $c = &$mine;
    } else {
        $c = &$cache;
        $c += ['known' => [], 'asked' => 0];
    }
    $now ??= time();
    $want = false;
    foreach ($ids as $id) {
        $id = (string) $id;
        if (preg_match('/^[0-9a-f]{64}$/D', $id) && !isset($c['known'][$id]) && $now - (int) $c['asked'] >= WATCH_FLOW_LAYERS_AGAIN) {
            $want = true;
        }
    }
    if ($want) {
        $c['asked'] = $now;
        $found = ($ask ?? 'watchmanFlowLayersAsk')();
        if (is_array($found)) {
            $known = [];
            foreach ($found as $id => $name) {
                if (count($known) < WATCH_FLOW_LAYERS && preg_match('/^[0-9a-f]{64}$/D', (string) $id)) {
                    $known[(string) $id] = (string) $name;
                }
            }
            $c['known'] = $known;           // what Docker has now: removed images go
        }
    }
    $out = [];
    foreach ($ids as $id) {
        $out[(string) $id] = (string) ($c['known'][(string) $id] ?? '');
    }
    return $out;
}

/** docker image inspect: id, tags, its layers' diff ids, its top layer's storage data */
const WATCH_INSPECT_IMAGE = "{{.Id}}\t{{json .RepoTags}}\t{{json .RootFS.Layers}}\t{{json .GraphDriver.Data}}";

/** Every image's layers as Docker on ZFS keeps them (layer id => image), [] on another storage driver, null: Docker didn't answer */
function watchmanFlowLayersAsk(): ?array
{
    [$exit, $info] = run(['docker', 'info', '--format', '{{.Driver}}' . "\t" . '{{.DockerRootDir}}'], 20);
    $f = explode("\t", trim($info));
    if ($exit !== 0 || count($f) !== 2) {
        return null;
    }
    if ($f[0] !== 'zfs' || !preg_match('#^/[^\x00-\x1F]{1,200}$#D', $f[1]) || str_contains($f[1], '..')) {
        return [];
    }
    [$exit, $ids] = run(['docker', 'image', 'ls', '-q', '--no-trunc'], 20);
    if ($exit !== 0) {
        return null;
    }
    $ids = array_slice(array_values(array_unique(array_filter(explode("\n", trim($ids)), fn ($x) => (bool) preg_match('/^sha256:[0-9a-f]{64}$/D', $x)))), 0, 500);
    if (!$ids) {
        return [];
    }
    [$exit, $text] = run(array_merge(['docker', 'image', 'inspect', '--format', WATCH_INSPECT_IMAGE], $ids), 60);
    return $exit !== 0 && trim($text) === '' ? null : watchmanFlowLayersParse($text, $f[1]);
}

/**
 * docker image inspect (WATCH_INSPECT_IMAGE) and Docker's layer database under its root: each layer's chain id
 * (sha256 of «parent's chain id + ' ' + its diff id») names a folder whose cache-id is the layer's dataset. A layer
 * of several images goes to the first tagged one (by name); an image without a tag is named by its short id.
 */
function watchmanFlowLayersParse(string $text, string $root): array
{
    $imgs = [];
    foreach (explode("\n", trim($text)) as $line) {
        $f = explode("\t", $line);
        if (count($f) < 4 || !preg_match('/^sha256:([0-9a-f]{12})[0-9a-f]{52}$/D', $f[0], $m)) {
            continue;
        }
        $tags = array_values(array_filter((array) (json_decode($f[1], true) ?: []), fn ($t) => is_string($t) && $t !== '' && !str_contains($t, '<none>')));
        sort($tags);
        $name = $tags ? watchmanClean((string) preg_replace('/:latest$/D', '', $tags[0]), 120) : $m[1];
        $imgs[] = ['tagged' => $tags ? 0 : 1, 'name' => $name, 'layers' => json_decode($f[2], true), 'gd' => json_decode($f[3], true)];
    }
    usort($imgs, fn ($a, $b) => [$a['tagged'], $a['name']] <=> [$b['tagged'], $b['name']]);
    $out = [];
    foreach ($imgs as $img) {
        $chain = '';
        foreach (is_array($img['layers']) ? $img['layers'] : [] as $diff) {
            if (!is_string($diff) || !preg_match('/^sha256:[0-9a-f]{64}$/D', $diff)) {
                break;
            }
            $chain = $chain === '' ? $diff : 'sha256:' . hash('sha256', "$chain $diff");
            $id = trim((string) @file_get_contents("$root/image/zfs/layerdb/sha256/" . substr($chain, 7) . '/cache-id', false, null, 0, 128));
            if (preg_match('/^[0-9a-f]{64}$/D', $id)) {
                $out[$id] ??= $img['name'];
            }
        }
        $top = is_array($img['gd']) && is_string($img['gd']['Dataset'] ?? null) ? basename($img['gd']['Dataset']) : '';
        if (preg_match('/^[0-9a-f]{64}$/D', $top)) {
            $out[$top] ??= $img['name'];         // its top layer, also without the layer database
        }
    }
    return $out;
}

/**
 * Who wrote a share's pull (its run, watchmanFlowByMerge()), for its entry: the WATCH_FLOW_SOURCES biggest — containers
 * by name, Docker's image layers per image (asked only now), libvirt's dataset, other datasets, the share's own — and
 * `rest`. A share of one dataset: `none` (no single source can be seen). Null: nothing known of the pull's sources.
 * $ctx: single (one dataset), datasets (those there now, as keys: what went since is said), layers (callable), libvirt.
 * @return list<array{t: string, b: int, name?: string, n?: int, new?: true, gone?: true, dir?: string}>|null
 */
function watchmanFlowSources(array $run, string $share, array $ctx): ?array
{
    $total = (int) ($run['bytes'] ?? 0);
    if (!empty($ctx['single'])) {
        return [['t' => 'none', 'b' => $total]];
    }
    $by = (array) ($run['by'] ?? []);
    unset($by['*']);
    if (!$by) {
        return null;
    }
    $have = (array) ($ctx['datasets'] ?? []);
    $hex = fn (string $rel) => substr(basename($rel), 0, 64);
    $ly = [];
    foreach (array_keys($by) as $k) {
        if (str_starts_with((string) $k, 'ly:')) {
            $ly[] = $hex(substr((string) $k, 3));
        }
    }
    $images = $ly && is_callable($ctx['layers'] ?? null) ? (array) ($ctx['layers'])(array_values(array_unique($ly))) : [];
    $lv = is_array($ctx['libvirt'] ?? null) && $ctx['libvirt']['share'] === (explode('/', $share, 3)[1] ?? '') ? (string) $ctx['libvirt']['dir'] : null;
    $holds = fn (string $rel) => $lv !== null && $rel !== '' && ($lv === $rel || str_starts_with($lv, "$rel/"));
    $groups = [];
    foreach ($by as $k => $v) {
        [$t, $name] = explode(':', (string) $k, 2) + [1 => ''];
        $bytes = (int) ($v[0] ?? 0);
        $new = !empty($v[1]);
        if ($bytes <= 0) {
            continue;
        }
        switch ($t) {
            case 'ly':
                $img = (string) ($images[$hex($name)] ?? '');
                $g = 'img:' . ($new ? 'new' : 'old') . ":$img";
                $x = $groups[$g] ?? ['t' => 'img', 'name' => $img, 'n' => 0, 'b' => 0, 'new' => $new, 'gone' => true];
                $x['n']++;
                $x['b'] += $bytes;
                $x['gone'] = $x['gone'] && !isset($have["$share/$name"]);
                $groups[$g] = $x;
                break;
            case 'ct':
                $groups[$k] = ['t' => 'ct', 'name' => $name, 'b' => $bytes, 'new' => $new, 'gone' => false];
                break;
            case 'ds':
                $groups[$k] = ['t' => $holds($name) ? 'libvirt' : 'ds', 'name' => "$share/$name", 'b' => $bytes, 'new' => $new, 'gone' => !isset($have["$share/$name"])];
                break;
            case 'own':
                $inOwn = $lv !== null && !array_filter(array_keys($have), fn ($d) => str_starts_with((string) $d, "$share/") && $holds(substr((string) $d, strlen($share) + 1)));
                $groups[$k] = ['t' => 'own', 'name' => $share, 'b' => $bytes, 'new' => false, 'gone' => false] + ($inOwn ? ['dir' => $lv] : []);
                break;
        }
    }
    usort($groups, fn ($a, $b) => [$b['b'], $a['t'], $a['name']] <=> [$a['b'], $b['t'], $b['name']]);
    $out = [];
    $sum = 0;
    foreach (array_slice($groups, 0, WATCH_FLOW_SOURCES) as $x) {
        $sum += $x['b'];
        if ($x['t'] !== 'img') {
            unset($x['n']);
        }
        $out[] = array_filter($x, fn ($v) => $v !== false);
    }
    if ($total - $sum > 0) {
        $out[] = ['t' => 'rest', 'b' => $total - $sum];
    }
    return $out;
}

/** A source of written bytes (watchmanFlowSources()) in words — the page has the same (desk.js srcWhat()) */
function watchmanFlowSourceWhat(array $x, string $lang): string
{
    $t = fn (string $k, array $p = []) => officeNotifyText('watchman', $k, $p, $lang);
    $name = (string) ($x['name'] ?? '');
    return match ((string) ($x['t'] ?? '')) {
        'ct'      => $t('src.container', ['name' => $name]),
        'img'     => $t(!empty($x['new']) ? 'src.layers_new' : 'src.layers', ['n' => (int) ($x['n'] ?? 1),
                         'image' => $name !== '' ? $t('src.image', ['name' => $name]) : $t('src.image_unknown')]),
        'libvirt' => $t('src.libvirt', ['name' => $name]),
        'ds'      => $t('src.dataset', ['name' => $name]),
        'own'     => isset($x['dir']) ? $t('src.own_libvirt', ['name' => $name, 'folder' => trim((explode('/', $name, 2)[1] ?? '') . '/' . $x['dir'], '/')])
                                      : $t('src.own', ['name' => $name]),
        default   => $t('src.rest'),
    };
}

/** One source with its bytes: «Container EmbyServer (its writable layer) — new: 3.1 GB» */
function watchmanFlowSourceLine(array $x, string $lang): string
{
    $key = !empty($x['new']) && ($x['t'] ?? '') !== 'img' ? (!empty($x['gone']) ? 'src.line_new_gone' : 'src.line_new') : (!empty($x['gone']) ? 'src.line_gone' : 'src.line');
    return officeNotifyText('watchman', $key, ['what' => watchmanFlowSourceWhat($x, $lang), 'size' => watchmanSize((int) ($x['b'] ?? 0), $lang)], $lang);
}

/** The biggest source behind its entry's line (' Most of it: …'), or that none can be seen; '' for the page (it words it itself) */
function watchmanFlowFrom(array $p, ?string $lang): string
{
    $src = is_array($p['src'] ?? null) ? $p['src'] : [];
    $top = $src[0] ?? null;
    if ($lang === null || !is_array($top) || ($top['t'] ?? '') === 'rest') {
        return '';
    }
    if ($top['t'] === 'none') {
        return ' ' . officeNotifyText('watchman', 'src.none', ['share' => (string) ($p['share'] ?? '')], $lang);
    }
    return ' ' . officeNotifyText('watchman', 'src.most', ['what' => watchmanFlowSourceWhat($top, $lang), 'size' => watchmanSize((int) ($top['b'] ?? 0), $lang)], $lang);
}

/**
 * An unusual pull (or one going on) in the book: one open entry per key. A round right after the
 * entry's last one brings it up to date (the same pull, still going); a later unusual one starts a
 * new episode in it (count + 1). $who: what it is about (ip/service, name, share) — or a closure giving it, called only
 * when the entry is written.
 */
function watchmanFlowNote(array &$book, string $kind, string $key, int $now, array $run, int $hour, ?array $judged, array|Closure $who): ?string
{
    if (!$who instanceof Closure) {         // a closure is asked only when the entry is written (into a share: who wrote it — maybe Docker)
        $known = $who;
        $who = fn (): array => $known;
    }
    $minutes = max(1, (int) ceil(($now - (int) $run['from']) / 60));
    foreach ($book as $i => $e) {
        if (($e['key'] ?? '') !== $key || !watchmanOpen($e)) {
            continue;
        }
        $p = (array) $e['p'];
        if ($now - (int) $e['last'] <= WATCH_FLOW_GOING) {
            $p = $who() + $p;
            $p['bytes'] = (int) $run['bytes'];
            $p['minutes'] = $minutes;
            $p['peak'] = max((int) ($p['peak'] ?? 0), $hour, (int) $run['bytes']);
            $book[$i]['p'] = $p;
            $book[$i]['last'] = $now;
            return null;
        }
        if ($judged === null) {
            return null;
        }
        $book[$i]['count'] = (int) $e['count'] + 1;
        $book[$i]['p'] = $who() + ['bytes' => (int) $run['bytes'], 'minutes' => $minutes, 'usual' => $judged['usual'], 'limit' => $judged['limit'],
                                 'learning' => $judged['learning'], 'peak' => max((int) ($p['peak'] ?? 0), $judged['hour'], (int) $run['bytes'])];
        $book[$i]['last'] = $now;
        return null;
    }
    if ($judged === null) {
        return null;
    }
    $book[] = watchmanEntry($kind, $key, $now, $who() + ['bytes' => (int) $run['bytes'], 'minutes' => $minutes, 'usual' => $judged['usual'],
        'limit' => $judged['limit'], 'learning' => $judged['learning'], 'peak' => max($judged['hour'], (int) $run['bytes'])]);
    return $kind;
}

/**
 * The data flow of a round: the look against the last round's counters and against what is normal.
 * $bf: the baseline's part (since when, SMB's users and machines and the hours they start sessions,
 * what «I know, thanks» made normal — null: he starts watching, the first look is counters only);
 * $flow: flow.json (hourly sums); $prev: the last round's counters, or null.
 *
 * @return array{0: list<string>, 1: array, 2: array}  kinds added, flow.json, the counters for the next round
 */
function watchmanFlowCompare(?array &$bf, array $flow, ?array $prev, array $look, array &$book, int $now): array
{
    $start = !is_array($bf);
    if ($start) {
        $bf = ['since' => $now, 'smb_users' => [], 'smb_clients' => [], 'ack' => []];
        $flow = [];
        $prev = null;
    }
    $bf += ['since' => $now, 'smb_users' => [], 'smb_clients' => [], 'ack' => []];
    $flow += ['since' => (int) $bf['since'], 'clients' => [], 'containers' => [], 'shares' => [], 'gone' => [], 'totals' => ['sent' => [], 'written' => []]];
    $office = in_array($look['holder'] ?? null, WATCH_FLOW_OFFICE, true);
    $restore = ($look['holder'] ?? null) === 'restore';
    $officeShares = array_flip(array_map('strval', (array) ($look['office_shares'] ?? [])));
    $prevTime = $prev === null ? null : (int) $prev['time'];
    $ack = fn (string $key): int => (int) ($bf['ack'][$key]['bytes'] ?? 0);
    $added = [];
    $next = ['time' => $now, 'conns' => null, 'cts' => null, 'ds' => null, 'smb' => null, 'ref' => null, 'disks' => null, 'moving' => null,
             'door' => !empty($look['partner']['door'])];
    // the partner door at work — receiving now, at the last round, or a receive finished since: what it wrote into the
    // partners' place and the pairs' SSH bytes are the office's own; so are they while this office sends (phase partner)
    $pl = is_array($look['partner'] ?? null) ? $look['partner'] : [];
    $door = !empty($pl['door']) || !empty($prev['door']) || ($prevTime !== null && (int) ($pl['received'] ?? 0) >= $prevTime - 60);
    $pairIps = $door || !empty($pl['sending']) ? array_flip(array_map('strval', (array) ($pl['ips'] ?? []))) : [];
    $active = null;                 // who moved data over SMB, NFS or SSH this round (for what vanished), null: can't be told

    // SMB: users, machines, the hours they start sessions
    $names = [];
    $smb = $look['smb'] ?? null;
    if (is_array($smb) && !empty($smb['on'])) {
        $next['smb'] = [];
        $seenIds = array_flip(array_map('strval', (array) ($prev['smb'] ?? [])));
        foreach ((array) $smb['sessions'] as $s) {
            $next['smb'][] = $s['id'];
            $ip = $s['ip'];
            $user = $s['user'];
            $machine = $s['machine'] !== '' && $s['machine'] !== $ip ? $s['machine'] : '';
            if ($machine !== '') {
                $names[$ip] = $machine;
            }
            if (!isset($bf['smb_users'][$user])) {
                if ($start) {
                    $bf['smb_users'][$user] = $now;
                } else {
                    $added[] = watchmanSet($book, 'smb_user', "smb_user:$user", $now, ['user' => $user, 'ip' => $ip, 'machine' => $machine]);
                }
            }
            $how = watchmanHourOfWeek($s['start'] ?? $now);
            $c = $bf['smb_clients'][$ip] ?? null;
            if (!is_array($c)) {
                if ($start) {
                    $bf['smb_clients'][$ip] = ['first' => $now, 'last' => $now, 'hours' => [$how], 'name' => $machine];
                } else {
                    $added[] = watchmanSet($book, 'smb_client', "smb_client:$ip", $now, ['ip' => $ip, 'users' => [$user], 'machine' => $machine]);
                }
                continue;
            }
            $bf['smb_clients'][$ip]['last'] = $now;
            if ($machine !== '') {
                $bf['smb_clients'][$ip]['name'] = $machine;
            }
            // a session that started since the last round
            if ($prev === null || !is_array($prev['smb'] ?? null) || isset($seenIds[$s['id']]) || ($s['start'] !== null && $s['start'] <= $prevTime - 60)) {
                continue;
            }
            $hours = array_map('intval', (array) ($c['hours'] ?? []));
            if ($now - (int) $c['first'] < WATCH_FLOW_LEARN) {
                if (!in_array($how, $hours, true) && count($hours) < 168) {
                    $hours[] = $how;
                    sort($hours);
                    $bf['smb_clients'][$ip]['hours'] = $hours;
                }
            } elseif (!watchmanHourNear($hours, $how)) {
                $added[] = watchmanBump($book, 'smb_hour', "smb_hour:$ip", (int) ($s['start'] ?? $now), 1,
                    ['ip' => $ip, 'users' => [$user], 'hours' => [$how], 'machine' => $machine]);
            }
        }
    }

    // who pulls how much: per client and service
    $conns = $look['conns'] ?? null;
    if (is_array($conns)) {
        $next['conns'] = [];
        $was = $prev['conns'] ?? null;
        $sum = [];
        foreach ($conns as $c) {
            $peer = (string) $c['peer'];
            if (str_starts_with($peer, '127.') || $peer === '::1' || count($next['conns']) >= WATCH_FLOW_CONNS) {
                continue;               // the server talking to itself
            }
            $k = "$c[local]:$c[lport]>$peer:$c[pport]";
            $next['conns'][$k] = (int) $c['sent'];
            if (!is_array($was)) {
                continue;               // the first look: counters only
            }
            $before = $was[$k] ?? null;
            $d = $before !== null && $c['sent'] >= $before ? $c['sent'] - (int) $before : (int) $c['sent'];   // new since (or a new one on the same ports)
            if ($d > 0) {
                $sum["$peer|$c[service]"] = ($sum["$peer|$c[service]"] ?? 0) + $d;
            }
        }
        $active = is_array($was) ? [] : null;
        foreach ($sum as $key => $d) {
            [$ip, $svc] = explode('|', (string) $key, 2);
            if ($active !== null && $svc !== 'web' && count($active) < WATCH_LIST_MAX) {
                $active[] = "$ip (" . WATCH_FLOW_SERVICES[$svc] . ')';
            }
            $flow['totals']['sent'][$svc] = (int) ($flow['totals']['sent'][$svc] ?? 0) + $d;
            $s = is_array($flow['clients'][$key] ?? null) ? $flow['clients'][$key] : watchmanFlowSeries($now);
            $mine = $svc === 'ssh' && isset($pairIps[$ip]);         // a partner office through its door
            watchmanFlowAdd($s, $d, $now, $prevTime, $mine);
            if (isset($names[$ip])) {
                $s['name'] = $names[$ip];
            }
            $flow['clients'][$key] = $s;
            if ($mine) {
                continue;
            }
            $j = watchmanFlowJudge($s, $d, $now, $ack("flow_client:$key"), WATCH_FLOW_MIN, WATCH_FLOW_NEW);
            $added[] = watchmanFlowNote($book, 'flow_client', "flow_client:$key", $now, $s['run'], (int) ($s['h'][intdiv($now, 3600)] ?? 0), $j,
                ['ip' => $ip, 'service' => $svc, 'machine' => (string) ($s['name'] ?? '')]);
        }
    }

    // containers: what each network namespace sent
    $cts = $look['containers'] ?? null;
    $host = [];
    if (is_array($cts)) {
        $next['cts'] = [];
        $was = $prev['cts'] ?? null;
        $byNs = [];
        foreach ($cts as $name => $c) {
            if (!empty($c['host'])) {
                $host[] = (string) $name;
            } else {
                $byNs[$c['ns']][] = (string) $name;
            }
        }
        foreach ($byNs as $names2) {
            sort($names2);
            $name = $names2[0];         // containers sharing one namespace: counted once, under the first name
            $c = $cts[$name];
            $next['cts'][$name] = [(int) $c['pid'], (int) $c['tx']];
            $s = is_array($flow['containers'][$name] ?? null) ? $flow['containers'][$name] : watchmanFlowSeries($now);
            $s['image'] = mb_substr((string) $c['image'], 0, 120);
            $s['with'] = array_slice($names2, 1, WATCH_LIST_MAX);
            $s['seen'] = $now;
            $media = (bool) preg_match(WATCH_FLOW_MEDIA, $s['image'] . ' ' . $name);
            $kopia = ($look['kopia'] ?? null) !== null ? $name === $look['kopia'] : (bool) preg_match('/kopia/i', $s['image']);
            $s['media'] = $media;
            $s['kopia'] = $kopia;
            if (is_array($was)) {
                $before = $was[$name] ?? null;
                $d = is_array($before) && (int) $before[0] === (int) $c['pid'] && $c['tx'] >= (int) $before[1] ? $c['tx'] - (int) $before[1] : (int) $c['tx'];
                if ($d > 0) {
                    $mine = $office && $kopia;      // Kopia uploading the office's backup
                    watchmanFlowAdd($s, $d, $now, $prevTime, $mine);
                    if (!$mine && !$media) {
                        $j = watchmanFlowJudge($s, $d, $now, $ack("flow_container:$name"), WATCH_FLOW_MIN, WATCH_FLOW_NEW);
                        $added[] = watchmanFlowNote($book, 'flow_container', "flow_container:$name", $now, $s['run'], (int) ($s['h'][intdiv($now, 3600)] ?? 0), $j,
                            ['name' => $name, 'image' => $s['image']]);
                    }
                }
            }
            $flow['containers'][$name] = $s;
        }
    }

    // shares: what was written into the datasets of each ZFS share
    $z = $look['zfs'] ?? null;
    if (is_array($z)) {
        $next['ds'] = [];
        $was = $prev['ds'] ?? null;
        $asleep = array_flip((array) $z['asleep']);
        if (is_array($was)) {
            foreach ($was as $ds => $v) {           // a sleeping pool keeps its counters until it wakes
                if (isset($asleep[strtok((string) $ds, '/')])) {
                    $next['ds'][$ds] = $v;
                }
            }
        }
        $per = [];
        $pools = [];                    // the pools counted last round: a dataset new there was made since
        foreach (is_array($was) ? array_keys($was) : [] as $ds) {
            $pools[strtok((string) $ds, '/')] = true;
        }
        $dockerDs = is_array($look['docker_ds'] ?? null) ? $look['docker_ds'] : [];
        foreach ((array) $z['datasets'] as $ds => $v) {
            $parts = explode('/', (string) $ds, 3);
            if (count($parts) < 2) {
                continue;               // the pool's own top: no share
            }
            $share = "$parts[0]/$parts[1]";
            $next['ds'][$ds] = [(int) $v['w'], $v['s']];
            $a = $per[$share] ?? ['d' => 0, 'w' => 0, 'u' => 0, 's' => null, 'snap' => false, 'n' => 0, 'by' => []];
            $a['w'] += (int) $v['w'];
            $a['n']++;
            if (count($parts) === 2) {
                $a['u'] = (int) $v['u'];
                $a['s'] = $v['s'];
            }
            $a['snap'] = $a['snap'] || $v['s'] !== null;
            $d = 0;
            $new = false;
            if (is_array($was) && is_array($was[$ds] ?? null)) {
                [$pw, $ps] = $was[$ds];
                if ($ps === $v['s']) {
                    $d = max(0, (int) $v['w'] - (int) $pw);
                } elseif ((int) $v['w'] < (int) $pw) {
                    $d = (int) $v['w'];             // a new snapshot: what was written since it
                }                                    // snapshots went and it grew: can't be told — this round left out
            } elseif (is_array($was) && isset($pools[$parts[0]]) && $prevTime !== null && (int) ($v['c'] ?? 0) >= $prevTime
                      && !(str_contains((string) $ds, '/') && storeroomIn((string) $ds))) {
                $d = (int) $v['w'];                 // made since the last round (a new Docker layer, a container): all it holds was written now
                $new = true;                         // (one renamed keeps its old creation — no writing)
            }
            if ($d > 0) {
                $a['d'] += $d;
                $k = watchmanFlowByKey($share, (string) ($parts[2] ?? ''), $dockerDs);
                $a['by'][$k] = [(int) ($a['by'][$k][0] ?? 0) + $d, !empty($a['by'][$k][1]) || $new];
            }
            $per[$share] = $a;
        }
        // who wrote it, for an entry (watchmanFlowSources()): the datasets there now, Docker's image layers asked only then
        $srcCtx = ['datasets' => array_fill_keys(array_map('strval', array_keys((array) $z['datasets'])), true),
                   'layers' => is_callable($look['layers'] ?? null) ? $look['layers'] : null, 'libvirt' => $look['libvirt'] ?? null];
        foreach ($per as $share => $a) {
            if (!isset($flow['shares'][$share]) && count($flow['shares']) >= WATCH_FLOW_SHARES) {
                continue;
            }
            $s = is_array($flow['shares'][$share] ?? null) ? $flow['shares'][$share] : watchmanFlowSeries($now);
            $s['w'] = $a['w'];
            $s['u'] = $a['u'];
            $s['s'] = $a['s'];
            $s['snap'] = $a['snap'];
            $s['seen'] = $now;
            if ($a['d'] > 0) {
                $flow['totals']['written'][$share] = (int) ($flow['totals']['written'][$share] ?? 0) + $a['d'];
                $mine = $office && ($restore || isset($officeShares[explode('/', (string) $share, 2)[1]]));      // the engine's packages and dumps, a restore
                $mine = $mine || ($door && explode('/', (string) $share, 2)[1] === PARTNER_PARENT);           // a partner's copies, received by the door
                watchmanFlowAdd($s, $a['d'], $now, $prevTime, $mine, $a['by']);
                if (!$mine) {
                    $clear = max(WATCH_FLOW_WRITE, (int) ($a['u'] * WATCH_FLOW_PART));
                    $j = watchmanFlowJudge($s, $a['d'], $now, $ack("flow_written:$share"), WATCH_FLOW_MIN, $clear);
                    $run = (array) $s['run'];
                    $added[] = watchmanFlowNote($book, 'flow_written', "flow_written:$share", $now, $run, (int) ($s['h'][intdiv($now, 3600)] ?? 0), $j,
                        fn (): array => ['share' => (string) $share, 'pct' => $a['u'] > 0 ? (int) min(999, round(100 * (int) $run['bytes'] / $a['u'])) : 0,
                                         'src' => watchmanFlowSources($run, (string) $share, $srcCtx + ['single' => $a['n'] === 1])]);
                }
            }
            $flow['shares'][$share] = $s;
        }
    }

    // gone: what vanished from shares — ZFS per user share, XFS/btrfs per disk
    $movers = is_array($look['moving'] ?? null) ? array_values(array_map('strval', $look['moving'])) : null;
    $next['moving'] = $movers;
    $moving = array_values(array_unique(array_merge($movers ?? [], array_map('strval', (array) ($prev['moving'] ?? [])))));
    $expected = $office || $moving;         // the office at work, or something moving data: what vanishes went elsewhere
    $users = [];
    foreach ((array) ($smb['sessions'] ?? []) as $x) {
        $users[(string) $x['user']] = true;
    }
    $whoNow = ['clients' => $active ?? [], 'users' => array_slice(array_keys($users), 0, WATCH_LIST_MAX),
               'from' => $active === null ? null : ($active ? 'clients' : 'server')];
    $gone = function (string $key, int $loss, int $size, array $who) use (&$flow, &$added, &$book, $now, $prevTime, $expected, $whoNow, $ack, $door): void {
        if (!isset($flow['gone'][$key]) && count($flow['gone']) >= WATCH_FLOW_SHARES) {
            return;
        }
        $expected = $expected || ($door && $key === 'share:' . PARTNER_PARENT);      // a receive brings the sender's deletions along
        $s = is_array($flow['gone'][$key] ?? null) ? $flow['gone'][$key] : watchmanFlowSeries($now);
        $s = $who + $s;
        $s['size'] = $size;
        $s['seen'] = $now;
        if ($loss > 0) {
            watchmanFlowAdd($s, $loss, $now, $prevTime, $expected);
            if (!$expected) {
                $clear = max(WATCH_FLOW_WRITE, min(WATCH_GONE_NEW, (int) ($size * WATCH_GONE_PART)));
                $j = watchmanFlowJudge($s, $loss, $now, $ack("flow_gone:$key"), WATCH_FLOW_MIN, $clear);
                $run = (array) $s['run'];
                $pct = $size > 0 ? (int) min(999, round(100 * (int) $run['bytes'] / $size)) : 0;
                $added[] = watchmanFlowNote($book, 'flow_gone', "flow_gone:$key", $now, $run, (int) ($s['h'][intdiv($now, 3600)] ?? 0), $j,
                    $who + ['pct' => $pct, 'part' => $j !== null && $j['learning'] && $loss >= (int) ($size * WATCH_GONE_PART)] + $whoNow);
            }
        }
        $flow['gone'][$key] = $s;
    };
    $flow['gone'] = (array) ($flow['gone'] ?? []);
    if (is_array($z)) {
        // ZFS: referenced of each user share's datasets on the awake pools (a pool asleep keeps its counters). Net per share:
        // a dataset renamed (also into Ms. Dustdevil's storeroom, inside the share or as the share itself) is gone under its
        // old name and new under the new one; the storeroom emptied is no loss; a pool not counted before adds nothing yet
        $next['ref'] = [];
        $was = $prev['ref'] ?? null;
        $sleeping = array_flip((array) $z['asleep']);
        $per = $off = $known = [];
        foreach (is_array($was) ? $was : [] as $ds => $v) {
            $pool = (string) strtok((string) $ds, '/');
            $known[$pool] = true;
            if (isset($sleeping[$pool])) {
                $next['ref'][$ds] = $v;
                if (($sh = watchmanGoneShare((string) $ds)) !== null) {
                    $off[$sh][$pool] = true;
                }
            }
        }
        $blank = ['prev' => 0, 'cur' => 0, 'b0' => 0, 'b1' => 0];
        foreach ((array) $z['datasets'] as $ds => $v) {
            if (!isset($v['r'])) {
                continue;
            }
            $ds = (string) $ds;
            $next['ref'][$ds] = [(int) $v['r'], (int) ($v['b'] ?? 0)];
            if (!is_array($was) || ($sh = watchmanGoneShare($ds)) === null) {
                continue;
            }
            $a = $per[$sh] ?? $blank;
            if (is_array($was[$ds] ?? null)) {
                $a['prev'] += (int) $was[$ds][0];
                $a['cur'] += (int) $v['r'];
                $a['b0'] += (int) $was[$ds][1];
                $a['b1'] += (int) ($v['b'] ?? 0);
            } elseif (isset($known[strtok($ds, '/')])) {
                $a['cur'] += (int) $v['r'];             // new here: created, or a dataset's new name
            }
            $per[$sh] = $a;
        }
        foreach (is_array($was) ? $was : [] as $ds => $v) {
            $ds = (string) $ds;
            if (isset($next['ref'][$ds]) || (str_contains($ds, '/') && storeroomIn($ds)) || ($sh = watchmanGoneShare($ds)) === null) {
                continue;                               // still there, or the storeroom emptied
            }
            $per[$sh] ??= $blank;
            $per[$sh]['prev'] += (int) $v[0];           // destroyed, or renamed (its new name counts above)
        }
        foreach ($per as $sh => $a) {
            $loss = max(0, $a['prev'] - $a['cur']);
            $gone("share:$sh", $loss, $a['prev'] ?: $a['cur'], ['share' => (string) $sh, 'disk' => null, 'shares' => [],
                'kept' => min($loss, max(0, $a['b1'] - $a['b0'])), 'asleep' => array_keys($off[$sh] ?? [])]);
        }
    }
    $dk = $look['disks'] ?? null;
    if (is_array($dk)) {
        // XFS/btrfs: the used space of each awake disk and pool (a sleeping one keeps its counters)
        $next['disks'] = [];
        $was = $prev['disks'] ?? null;
        foreach (array_flip((array) $dk['asleep']) as $name => $_) {
            if (is_array($was[$name] ?? null)) {
                $next['disks'][$name] = $was[$name];
            }
        }
        foreach ((array) $dk['disks'] as $name => $d) {
            $name = (string) $name;
            $next['disks'][$name] = [(int) $d['used'], $d['snap']];
            $p = $was[$name] ?? null;
            if (!is_array($p)) {
                continue;
            }
            $loss = max(0, (int) $p[0] - (int) $d['used']);
            if ($d['snap'] !== $p[1] || ($d['snap'] !== null && $now - (int) $d['snap'] < WATCH_GONE_SNAPS)) {
                $loss = 0;              // its btrfs snapshots changed lately: what they freed was deleted long ago — this round left out
            }
            $gone("disk:$name", $loss, (int) $p[0], ['share' => null, 'disk' => $name, 'fs' => (string) $d['fs'],
                'shares' => array_slice(array_map('strval', (array) $d['shares']), 0, WATCH_LIST_MAX), 'kept' => 0, 'asleep' => []]);
        }
    }

    foreach (['conns', 'cts', 'ds', 'smb', 'ref', 'disks', 'moving'] as $part) {   // 'door' is this round's word, never carried
        if ($next[$part] === null) {
            $next[$part] = $prev[$part] ?? null;       // not looked at this time: the last counters stay (counted on next time)
        }
    }
    $flow['last'] = $now;
    $flow['can'] = ['ss' => is_array($conns), 'smb' => is_array($smb) ? (!empty($smb['on']) ? 'on' : 'off') : null, 'nfs' => !empty($look['nfs']),
                    'docker' => is_array($cts), 'host' => array_slice($host, 0, 50),
                    'zfs' => is_array($z) ? ['pools' => array_values((array) $z['pools']), 'asleep' => array_values((array) $z['asleep'])] : null,
                    'disks' => is_array($dk) ? ['disks' => array_keys((array) $dk['disks']), 'asleep' => array_values((array) $dk['asleep'])] : null,
                    'moving' => $movers,
                    'office' => $office ? (string) $look['holder'] : null];
    watchmanFlowTidy($bf, $flow, $now);
    return [array_values(array_filter($added)), $flow, $next];
}

/** Keeps the data flow small: hours beyond WATCH_FLOW_KEEP and tiny past hours go, what is long gone is forgotten, the lists capped */
function watchmanFlowTidy(array &$bf, array &$flow, int $now): void
{
    $oldest = intdiv($now - WATCH_FLOW_KEEP, 3600);
    $cur = intdiv($now, 3600);
    foreach (['clients' => WATCH_FLOW_CLIENTS, 'containers' => WATCH_FLOW_CTS, 'shares' => WATCH_FLOW_SHARES, 'gone' => WATCH_FLOW_SHARES] as $part => $cap) {
        $list = (array) ($flow[$part] ?? []);
        foreach ($list as $key => $s) {
            foreach (['h', 'o'] as $hk) {
                $s[$hk] = array_filter((array) ($s[$hk] ?? []), fn ($b, $idx) => (int) $idx >= $oldest && ((int) $idx >= $cur || (int) $b >= WATCH_FLOW_TINY),
                                       ARRAY_FILTER_USE_BOTH);
            }
            $seen = max((int) ($s['last'] ?? 0), (int) ($s['seen'] ?? 0), (int) ($s['first'] ?? 0));
            if ($now - $seen > WATCH_FORGET) {
                unset($list[$key]);
                continue;
            }
            $list[$key] = $s;
        }
        if (count($list) > $cap) {
            uasort($list, fn ($x, $y) => max((int) ($y['last'] ?? 0), (int) ($y['seen'] ?? 0)) <=> max((int) ($x['last'] ?? 0), (int) ($x['seen'] ?? 0)));
            $list = array_slice($list, 0, $cap, true);
        }
        $flow[$part] = $list;
    }
    $parts = ['flow_client' => 'clients', 'flow_container' => 'containers', 'flow_written' => 'shares', 'flow_gone' => 'gone'];
    foreach ((array) $bf['ack'] as $key => $a) {
        [$kind, $what] = array_pad(explode(':', (string) $key, 2), 2, '');
        if (!isset($parts[$kind], $flow[$parts[$kind]][$what])) {
            unset($bf['ack'][$key]);                // what it was about is forgotten
        }
    }
    foreach (['smb_users' => null, 'smb_clients' => 'last'] as $k => $field) {
        $list = (array) $bf[$k];
        if (count($list) > WATCH_FLOW_SMB_MAX) {
            uasort($list, fn ($x, $y) => ($field ? (int) ($y[$field] ?? 0) : (int) $y) <=> ($field ? (int) ($x[$field] ?? 0) : (int) $x));
            $list = array_slice($list, 0, WATCH_FLOW_SMB_MAX, true);
        }
        $bf[$k] = $list;
    }
    $written = (array) ($flow['totals']['written'] ?? []);
    $flow['totals']['written'] = array_intersect_key($written, (array) $flow['shares']);
}

/** For the metrics (state.json): bytes sent per service, written per share (the WATCH_FLOW_TOP most) since he watches */
function watchmanFlowTotals(array $flow): array
{
    $sent = [];
    foreach (array_keys(WATCH_FLOW_SERVICES) as $svc) {
        $sent[$svc] = (int) ($flow['totals']['sent'][$svc] ?? 0);
    }
    $written = array_map('intval', (array) ($flow['totals']['written'] ?? []));
    arsort($written);
    return ['sent' => $sent, 'written' => array_slice($written, 0, WATCH_FLOW_TOP, true)];
}

/** "38 GB" — like the page's fmt.size (1024, one decimal under 10) */
function watchmanSize(int $bytes, string $lang = 'en'): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $v = (float) max(0, $bytes);
    $i = 0;
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }
    return number_format($v, $i === 0 ? 0 : ($v < 10 ? 1 : 0), $lang === 'en' ? '.' : ',', '') . ' ' . $units[$i];
}

/** Hours of the week as "Mon 03:00" (English day names; the page writes them in the browser's language) */
function watchmanHourNames(array $hours): string
{
    return implode(', ', array_map(fn ($h) => date('D', strtotime('2024-01-01 +' . intdiv((int) $h, 24) . ' days')) . sprintf(' %02d:00', (int) $h % 24),
                                   array_slice($hours, 0, WATCH_LIST_MAX)));
}

/** What is normal, in words of $lang (null: left out — the page writes it itself) */
function watchmanFlowUsualText(array $p, string $kind, ?string $lang): string
{
    if ($lang === null) {
        return '';
    }
    if (!empty($p['learning'])) {
        return $kind === 'flow_written' || ($kind === 'flow_gone' && !empty($p['part']))
            ? officeNotifyText('watchman', 'flow.learning_share', ['pct' => (int) ($p['pct'] ?? 0)], $lang)
            : officeNotifyText('watchman', 'flow.learning', ['size' => watchmanSize((int) ($p['limit'] ?? 0), $lang)], $lang);
    }
    return (int) ($p['usual'] ?? 0) > 0
        ? officeNotifyText('watchman', 'flow.usual', ['size' => watchmanSize((int) $p['usual'], $lang)], $lang)
        : officeNotifyText('watchman', 'flow.usual_none', [], $lang);
}

/** "What I keep an eye on" of the data flow: per client and service, container, share the last 24 h and what is normal now */
function watchmanFlowSummary(?array $bf, ?array $flow, int $now): ?array
{
    if (!is_array($bf) || !is_array($flow)) {
        return null;
    }
    $day = intdiv($now - 86400, 3600);
    $sum = fn (array $h): int => array_sum(array_filter(array_map('intval', $h), fn ($idx) => (int) $idx > $day, ARRAY_FILTER_USE_KEY));
    $learning = fn (array $s): ?int => $now - (int) ($s['first'] ?? $now) < WATCH_FLOW_LEARN ? intdiv($now - (int) ($s['first'] ?? $now), 86400) : null;
    $clients = [];
    foreach ((array) ($flow['clients'] ?? []) as $key => $s) {
        [$ip, $svc] = array_pad(explode('|', (string) $key, 2), 2, '');
        $h = (array) ($s['h'] ?? []);
        $clients[] = ['ip' => $ip, 'service' => $svc, 'name' => (string) ($s['name'] ?? ''), 'day' => $sum($h), 'usual' => watchmanFlowUsual($h, $now),
                      'peak' => $h ? max(array_map('intval', $h)) : 0, 'learning' => $learning($s), 'last' => (int) ($s['last'] ?? 0),
                      'ack' => (int) ($bf['ack']["flow_client:$key"]['bytes'] ?? 0)];
    }
    usort($clients, fn ($x, $y) => [$y['day'], $y['last']] <=> [$x['day'], $x['last']]);
    $cts = [];
    foreach ((array) ($flow['containers'] ?? []) as $name => $s) {
        $h = (array) ($s['h'] ?? []);
        $cts[] = ['name' => (string) $name, 'image' => (string) ($s['image'] ?? ''), 'day' => $sum($h) + $sum((array) ($s['o'] ?? [])), 'office' => $sum((array) ($s['o'] ?? [])),
                  'usual' => watchmanFlowUsual($h, $now), 'media' => !empty($s['media']), 'kopia' => !empty($s['kopia']), 'with' => (array) ($s['with'] ?? []),
                  'learning' => $learning($s), 'running' => (int) ($s['seen'] ?? 0) >= (int) ($flow['last'] ?? 0)];
    }
    usort($cts, fn ($x, $y) => [$y['day'], $x['name']] <=> [$x['day'], $y['name']]);
    $shares = [];
    foreach ((array) ($flow['shares'] ?? []) as $share => $s) {
        $h = (array) ($s['h'] ?? []);
        $shares[] = ['share' => (string) $share, 'day' => $sum($h) + $sum((array) ($s['o'] ?? [])), 'office' => $sum((array) ($s['o'] ?? [])),
                     'written' => (int) ($s['w'] ?? 0), 'used' => (int) ($s['u'] ?? 0), 'snap' => isset($s['s']) ? (int) $s['s'] : null, 'snapshots' => !empty($s['snap']),
                     'usual' => watchmanFlowUsual($h, $now), 'learning' => $learning($s), 'seen' => (int) ($s['seen'] ?? 0)];
    }
    usort($shares, fn ($x, $y) => [$y['day'], $x['share']] <=> [$x['day'], $y['share']]);
    $gone = [];
    foreach ((array) ($flow['gone'] ?? []) as $key => $s) {
        $h = (array) ($s['h'] ?? []);
        $gone[] = ['key' => (string) $key, 'share' => $s['share'] ?? null, 'disk' => $s['disk'] ?? null, 'fs' => (string) ($s['fs'] ?? 'zfs'),
                   'shares' => (array) ($s['shares'] ?? []), 'size' => (int) ($s['size'] ?? 0), 'day' => $sum($h), 'expected' => $sum((array) ($s['o'] ?? [])),
                   'usual' => watchmanFlowUsual($h, $now), 'learning' => $learning($s), 'asleep' => (array) ($s['asleep'] ?? []),
                   'last' => (int) ($s['last'] ?? 0), 'ack' => (int) ($bf['ack']["flow_gone:$key"]['bytes'] ?? 0)];
    }
    usort($gone, fn ($x, $y) => [$y['day'] + $y['expected'], $y['last'], $x['key']] <=> [$x['day'] + $x['expected'], $x['last'], $y['key']]);
    $smbClients = [];
    foreach ((array) ($bf['smb_clients'] ?? []) as $ip => $c) {
        $smbClients[] = ['ip' => (string) $ip, 'name' => (string) ($c['name'] ?? ''), 'hours' => count((array) ($c['hours'] ?? [])),
                         'learning' => $learning($c), 'last' => (int) ($c['last'] ?? 0)];
    }
    usort($smbClients, fn ($x, $y) => $y['last'] <=> $x['last']);
    $users = array_map('strval', array_keys((array) ($bf['smb_users'] ?? [])));
    sort($users);
    return [
        'since'      => (int) ($bf['since'] ?? $now),
        'days'       => intdiv($now - (int) ($bf['since'] ?? $now), 86400),
        'learn'      => intdiv(WATCH_FLOW_LEARN, 86400),
        'last'       => (int) ($flow['last'] ?? 0),
        'can'        => (array) ($flow['can'] ?? []),
        'clients'    => array_slice($clients, 0, 40),
        'containers' => array_slice($cts, 0, 15),
        'idle'       => max(0, count($cts) - 15),
        'shares'     => array_slice($shares, 0, 60),
        'gone'       => array_slice($gone, 0, 60),
        'smb'        => ['users' => array_slice($users, 0, 60), 'clients' => array_slice($smbClients, 0, 40)],
        'limits'     => ['factor' => WATCH_FLOW_FACTOR, 'min' => WATCH_FLOW_MIN, 'new' => WATCH_FLOW_NEW, 'part' => (int) round(WATCH_FLOW_PART * 100),
                         'keep' => intdiv(WATCH_FLOW_KEEP, 86400), 'gone_new' => WATCH_GONE_NEW, 'gone_part' => (int) round(WATCH_GONE_PART * 100)],
    ];
}

// ===================================================================== snapshots that vanish

/*
 * An attacker removes the snapshots first, then encrypts. Every round he compares the snapshots with
 * the round before — cheap, and never waking a disk: `zfs list -t snapshot -o name,guid,userrefs` of
 * the awake ZFS pools only (disks.ini, like the data flow; Docker's image layers left out), and the
 * snapshot folders of the awake btrfs disks and pools (.btrfs-snap — the engine's and Ms.
 * Snapshotini's — and the engine's btrfs_snap_dir). A pool asleep keeps its last list: compared once it
 * is awake again, never "gone" because it slept.
 *
 * What the office removes itself is no news:
 *   Ms. Snapshotini  her deletions, releases and renames — by hand and her schedules' retention — stand
 *                    in her own record, which only root can write (data/snapshot/deletes.jsonl, see
 *                    snapshot.php; watchmanSnapRecord()), and in the office's log (agent.log: "Deleted:
 *                    <ds>@<a>,<b>", "Deleted: <path> (btrfs)", "Released: <ds>@<name>", "Renamed: <where>
 *                    <old> → <new>"); both read by offset like the syslog, remembered WATCH_SNAP_OFFICE (a
 *                    deletion noted before the snapshot is missed in a list still counts). The record wins:
 *                    the log's lines count only until it is there (the round it first shows up — what an
 *                    older office logged), and once she keeps one, a record that isn't one any more (gone,
 *                    open to others) leaves her lines unproven
 *   the engine       its retention prunes only its own names, only in a real backup run (status.json,
 *                    history.jsonl — its interface, never its log) and always keeps the newest: one of
 *                    its snapshots gone while a run went on, with a newer one of its own still on that
 *                    dataset (that disk), is its retention
 *   renamed          a ZFS snapshot whose guid is still there under another name (Ms. Snapshotini's
 *                    rename, Mr. Restori and Ms. Dustdevil renaming datasets aside)
 *   the storeroom    datasets in Ms. Dustdevil's storeroom (put away, and emptied when you ask her)
 *   learned          «I know, thanks» on an entry: snapshots named like those (their series: the name
 *                    with its numbers as #) may go — while a newer one of the same series stays on that
 *                    dataset, like a retention of yours does
 * The rest: `snap_gone` (important) — how many, which datasets, the pool's `zpool history -l` lines of
 * that time naming them (who ran zfs destroy, when — read only then) and the syslog around it. A hold
 * released (userrefs fewer) not by Ms. Snapshotini: `snap_hold_released` (important) — the step before
 * deleting a held snapshot. The lists, what the office removed and the log's position: snaps.json (only
 * rounds write it). The office's log is writable by the web server's user like all its data: whoever
 * writes «Deleted:» lines into it could hide a deletion — that is why her record (root only) counts
 * instead of them.
 */
const WATCH_SNAP_MAX      = 20000;              // snapshots followed in all (a pool beyond is not compared)
const WATCH_SNAP_DIR_MAX  = 5000;               // btrfs snapshots per disk
const WATCH_SNAP_OFFICE   = 7 * 86400;          // what the office removed is remembered this long
const WATCH_SNAP_OFFICE_N = 5000;               // … at most so many
const WATCH_SNAP_LOG_MAX  = 4 * 1024 * 1024;    // the office's log read in one round at most
const WATCH_SNAP_SERIES   = 200;                // learned series
const WATCH_SNAP_DOCKER   = '/^(?:[0-9a-f]{64}|[a-z0-9]{25})(?:-init)?$/D';     // Docker's image layers (its zfs storage driver)
const WATCH_SNAP_NAME     = '#^[A-Za-z0-9_.:-]+(?:/[^\x00-\x1F@/]+)*@[^\x00-\x1F@/,]+$#D';
const WATCH_SNAP_EVIDENCE = '#\bzfs\b|\bzpool\b|btrfs|snapshot|subvolume|destroy|shcmd|/plugins/|\.(?:sh|php|py)\b|user\.scripts|unraid-secretary-office#i';

/**
 * One round's snapshot watch, outside the book's lock: the look, what the office removed since, the
 * engine's runs, what is gone and why, the evidence for what nobody of the office did. $known: snaps.json
 * (null: taking over the watch — all of it is normal), $series: the learned series (baseline).
 *
 * @return array{known: array, gone: list<array>, released: list<array>, summary: array}
 */
function watchmanSnaps(array $paths, ?array $known, array $series, int $now): array
{
    $settings = isset($paths['engine']) ? backupReadSettings($paths['engine'] . '/settings.ini') : [];
    $snapDirs = array_values(array_unique(['.btrfs-snap', basename((string) backupSetting($settings, 'general', 'btrfs_snap_dir', '.btrfs-snap'))]));
    $prefixes = backupSnapPrefixes(backupSetting($settings, 'general', 'snap_prefix'));
    $look = watchmanSnapLook($paths, $snapDirs);
    [$events, $pos] = isset($paths['agent_log'])
        ? watchmanSnapOfficeLog($paths['agent_log'], is_array($known['log'] ?? null) ? $known['log'] : null, $now)
        : [['d' => [], 'r' => [], 'm' => []], null];
    // her own record (root only) beats her log lines: those count only for what came before it — the
    // round it first shows up; a record she kept that isn't one any more leaves her lines unproven
    $hadRecord = is_array($known['record'] ?? null);
    $record = isset($paths['snap_record']) ? watchmanSnapRecord($paths['snap_record'], $hadRecord ? $known['record'] : null, $known === null) : null;
    $recPos = $hadRecord ? $known['record'] : null;
    if ($record !== null) {
        [$recEvents, $recPos] = $record;
        $events = $hadRecord ? $recEvents : watchmanSnapEventsAdd($events, $recEvents);
    } elseif ($hadRecord) {
        $events = ['d' => [], 'r' => [], 'm' => []];
    }
    $office = watchmanSnapOfficeMerge((array) ($known['office'] ?? []), $events, $now);
    // what the partner door's retention destroyed of the copies it keeps (data/partner/deletes.jsonl, root only)
    $partnerPos = is_array($known['partner'] ?? null) ? $known['partner'] : null;
    $partner = isset($paths['partner_data']) ? watchmanPartnerDeletes($paths['partner_data'] . '/deletes.jsonl', $partnerPos, $known === null) : null;
    if ($partner !== null) {
        [$partnerEvents, $partnerPos] = $partner;
    }
    $office['p'] = watchmanSnapPartnerMerge((array) ($known['office']['p'] ?? []), $partnerEvents ?? [], $now);
    $runs = isset($paths['engine']) ? watchmanEngineRuns($paths['engine']) : [];
    $diff = watchmanSnapDiff($known, $look, $office, $runs, $prefixes, $now);
    $office = $diff['office'];
    $gone = [];
    foreach ($diff['gone'] as $g) {
        $g['list'] = watchmanSnapUnlearned($g['list'], $series);
        if ($g['list']) {
            $gone[] = $g + watchmanSnapEvidence($paths, $g, $now, 'destroy|rename|release');
        }
    }
    $released = [];
    foreach ($diff['released'] as $g) {
        $released[] = $g + watchmanSnapEvidence($paths, $g, $now, 'release|destroy');
    }
    $diff['known'] += ['office' => $office, 'log' => $pos, 'record' => $recPos, 'partner' => $partnerPos];
    $count = fn (array $lists) => array_map('count', $lists);
    return ['known' => $diff['known'], 'gone' => $gone, 'released' => $released, 'summary' => [
        'time'     => $now,
        'zfs'      => is_array($look['zfs']) ? $count($look['zfs']['snaps']) : null,
        'btrfs'    => is_array($look['btrfs']) ? $count($look['btrfs']['snaps']) : null,
        'asleep'   => array_values(array_merge((array) ($look['zfs']['asleep'] ?? []), (array) ($look['btrfs']['asleep'] ?? []))),
        'capped'   => (array) ($look['zfs']['capped'] ?? []),
        'expected' => $diff['expected'],
    ]];
}

/**
 * The snapshots now: ZFS of the awake pools (one `zfs list`; a pool sleeps when any of its disks does —
 * never asked; Docker's layers left out; over WATCH_SNAP_MAX the pools beyond aren't looked at), the
 * snapshot folders of the awake btrfs disks and pools. A part that can't be looked at is null; a pool
 * not looked at (asleep, capped, zfs failing) keeps its last list.
 *
 * @return array{zfs: ?array{pools: list<string>, asleep: list<string>, capped: list<string>, snaps: array<string, array<string, array{0: string, 1: int}>>},
 *               btrfs: ?array{disks: list<string>, asleep: list<string>, snaps: array<string, array<string, int>>}}
 */
function watchmanSnapLook(array $paths, array $snapDirs): array
{
    $ini = isset($paths['disks_ini']) ? readCfg($paths['disks_ini'], true) : [];
    $sleep = [];
    foreach ($ini as $section => $v) {
        $sleep[(string) ($v['name'] ?? $section)] = diskAsleep($v);       // spun down AND rotating (lib/mounts.php)
    }
    $zpools = $zasleep = $bdisks = $basleep = [];
    foreach ($ini as $section => $v) {
        $name = (string) ($v['name'] ?? $section);
        $fs = (string) preg_replace('/^luks:/', '', strtolower((string) ($v['fsType'] ?? '')));
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $name)) {
            continue;
        }
        if ($fs === 'zfs' && !in_array($v['type'] ?? '', ['Boot', 'Flash'], true)) {
            baseAsleep($name, $sleep) ? $zasleep[] = $name : $zpools[] = $name;
        } elseif ($fs === 'btrfs' && in_array($v['type'] ?? '', ['Data', 'Cache'], true) && ($v['fsStatus'] ?? 'Mounted') === 'Mounted') {
            baseAsleep($name, $sleep) ? $basleep[] = $name : $bdisks[] = $name;
        }
    }
    $zfs = null;
    $zbin = isset($paths['zfs']) ? (str_contains($paths['zfs'], '/') ? $paths['zfs'] : bin($paths['zfs'])) : null;
    if ($zbin !== null && $ini) {
        $zfs = ['pools' => [], 'asleep' => $zasleep, 'capped' => [], 'snaps' => []];
        if ($zpools) {
            [$exit, $out] = run([$zbin, 'list', '-H', '-p', '-t', 'snapshot', '-o', 'name,guid,userrefs', '-r', ...$zpools], 120);
            if ($exit === 0) {
                $lists = watchmanSnapParse($out);
                $total = 0;
                foreach ($zpools as $pool) {
                    $n = count($lists[$pool] ?? []);
                    if ($total + $n > WATCH_SNAP_MAX) {
                        $zfs['capped'][] = $pool;
                        continue;
                    }
                    $total += $n;
                    $zfs['pools'][] = $pool;
                    $zfs['snaps'][$pool] = $lists[$pool] ?? [];
                }
            } else {
                $zfs['failed'] = true;      // zfs didn't answer: nothing looked at, every list kept
            }
        }
    }
    $btrfs = null;
    if (isset($paths['mnt']) && $ini) {
        $mnt = rtrim($paths['mnt'], '/');
        $btrfs = ['disks' => [], 'asleep' => $basleep, 'snaps' => []];
        foreach ($bdisks as $d) {
            if (!is_dir("$mnt/$d")) {
                continue;                   // not mounted: not looked at
            }
            $list = [];
            foreach ($snapDirs as $sd) {
                $dir = "$mnt/$d/$sd";
                if (!preg_match('/^\.?[A-Za-z0-9_.-]{1,64}$/D', $sd) || is_link($dir) || !is_dir($dir)) {
                    continue;
                }
                foreach (@scandir($dir) ?: [] as $n) {
                    if ($n === '.' || $n === '..' || count($list) >= WATCH_SNAP_DIR_MAX || preg_match('/[\x00-\x1F]/', $n)) {
                        continue;
                    }
                    if (!is_link("$dir/$n") && is_dir("$dir/$n")) {
                        $list["$dir/$n"] = 1;
                    }
                }
            }
            ksort($list);
            $btrfs['disks'][] = $d;
            $btrfs['snaps'][$d] = $list;
        }
    }
    return ['zfs' => $zfs, 'btrfs' => $btrfs];
}

/** zfs list -Hp -t snapshot -o name,guid,userrefs → pool => [dataset@name => [guid, userrefs]], Docker's layers left out */
function watchmanSnapParse(string $text): array
{
    $out = [];
    foreach (explode("\n", $text) as $line) {
        $f = explode("\t", $line);
        if (count($f) !== 3 || !preg_match(WATCH_SNAP_NAME, $f[0]) || !ctype_digit($f[1]) || !ctype_digit(trim($f[2]))) {
            continue;
        }
        $ds = strstr($f[0], '@', true);
        if (preg_match(WATCH_SNAP_DOCKER, basename($ds))) {
            continue;
        }
        $out[explode('/', $ds)[0]][$f[0]] = [$f[1], (int) trim($f[2])];
    }
    foreach ($out as &$list) {
        ksort($list);
    }
    return $out;
}

/** A snapshot's series: its name with every number as # (uso-plan-daily-20261006-0100 → uso-plan-daily-#-#) */
function watchmanSnapSeries(string $name): string
{
    return (string) preg_replace('/\d+/', '#', $name);
}

/**
 * The office's log since $pos: what Ms. Snapshotini deleted, released and renamed (by hand and her
 * schedules). Rotated meanwhile (agent.log.1, found by its inode): its rest first. Without a position:
 * from now on. Only whole lines, at most WATCH_SNAP_LOG_MAX of a file.
 *
 * @return array{0: array{d: array<string, int>, r: array<string, int>, m: list<array{0: string, 1: string, 2: string, 3: int}>}, 1: ?array}
 */
function watchmanSnapOfficeLog(string $log, ?array $pos, int $now): array
{
    $ev = ['d' => [], 'r' => [], 'm' => []];
    clearstatcache();
    $st = @stat($log);
    if (!$st) {
        return [$ev, $pos];
    }
    $read = function (string $file, int $from) use (&$ev, $now): int {
        $h = @fopen($file, 'r');
        if (!$h) {
            return $from;
        }
        $size = (int) (fstat($h)['size'] ?? 0);
        $start = max($from, $size - WATCH_SNAP_LOG_MAX);
        fseek($h, $start);
        if ($start > $from && $start > 0) {
            fgets($h);              // began inside a line
        }
        $at = (int) ftell($h);
        while (($line = fgets($h)) !== false && str_ends_with($line, "\n")) {
            $at += strlen($line);
            if (!preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)  (Deleted|Released|Renamed): (.+)$/D', rtrim($line, "\r\n"), $m)) {
                continue;
            }
            $t = strtotime($m[1]) ?: $now;
            if ($m[2] === 'Deleted' && preg_match('/^(.+) \(btrfs\)$/D', $m[3], $x)) {
                $ev['d'][$x[1]] = $t;
            } elseif ($m[2] === 'Deleted' && preg_match('/^([^@]+)@(.+)$/D', $m[3], $x)) {
                foreach (explode(',', $x[2]) as $n) {
                    $ev['d']["$x[1]@$n"] = $t;
                }
            } elseif ($m[2] === 'Released' && preg_match('/^[^@]+@.+$/D', $m[3])) {
                $ev['r'][$m[3]] = $t;
            } elseif ($m[2] === 'Renamed' && preg_match('/^(.+) (\S+) → (\S+)$/uD', $m[3], $x)) {
                $ev['m'][] = [$x[1], $x[2], $x[3], $t];
            }
        }
        fclose($h);
        return $at;
    };
    if ($pos === null) {
        return [$ev, ['ino' => (int) $st['ino'], 'size' => (int) $st['size']]];
    }
    if ((int) ($pos['ino'] ?? -1) === (int) $st['ino'] && (int) ($pos['size'] ?? PHP_INT_MAX) <= (int) $st['size']) {
        $from = (int) $pos['size'];
    } else {
        $from = 0;
        $old = @stat("$log.1");
        if ($old && (int) $old['ino'] === (int) ($pos['ino'] ?? -1) && (int) ($pos['size'] ?? PHP_INT_MAX) <= (int) $old['size']) {
            $read("$log.1", (int) $pos['size']);
        }
    }
    $end = $read($log, $from);
    return [$ev, ['ino' => (int) $st['ino'], 'size' => $end]];
}

/**
 * Ms. Snapshotini's own record (data/snapshot/deletes.jsonl, see agent/desks/snapshot.php) since $pos:
 * the same facts as her log lines, in a file only root can write. Counts only while it is one: a real
 * folder of root's, closed to others, and in it a plain file of root's, closed to others, one link —
 * else null (not a record). Rotated meanwhile (deletes.jsonl.1, found by its inode): its rest first.
 * Without a position: from now on when the watch is taken over anew ($fresh), else from its beginning
 * (the round it first appears). Only whole lines, at most WATCH_SNAP_LOG_MAX of a file.
 *
 * @return array{0: array{d: array<string, int>, r: array<string, int>, m: list<array{0: string, 1: string, 2: string, 3: int}>}, 1: array}|null
 */
function watchmanSnapRecord(string $file, ?array $pos, bool $fresh): ?array
{
    $own = function (string $path, bool $dir): ?array {
        $st = @lstat($path);
        return $st && ($st['mode'] & 0170000) === ($dir ? 0040000 : 0100000) && $st['uid'] === 0 && !($st['mode'] & 0077)
            && ($dir || $st['nlink'] === 1) ? $st : null;
    };
    clearstatcache();
    if ($own(dirname($file), true) === null) {
        return null;
    }
    $st = $own($file, false);
    $old = $own("$file.1", false);
    if ($st === null && (file_exists($file) || is_link($file) || $old === null)) {
        return null;                        // missing for a moment while she starts a new one: only .1 then
    }
    $ev = ['d' => [], 'r' => [], 'm' => []];
    $read = function (string $path, int $from) use (&$ev): int {
        $h = @fopen($path, 'r');
        if (!$h) {
            return $from;
        }
        $size = (int) (fstat($h)['size'] ?? 0);
        $start = max($from, $size - WATCH_SNAP_LOG_MAX);
        fseek($h, $start);
        if ($start > $from && $start > 0) {
            fgets($h);                      // began inside a line
        }
        $at = (int) ftell($h);
        while (($line = fgets($h)) !== false && str_ends_with($line, "\n")) {
            $at += strlen($line);
            $e = json_decode($line, true);
            $str = fn (string $k): ?string => is_array($e) && is_string($e[$k] ?? null) && $e[$k] !== '' && !preg_match('/[\x00-\x1F]/', $e[$k]) ? $e[$k] : null;
            if (!is_array($e) || !is_int($e['t'] ?? null)) {
                continue;
            }
            $t = $e['t'];
            if (($e['do'] ?? '') === 'deleted' && ($e['fs'] ?? '') === 'zfs' && $str('ds') !== null && is_array($e['names'] ?? null)) {
                foreach ($e['names'] as $n) {
                    if (is_string($n) && $n !== '' && !preg_match('/[\x00-\x1F@,]/', $n)) {
                        $ev['d'][$e['ds'] . "@$n"] = $t;
                    }
                }
            } elseif (($e['do'] ?? '') === 'deleted' && ($e['fs'] ?? '') === 'btrfs' && $str('path') !== null) {
                $ev['d'][$e['path']] = $t;
            } elseif (($e['do'] ?? '') === 'released' && $str('ds') !== null && $str('name') !== null) {
                $ev['r'][$e['ds'] . '@' . $e['name']] = $t;
            } elseif (($e['do'] ?? '') === 'renamed' && $str('where') !== null && $str('from') !== null && $str('to') !== null) {
                $ev['m'][] = [$e['where'], $e['from'], $e['to'], $t];
            }
        }
        fclose($h);
        return $at;
    };
    if ($st === null) {                     // only .1 for a moment: its rest; the new one from its beginning next round
        $from = $pos !== null && (int) ($pos['ino'] ?? -1) === (int) $old['ino'] ? (int) ($pos['size'] ?? 0) : 0;
        $read("$file.1", $from);
        return [$ev, ['ino' => -1, 'size' => 0]];
    }
    if ($pos === null && $fresh) {
        return [$ev, ['ino' => (int) $st['ino'], 'size' => (int) $st['size']]];
    }
    $from = 0;
    if ($pos !== null && (int) ($pos['ino'] ?? -1) === (int) $st['ino'] && (int) ($pos['size'] ?? PHP_INT_MAX) <= (int) $st['size']) {
        $from = (int) $pos['size'];
    } elseif ($pos !== null && $old !== null && (int) $old['ino'] === (int) ($pos['ino'] ?? -1) && (int) ($pos['size'] ?? PHP_INT_MAX) <= (int) $old['size']) {
        $read("$file.1", (int) $pos['size']);
    }
    $end = $read($file, $from);
    return [$ev, ['ino' => (int) $st['ino'], 'size' => $end]];
}

/**
 * What the partner door's retention destroyed (data/partner/deletes.jsonl — agent/partner-door.php appends a line
 * `{t, pair, dataset, snaps: [uso-backup-…]}` before it destroys them; .1 beyond 1 MB): trusted only while its folder
 * and the file are root's own and closed to others, only datasets under a pool's UnraidSecretaryOffice-partners and
 * only the engine's snapshot names — the door destroys nothing else. Read by offset (inode), like Ms. Snapshotini's
 * record; his first look starts at its end. Null: not trusted (nothing of it counts).
 *
 * @return array{0: array<string, int>, 1: ?array}|null  "<dataset>@<snapshot>" => time, the position
 */
function watchmanPartnerDeletes(string $file, ?array $pos, bool $fresh): ?array
{
    $own = function (string $path, bool $dir): ?array {
        $st = @lstat($path);
        return $st && ($st['mode'] & 0170000) === ($dir ? 0040000 : 0100000) && $st['uid'] === 0 && !($st['mode'] & 0077)
            && ($dir || $st['nlink'] === 1) ? $st : null;
    };
    clearstatcache();
    if ($own(dirname($file), true) === null) {
        return null;
    }
    $st = $own($file, false);
    if ($st === null) {
        return file_exists($file) || is_link($file) ? null : [[], $pos];       // nothing destroyed so far
    }
    $ev = [];
    $read = function (string $path, int $from) use (&$ev): int {
        $h = @fopen($path, 'r');
        if (!$h) {
            return $from;
        }
        $size = (int) (fstat($h)['size'] ?? 0);
        $start = max($from, $size - WATCH_SNAP_LOG_MAX);
        fseek($h, $start);
        if ($start > $from && $start > 0) {
            fgets($h);
        }
        $at = (int) ftell($h);
        while (($line = fgets($h)) !== false && str_ends_with($line, "\n")) {
            $at += strlen($line);
            $e = json_decode($line, true);
            $ds = is_array($e) ? ($e['dataset'] ?? null) : null;
            if (!is_int($e['t'] ?? null) || !is_string($ds) || preg_match('/[\x00-\x1F@,]/', $ds) || ($d = partnerLookDataset($ds)) === null
                || $d['id'] === null || $d['trash'] || !is_array($e['snaps'] ?? null)) {
                continue;
            }
            foreach ($e['snaps'] as $n) {
                if (is_string($n) && preg_match(PARTNER_SNAP_RE, $n)) {
                    $ev["$ds@$n"] = $e['t'];
                }
            }
        }
        fclose($h);
        return $at;
    };
    if ($pos === null && $fresh) {
        return [[], ['ino' => (int) $st['ino'], 'size' => (int) $st['size']]];
    }
    $from = 0;
    if ($pos !== null && (int) ($pos['ino'] ?? -1) === (int) $st['ino'] && (int) ($pos['size'] ?? PHP_INT_MAX) <= (int) $st['size']) {
        $from = (int) $pos['size'];
    } elseif ($pos !== null && ($old = $own("$file.1", false)) !== null && (int) $old['ino'] === (int) ($pos['ino'] ?? -1)
              && (int) ($pos['size'] ?? PHP_INT_MAX) <= (int) $old['size']) {
        $read("$file.1", (int) $pos['size']);
    }
    $end = $read($file, $from);
    return [$ev, ['ino' => (int) $st['ino'], 'size' => $end]];
}

/** What the partner door destroyed, remembered like the office's own removals (WATCH_SNAP_OFFICE, at most WATCH_SNAP_OFFICE_N) */
function watchmanSnapPartnerMerge(array $old, array $new, int $now): array
{
    $list = array_filter($new + $old, fn ($t) => is_int($t) && $t >= $now - WATCH_SNAP_OFFICE);
    arsort($list);
    return array_slice($list, 0, WATCH_SNAP_OFFICE_N, true);
}

/** Two rounds' worth of what the office removed, as one */
function watchmanSnapEventsAdd(array $a, array $b): array
{
    return ['d' => $b['d'] + $a['d'], 'r' => $b['r'] + $a['r'], 'm' => array_merge($a['m'], $b['m'])];
}

/** What the office removed: the remembered and this round's, those older than WATCH_SNAP_OFFICE out, at most WATCH_SNAP_OFFICE_N */
function watchmanSnapOfficeMerge(array $old, array $new, int $now): array
{
    $out = [];
    foreach (['d', 'r'] as $k) {
        $list = array_filter((array) ($old[$k] ?? []) + (array) ($new[$k] ?? []), fn ($t) => is_int($t) && $t >= $now - WATCH_SNAP_OFFICE);
        foreach ((array) ($new[$k] ?? []) as $name => $t) {
            $list[$name] = $t;              // the newest time
        }
        arsort($list);
        $out[$k] = array_slice($list, 0, WATCH_SNAP_OFFICE_N, true);
    }
    $m = array_values(array_filter(array_merge((array) ($old['m'] ?? []), (array) ($new['m'] ?? [])),
        fn ($x) => is_array($x) && count($x) === 4 && (int) $x[3] >= $now - WATCH_SNAP_OFFICE));
    $out['m'] = array_slice($m, -WATCH_SNAP_OFFICE_N);
    return $out;
}

/**
 * The engine's real backup runs — only they prune its snapshots: the one in status.json (going on while
 * its lock is held) and the newest of history.jsonl (skipped ones left out), as [started, ended|null].
 *
 * @return list<array{0: int, 1: ?int}>
 */
function watchmanEngineRuns(string $engine): array
{
    $runs = [];
    $st = readJson("$engine/state/status.json");
    if (is_array($st) && ($st['mode'] ?? '') === 'backup' && is_numeric($st['started'] ?? null)) {
        $end = is_numeric($st['finished'] ?? null) ? (int) $st['finished'] : null;
        if ($end === null && !flockHeld("$engine/state/lock")) {
            $end = is_numeric($st['updated'] ?? null) ? (int) $st['updated'] : (int) $st['started'];     // ended without a word
        }
        $runs[] = [(int) $st['started'], $end];
    }
    $lines = @file("$engine/state/history.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach (array_slice($lines, -20) as $line) {
        $r = json_decode($line, true);
        if (is_array($r) && ($r['result'] ?? '') !== 'skipped' && in_array($r['mode'] ?? 'backup', ['backup', ''], true)
            && is_numeric($r['started'] ?? null) && is_numeric($r['finished'] ?? null)) {
            $runs[] = [(int) $r['started'], (int) $r['finished']];
        }
    }
    return $runs;
}

/** Did one of the engine's runs go on between $from and $to? */
function watchmanEngineRan(array $runs, int $from, int $to): bool
{
    foreach ($runs as [$start, $end]) {
        if ($start <= $to && ($end ?? $to) >= $from) {
            return true;
        }
    }
    return false;
}

/**
 * The lists then ($known) and now ($look): what went, which ZFS snapshots lost a hold — and why
 * the office's going ones are no news (watchmanSnapWhy()). The new lists: what was looked at now, the
 * rest as before (a pool asleep, not looked at); a pool or disk gone from disks.ini is forgotten after
 * WATCH_FORGET. Gone and released per pool/disk, with the time of the list before ('from').
 *
 * @return array{known: array, gone: list<array>, released: list<array>, expected: array<string, int>, office: array}
 */
function watchmanSnapDiff(?array $known, array $look, array $office, array $runs, array $prefixes, int $now): array
{
    $new = ['zfs' => (array) ($known['zfs'] ?? []), 'btrfs' => (array) ($known['btrfs'] ?? [])];
    $gone = $released = [];
    $expected = [];
    $there_ = [];                   // every snapshot there now
    $note = function (string $why) use (&$expected): void {
        $expected[$why] = ($expected[$why] ?? 0) + 1;
    };
    if (is_array($look['zfs'] ?? null)) {
        $guids = [];
        foreach ($look['zfs']['snaps'] as $list) {
            foreach ($list as $full => $v) {
                $guids[$v[0]] = true;
            }
        }
        foreach ($look['zfs']['pools'] as $pool) {
            $cur = $look['zfs']['snaps'][$pool] ?? [];
            $there_ += $cur;
            $old = $known === null ? null : ($known['zfs'][$pool] ?? null);
            if (is_array($old)) {
                $present = [];
                foreach ($cur as $full => $_) {
                    [$ds, $n] = explode('@', $full, 2);
                    $present[$ds][] = $n;
                }
                $from = (int) ($old['time'] ?? 0);
                $lost = $rel = [];
                foreach ((array) ($old['s'] ?? []) as $full => $v) {
                    [$guid, $refs] = array_pad(explode(':', (string) $v, 2), 2, '0');
                    $refs = (int) $refs;
                    if (isset($cur[$full])) {
                        if ($cur[$full][1] < $refs && !storeroomIn((string) $full)) {
                            isset($office['r'][$full]) ? $note('office') : $rel[] = ['name' => (string) $full];
                        }
                        continue;
                    }
                    [$ds, $n] = explode('@', (string) $full, 2) + [1 => ''];
                    $why = isset($guids[$guid]) ? 'renamed' : watchmanSnapWhy('zfs', (string) $full, $ds, $n, $office, $runs, $prefixes, $present[$ds] ?? [], $from, $now);
                    if ($why !== null) {
                        $note($why);
                        continue;
                    }
                    $lost[] = ['name' => (string) $full, 'ds' => $ds, 'held' => $refs > 0] + watchmanSnapLeft($n, $present[$ds] ?? []);
                }
                if ($lost) {
                    $gone[] = ['fs' => 'zfs', 'where' => $pool, 'from' => $from, 'list' => $lost];
                }
                if ($rel) {
                    $released[] = ['fs' => 'zfs', 'where' => $pool, 'from' => $from, 'list' => $rel];
                }
            }
            $new['zfs'][$pool] = ['time' => $now, 's' => array_map(fn ($v) => "$v[0]:$v[1]", $cur)];
        }
    }
    if (is_array($look['btrfs'] ?? null)) {
        foreach ($look['btrfs']['disks'] as $disk) {
            $cur = $look['btrfs']['snaps'][$disk] ?? [];
            $there_ += $cur;
            $old = $known === null ? null : ($known['btrfs'][$disk] ?? null);
            if (is_array($old)) {
                $present = [];
                foreach ($cur as $path => $_) {
                    $present[dirname($path)][] = basename($path);
                }
                $from = (int) ($old['time'] ?? 0);
                $lost = [];
                foreach (array_keys((array) ($old['s'] ?? [])) as $path) {
                    $path = (string) $path;
                    if (isset($cur[$path])) {
                        continue;
                    }
                    $dir = dirname($path);
                    $why = watchmanSnapWhy('btrfs', $path, $dir, basename($path), $office, $runs, $prefixes, $present[$dir] ?? [], $from, $now);
                    if ($why !== null) {
                        $note($why);
                        continue;
                    }
                    $lost[] = ['name' => $path, 'ds' => $dir, 'held' => false] + watchmanSnapLeft(basename($path), $present[$dir] ?? []);
                }
                if ($lost) {
                    $gone[] = ['fs' => 'btrfs', 'where' => $disk, 'from' => $from, 'list' => $lost];
                }
            }
            $new['btrfs'][$disk] = ['time' => $now, 's' => $cur];
        }
    }
    // a deletion the office logged before a snapshot of that name was there (again): made anew since — that record is used up
    foreach ($office['d'] ?? [] as $id => $t) {
        if ((int) $t < $now - 5 && isset($there_[(string) $id])) {
            unset($office['d'][$id]);
        }
    }
    // a pool or disk that left disks.ini (not asleep, not looked at): forgotten after a while
    $there = array_merge((array) ($look['zfs']['pools'] ?? []), (array) ($look['zfs']['asleep'] ?? []), (array) ($look['zfs']['capped'] ?? []),
        (array) ($look['btrfs']['disks'] ?? []), (array) ($look['btrfs']['asleep'] ?? []));
    foreach (['zfs', 'btrfs'] as $fs) {
        foreach ($new[$fs] as $name => $x) {
            if (!in_array((string) $name, $there, true) && is_array($look[$fs] ?? null) && empty($look[$fs]['failed'])
                && $now - (int) ($x['time'] ?? 0) > WATCH_FORGET) {
                unset($new[$fs][$name]);
            }
        }
    }
    ksort($expected);
    return ['known' => $new + ['time' => $now], 'gone' => $gone, 'released' => $released, 'expected' => $expected, 'office' => $office];
}

/**
 * Why a snapshot that went is no news, or null: the office deleted it (Ms. Snapshotini's log), renamed
 * (btrfs: her log; ZFS: its guid — the caller), in Ms. Dustdevil's storeroom, or the engine's retention
 * (its name, a real run between the two lists, a newer one of its own still there). $present: the names
 * still on that dataset (btrfs: in that folder).
 */
function watchmanSnapWhy(string $fs, string $id, string $ds, string $name, array $office, array $runs, array $prefixes, array $present, int $from, int $to): ?string
{
    if (isset($office['d'][$id])) {
        return 'office';
    }
    if (isset($office['p'][$id])) {
        return 'partner';               // the partner door's retention on a partner's copies
    }
    if (storeroomIn($ds)) {
        return 'storeroom';
    }
    if ($fs === 'btrfs') {
        foreach ((array) ($office['m'] ?? []) as [$where, $old, $new]) {
            if ($old === $name && str_starts_with($id, rtrim((string) $where, '/') . '/') && in_array($new, $present, true)) {
                return 'renamed';
            }
        }
    }
    if (backupIsEngineSnap($name, $prefixes, $fs) && watchmanEngineRan($runs, $from, $to)) {
        $stamp = substr($name, -13);
        foreach ($present as $p) {
            if (backupIsEngineSnap((string) $p, $prefixes, $fs) && strcmp(substr((string) $p, -13), $stamp) > 0) {
                return 'engine';
            }
        }
    }
    return null;
}

/** A snapshot's series, and whether a newer one of it stays ($present: the names still there) */
function watchmanSnapLeft(string $name, array $present): array
{
    $series = watchmanSnapSeries($name);
    $left = false;
    foreach ($present as $p) {
        $left = $left || (watchmanSnapSeries((string) $p) === $series && strnatcmp((string) $p, $name) > 0);
    }
    return ['series' => $series, 'left' => $left];
}

/** What went, without what a learned series may lose (a newer one of it stays) */
function watchmanSnapUnlearned(array $list, array $series): array
{
    return array_values(array_filter($list, fn ($x) => !(!empty($x['left']) && isset($series[$x['series'] ?? '']))));
}

/**
 * Who removed them: `zpool history -l` of the pool (each command with its time, user and host — read
 * only now, for this) — the lines since the list before that name these datasets (or one above them) —
 * and the syslog around the first of them; without one, around the time between the two lists.
 *
 * @return array{history: list<string>, evidence: list<string>}
 */
function watchmanSnapEvidence(array $paths, array $g, int $now, string $verbs): array
{
    $from = (int) ($g['from'] ?? $now - WATCH_EVERY);
    $hist = [];
    $first = null;
    $bin = $g['fs'] === 'zfs' && isset($paths['zpool']) ? (str_contains($paths['zpool'], '/') ? $paths['zpool'] : bin($paths['zpool'])) : null;
    if ($bin !== null) {
        $names = [];
        foreach ($g['list'] as $x) {
            for ($ds = (string) ($x['ds'] ?? strstr((string) $x['name'], '@', true)); $ds !== '' && $ds !== '.'; $ds = dirname($ds)) {
                $names[$ds] = true;
            }
        }
        static $read = [];              // a pool's history once per round (gone and released ask both)
        $out = $read["$bin|{$g['where']}|$now"] ??= (string) run([$bin, 'history', '-l', (string) $g['where']], 60)[1];
        $read = array_slice($read, -8, null, true);
        foreach (explode("\n", $out) as $line) {
            if (!preg_match('/^(\d{4}-\d\d-\d\d)\.(\d\d:\d\d:\d\d) ((?:zfs|zpool) (?:' . $verbs . ')\b.*)$/', $line, $m)) {
                continue;
            }
            $t = strtotime("$m[1] $m[2]");
            if ($t === false || $t < $from - 60 || $t > $now + 60) {
                continue;
            }
            $hit = false;
            foreach (array_keys($names) as $ds) {
                $hit = $hit || str_contains($m[3], " $ds@") || preg_match('/ ' . preg_quote((string) $ds, '/') . '(?:\s|$)/', $m[3]) === 1;
            }
            if ($hit) {
                $first ??= $t;
                $hist[] = mb_strimwidth(watchmanClean(watchmanScrub("$m[1] $m[2] $m[3]"), 400), 0, 220, '…');
            }
        }
    }
    $hist = array_slice($hist, -6);
    $t = $first ?? intdiv($from + $now, 2);
    $span = $first !== null ? WATCH_EVIDENCE_SPAN : max(WATCH_EVIDENCE_SPAN, min(900, intdiv($now - $from, 2) + 30));
    $evidence = isset($paths['syslog']) ? watchmanSyslogAround($paths['syslog'], $t, $now, WATCH_SNAP_EVIDENCE, $span) : [];
    return ['history' => $hist, 'evidence' => $evidence];
}

/**
 * Inside the book's lock: what went and what lost its hold, as entries — one open entry per pool or disk,
 * a later round adds to it (count = how many snapshots). A learned series is left out again (an «I know,
 * thanks» may have come since the look).
 *
 * @return list<string>  the kinds of new entries
 */
function watchmanSnapCompare(array &$bs, array $res, array &$book, int $now): array
{
    $series = (array) ($bs['series'] ?? []);
    $added = [];
    foreach ($res['gone'] as $g) {
        $list = watchmanSnapUnlearned($g['list'], $series);
        if (!$list) {
            continue;
        }
        $added[] = watchmanBump($book, 'snap_gone', "snap_gone:{$g['fs']}:{$g['where']}", $now, count($list), watchmanSnapWords($g, $list));
    }
    foreach ($res['released'] as $g) {
        $added[] = watchmanBump($book, 'snap_hold_released', "snap_hold_released:{$g['fs']}:{$g['where']}", $now, count($g['list']), watchmanSnapWords($g, $g['list']));
    }
    return array_values(array_filter($added));
}

/** An entry's words: where, the datasets, a few of the snapshots, their series, how many were held, the evidence */
function watchmanSnapWords(array $g, array $list): array
{
    $names = $datasets = $series = [];
    foreach ($list as $x) {
        $name = (string) $x['name'];
        if ($g['fs'] === 'btrfs') {
            $at = strpos($name, '/' . $g['where'] . '/');
            $name = $at === false ? $name : substr($name, $at + 1);           // disk1/.btrfs-snap/20261006-0100
        } else {
            $datasets[(string) ($x['ds'] ?? '')] = true;
        }
        $names[] = watchmanClean($name, 200);
        $series[(string) ($x['series'] ?? '')] = true;
    }
    return ['where' => (string) $g['where'], 'fs' => (string) $g['fs'],
            'datasets' => array_slice(array_map('strval', array_keys(array_filter($datasets, fn ($k) => $k !== '', ARRAY_FILTER_USE_KEY))), 0, WATCH_LIST_MAX),
            'names' => array_slice($names, 0, WATCH_LIST_MAX),
            'series' => array_slice(array_map('strval', array_keys(array_filter($series, fn ($k) => $k !== '', ARRAY_FILTER_USE_KEY))), 0, WATCH_LIST_MAX),
            'held' => count(array_filter($list, fn ($x) => !empty($x['held']))),
            'history' => array_values((array) ($g['history'] ?? [])), 'evidence' => array_values((array) ($g['evidence'] ?? []))];
}

/** "What I keep an eye on" of the snapshots: per pool and disk how many, which sleep, the learned series */
function watchmanSnapSummary(?array $s, ?array $bs): ?array
{
    if (!is_array($s)) {
        return null;
    }
    return ['time' => (int) ($s['time'] ?? 0), 'zfs' => $s['zfs'] ?? null, 'btrfs' => $s['btrfs'] ?? null, 'asleep' => (array) ($s['asleep'] ?? []),
            'capped' => (array) ($s['capped'] ?? []), 'expected' => (array) ($s['expected'] ?? []),
            'series' => array_slice(array_map('strval', array_keys((array) ($bs['series'] ?? []))), 0, 50)];
}

// ===================================================================== «I know, thanks»

/**
 * The state an entry tells of becomes normal: an address known, a container's
 * new rights, a plugin and its source, go, a file, a user, a password, a key,
 * a share open to guests — as the last round saw it ($seen). What is gone
 * meanwhile isn't adopted (if it comes back, it is told again).
 */
function watchmanAdopt(array &$b, array $e, array $seen, int $now): void
{
    $p = (array) ($e['p'] ?? []);
    $kind = (string) $e['kind'];
    $name = (string) ($p['name'] ?? '');
    switch ($kind) {
        case 'login_new_ip':
            $k = $b['ips'][$p['ip']] ?? ['first' => (int) $e['time'], 'last' => 0, 'users' => [], 'services' => []];
            $k['last'] = max((int) $k['last'], (int) $e['last']);
            $k['users'] = watchmanMerge(['x' => (array) $k['users']], ['x' => (array) ($p['users'] ?? [])])['x'];
            $k['services'] = watchmanMerge(['x' => (array) $k['services']], ['x' => (array) ($p['services'] ?? [])])['x'];
            $b['ips'][$p['ip']] = $k;
            break;
        case 'login_failures':
            $b['fail_ips'][$p['ip']] = ['since' => $now, 'last' => (int) $e['last'], 'n' => (int) $e['count'], 'quiet' => 0];
            break;
        case 'container_new':
            $b['containers'] ??= [];
            if (isset($seen['containers'][$name])) {
                $b['containers'][$name] = ['tokens' => (array) $seen['containers'][$name]['tokens'], 'seen' => $now];
            }
            break;
        case 'container_privileged':
        case 'container_host':
        case 'container_ports':
        case 'container_rights':
            $b['containers'] ??= [];
            $current = (array) ($seen['containers'][$name]['tokens'] ?? []);
            $was = (array) ($b['containers'][$name]['tokens'] ?? []);
            $tokens = array_values(array_unique(array_merge($was, array_intersect((array) ($p['tokens'] ?? []), $current))));
            sort($tokens);
            $b['containers'][$name] = ['tokens' => $tokens, 'seen' => $now];
            break;
        case 'plugin_new':
        case 'plugin_source':
            if (isset($seen['plugins'][$name])) {
                $b['plugins'][$name] = $seen['plugins'][$name] + ['seen' => $now];
            }
            break;
        case 'flash_go':
            $b['flash']['go'] = $seen['flash']['go'] ?? null;
            break;
        case 'flash_extra':
            $file = (string) ($p['file'] ?? '');
            if (isset($seen['flash']['extra'][$file])) {
                $b['flash']['extra'][$file] = $seen['flash']['extra'][$file];
            } else {
                unset($b['flash']['extra'][$file]);
            }
            break;
        case 'flash_user':
        case 'flash_password':
            $u = (string) ($p['user'] ?? '');
            if (in_array($u, (array) ($seen['flash']['users'] ?? []), true)) {
                $b['flash']['users'] = array_values(array_unique(array_merge((array) ($b['flash']['users'] ?? []), [$u])));
                $b['flash']['pw'][$u] = $seen['flash']['pw'][$u] ?? null;
            }
            break;
        case 'flash_ssh_key':
            $k = $seen['flash']['keys'][$p['user'] ?? ''][$p['fp'] ?? ''] ?? null;
            if ($k !== null) {
                $b['flash']['keys'][$p['user']][$p['fp']] = $k;
            }
            break;
        case 'share_public':
            $share = (string) ($p['share'] ?? '');
            $proto = ($p['proto'] ?? '') === 'nfs' ? 'nfs' : 'smb';
            $b['shares'] ??= [];
            $b['shares'][$share] ??= ['smb' => 0, 'nfs' => 0, 'seen' => $now];
            $b['shares'][$share][$proto] = (int) ($seen['shares'][$share][$proto] ?? 0);
            break;
        case 'flow_client':
        case 'flow_container':
        case 'flow_written':
        case 'flow_gone':
        case 'smb_user':
        case 'smb_client':
        case 'smb_hour':
            watchmanFlowAdopt($b, $e, $now);
            break;
        case 'snap_gone':
            // snapshots named like these may go from now on — while a newer one of the same series stays (watchmanSnapLeft())
            $b['snaps'] = is_array($b['snaps'] ?? null) ? $b['snaps'] : ['series' => []];
            foreach ((array) ($p['series'] ?? []) as $series) {
                if (is_string($series) && $series !== '' && strlen($series) <= 255) {
                    $b['snaps']['series'][$series] = $now;
                }
            }
            if (count($b['snaps']['series']) > WATCH_SNAP_SERIES) {
                arsort($b['snaps']['series']);
                $b['snaps']['series'] = array_slice($b['snaps']['series'], 0, WATCH_SNAP_SERIES, true);
            }
            break;
        case 'user_ram':
            // this account as it is now is normal (a second root too, once you know it)
            $u = $seen['host']['users'][$p['user'] ?? ''] ?? null;
            if (is_array($u) && is_array($b['host']['users'] ?? null)) {
                $b['host']['users'][(string) $p['user']] = $u;
            }
            break;
        case 'door_new':
            $d = $seen['host']['doors'][$p['key'] ?? ''] ?? null;
            if (is_array($d) && is_array($b['host']['doors'] ?? null)) {
                $b['host']['doors'][(string) $p['key']] = $d;
            }
            break;
        case 'api_key_new':
        case 'api_key_changed':
            // this key with the rights it has now is wanted (more later is told again)
            $k = $seen['host']['api'][$p['id'] ?? ''] ?? null;
            if (is_array($k) && is_array($b['host']['api'] ?? null)) {
                $b['host']['api'][(string) $p['id']] = $k;
            }
            break;
        case 'door_changed':
            // the pair's line as it is now is wanted (a change after this is told again)
            $l = $seen['partner']['lines'][$p['id'] ?? ''] ?? null;
            if (is_array($l)) {
                $b['partner'] = watchmanPartnerBase($b['partner'] ?? null);
                $b['partner']['lines'][(string) $p['id']] = watchmanPartnerLineKeep($l);
            }
            break;
        case 'door_key_moved':
            // this address with this key is fine (another pair of them is told again); the address a known login address
            if (is_string($p['ip'] ?? null)) {
                $b['partner'] = watchmanPartnerBase($b['partner'] ?? null);
                $b['partner']['logins'][$p['ip'] . '|' . (string) ($p['fp'] ?? '')] = $now;
                $k = $b['ips'][$p['ip']] ?? ['first' => (int) $e['time'], 'last' => 0, 'users' => [], 'services' => []];
                $k['last'] = max((int) $k['last'], (int) $e['last']);
                $k['users'] = watchmanMerge(['x' => (array) $k['users']], ['x' => (array) ($p['users'] ?? [])])['x'];
                $k['services'] = watchmanMerge(['x' => (array) $k['services']], ['x' => ['ssh:publickey']])['x'];
                $b['ips'][$p['ip']] = $k;
            }
            break;
        case 'listen_new':
        case 'proc_odd':
            $part = $kind === 'listen_new' ? 'listen' : 'procs';
            if (is_string($p['key'] ?? null) && is_array($b['host'][$part] ?? null)) {
                $b['host'][$part][$p['key']] = $now;
            }
            break;
        default:
            // log_cleared: something that happened — nothing to adopt
            if ((WATCH_KINDS[$kind][0] ?? '') === 'sched') {
                watchmanSchedAdopt($b, $kind, $p, $seen);
            } elseif ((WATCH_KINDS[$kind][0] ?? '') === 'net') {
                watchnetAdopt($b, $e, $now);       // the network (agent/lib/watchnet.php)
            }
    }
}

/**
 * «I know, thanks» on the data flow: that much is normal for this client, container or share from
 * now on (its normal raised to the most it pulled in an hour, never lowered); the SMB user, the
 * machine, the hours are known.
 */
function watchmanFlowAdopt(array &$b, array $e, int $now): void
{
    if (!is_array($b['flow'] ?? null)) {
        return;                     // the data flow was started anew meanwhile: nothing to raise
    }
    $p = (array) ($e['p'] ?? []);
    $kind = (string) $e['kind'];
    $f = &$b['flow'];
    switch ($kind) {
        case 'flow_client':
        case 'flow_container':
        case 'flow_written':
        case 'flow_gone':
            $key = (string) $e['key'];
            $f['ack'][$key] = ['bytes' => max((int) ($f['ack'][$key]['bytes'] ?? 0), (int) ($p['peak'] ?? 0)), 'time' => $now];
            break;
        case 'smb_user':
            $f['smb_users'][(string) ($p['user'] ?? '?')] = $now;
            break;
        case 'smb_client':
        case 'smb_hour':
            $ip = (string) ($p['ip'] ?? '');
            if ($ip === '') {
                break;
            }
            $c = is_array($f['smb_clients'][$ip] ?? null) ? $f['smb_clients'][$ip] : ['first' => $now, 'last' => $now, 'hours' => [], 'name' => (string) ($p['machine'] ?? '')];
            $hours = array_values(array_unique(array_map('intval', array_merge((array) $c['hours'], (array) ($p['hours'] ?? [])))));
            sort($hours);
            $c['hours'] = $hours;
            $f['smb_clients'][$ip] = $c;
            break;
    }
}

/**
 * «I know, thanks» for one entry (its id), several (a list of ids — those still open; none of them open: watch_gone) or
 * all open ones ('*')
 */
function watchmanAck(mixed $id, ?string $dir = null, ?int $now = null, bool $page = true): array
{
    $all = $id === '*';
    $ids = is_array($id) ? array_values($id) : [$id];
    if (!$all && (!$ids || count($ids) > WATCH_BOOK_MAX || array_filter($ids, fn ($x) => !is_string($x) || !preg_match(WATCH_ID, $x)))) {
        throw new Problem('bad_request');
    }
    $id = array_flip($ids);
    $dir ??= watchmanDir();
    $now ??= time();
    $noted = watchmanLocked($dir, function () use ($dir, $id, $all, $now): array {
        $d = watchmanLoad($dir);
        if (!is_array($d['baseline'])) {
            throw new Problem('watch_gone');
        }
        $seen = readJson("$dir/seen.json") ?? [];
        $b = $d['baseline'];
        $book = $d['book'];
        $noted = [];
        foreach ($book as $i => $e) {
            if (!watchmanOpen($e) || (!$all && !isset($id[$e['id']]))) {
                continue;
            }
            watchmanAdopt($b, $e, $seen, $now);
            $book[$i]['noted'] = $now;
            $book[$i]['by'] = 'page';
            $noted[] = $e['kind'];
        }
        if (!$noted && !$all) {
            throw new Problem('watch_gone');
        }
        $st = $d['state'];
        $st['open'] = watchmanOpenCounts($book);
        watchmanSave($dir, $d, ['baseline' => $b, 'book' => $book, 'state' => $st]);
        return $noted;
    });
    if (!$page) {
        return ['ok' => true, 'noted' => count($noted)];
    }
    if ($noted) {
        logLine('Night watchman: ' . count($noted) . ' noted («I know, thanks»): ' . implode(', ', array_unique($noted)));
        watchmanMirrorKeep($dir);
    }
    return ['ok' => true, 'noted' => count($noted), 'state' => watchmanPageState()];
}

/**
 * What the team lead put aside («I know, thanks» on one of his findings)
 * counts here too: every entry that finding stood for is noted. Only while
 * the finding is still the same (its signature): a newer entry since makes it
 * another one.
 */
function watchmanTeamLeadNotes(array &$b, array &$book, array $seen, int $now, ?string $acksFile = null): void
{
    if (!function_exists('caretakerAckRead') || !function_exists('caretakerAckSig') || !function_exists('caretakerAckFile')) {
        return;
    }
    $acks = caretakerAckRead($acksFile ?? caretakerAckFile());
    if (!$acks) {
        return;
    }
    foreach (watchmanFindings($book) as $f) {
        if (!isset($acks[caretakerAckSig('watchman', $f)])) {
            continue;
        }
        foreach ($book as $i => $e) {
            if (watchmanOpen($e) && $e['kind'] === $f['id']) {
                watchmanAdopt($b, $e, $seen, $now);
                $book[$i]['noted'] = $now;
                $book[$i]['by'] = 'teamlead';
            }
        }
    }
}

// ===================================================================== texts, the team lead, notifications

/**
 * The words an entry's texts take (entry.<kind>, check.<kind>, notify.<kind>):
 * only names, addresses and numbers — the same in every language; with $lang
 * (notifications) also the data flow's "what is normal" in that language.
 */
function watchmanText(array $e, ?string $lang = null): array
{
    $p = (array) ($e['p'] ?? []);
    $services = implode(', ', watchmanServiceNames((array) ($p['services'] ?? [])));
    $list = fn (string $k) => implode(', ', array_map('strval', (array) ($p[$k] ?? [])));
    $few = array_map('strval', array_slice((array) ($p['jobs'] ?? []), 0, 3));
    $jobs = implode(' · ', $few) . ((int) ($p['lines'] ?? 0) > count($few) ? ' · …' : '');
    return match ((string) $e['kind']) {
        'login_new_ip'   => ['ip' => (string) ($p['ip'] ?? ''), 'user' => $list('users') ?: '?', 'service' => $services],
        'login_failures' => ['ip' => (string) ($p['ip'] ?? ''), 'service' => $services],
        'container_new', 'container_privileged', 'container_host', 'container_ports', 'container_rights'
                         => ['name' => (string) ($p['name'] ?? ''), 'rights' => implode(' ', (array) ($p['tokens'] ?? []))],
        'drill_throwaway' => ['names' => $list('names'), 'id' => (string) ($p['id'] ?? '')],
        'plugin_new'     => ['name' => (string) ($p['name'] ?? ''), 'source' => (string) ($p['source'] ?? '') ?: '–'],
        'plugin_source'  => ['name' => (string) ($p['name'] ?? ''), 'source' => (string) ($p['source'] ?? '') ?: '–', 'old' => (string) ($p['old'] ?? '') ?: '–'],
        'flash_go'       => ['added' => (int) ($p['added'] ?? 0), 'removed' => (int) ($p['removed'] ?? 0)],
        'flash_extra'    => ['file' => (string) ($p['file'] ?? '')],
        'flash_user', 'flash_password' => ['user' => (string) ($p['user'] ?? '')],
        'flash_ssh_key'  => ['user' => (string) ($p['user'] ?? ''), 'key' => (string) ($p['comment'] ?? '') ?: substr((string) ($p['fp'] ?? ''), 0, 19)],
        'share_public'   => ['share' => (string) ($p['share'] ?? ''), 'proto' => strtoupper((string) ($p['proto'] ?? ''))],
        'cron_new', 'cron_twice', 'cron_office'
                         => ['lines' => (int) ($p['lines'] ?? 0), 'jobs' => $jobs, 'fix' => watchmanCronFix((string) $e['kind'])],
        'cron_file', 'cron_file_foreign'
                         => ['file' => (string) ($p['file'] ?? ''), 'plugin' => (string) ($p['plugin'] ?? ''), 'lines' => (int) ($p['lines'] ?? 0), 'jobs' => $jobs],
        'script_new', 'script_changed' => ['name' => (string) ($p['name'] ?? ''), 'cron' => (string) ($p['cron'] ?? '')],
        'at_job'         => ['cmd' => (string) ($p['cmd'] ?? '') ?: '?', 'when' => date('Y-m-d H:i', (int) ($p['when'] ?? 0))],
        'at_userscript'  => ['name' => (string) ($p['name'] ?? ''), 'when' => date('Y-m-d H:i', (int) ($p['when'] ?? 0))],
        'at_plugin'      => ['name' => (string) ($p['name'] ?? '') ?: (string) ($p['plugin'] ?? ''), 'file' => (string) ($p['file'] ?? '') ?: '?',
                             'when' => date('Y-m-d H:i', (int) ($p['when'] ?? 0))],
        'notify_agent'   => ['name' => (string) ($p['name'] ?? '')],
        'flow_client'    => ['ip' => (string) ($p['ip'] ?? ''), 'service' => WATCH_FLOW_SERVICES[$p['service'] ?? ''] ?? (string) ($p['service'] ?? ''),
                             'size' => watchmanSize((int) ($p['bytes'] ?? 0), $lang ?? 'en'), 'minutes' => (int) ($p['minutes'] ?? 0),
                             'usual' => watchmanFlowUsualText($p, 'flow_client', $lang)],
        'flow_container' => ['name' => (string) ($p['name'] ?? ''), 'size' => watchmanSize((int) ($p['bytes'] ?? 0), $lang ?? 'en'),
                             'minutes' => (int) ($p['minutes'] ?? 0), 'usual' => watchmanFlowUsualText($p, 'flow_container', $lang)],
        'flow_written'   => ['share' => (string) ($p['share'] ?? ''), 'size' => watchmanSize((int) ($p['bytes'] ?? 0), $lang ?? 'en'),
                             'minutes' => (int) ($p['minutes'] ?? 0), 'usual' => watchmanFlowUsualText($p, 'flow_written', $lang),
                             'from' => watchmanFlowFrom($p, $lang)],
        'flow_gone'      => ['share' => watchmanGoneName($p), 'size' => watchmanSize((int) ($p['bytes'] ?? 0), $lang ?? 'en'),
                             'minutes' => (int) ($p['minutes'] ?? 0), 'usual' => watchmanFlowUsualText($p, 'flow_gone', $lang),
                             'more' => watchmanGoneMore($p, $lang)],
        'smb_user'       => ['user' => (string) ($p['user'] ?? ''), 'ip' => (string) ($p['ip'] ?? '')],
        'smb_client'     => ['ip' => (string) ($p['ip'] ?? ''), 'user' => $list('users') ?: '?'],
        'smb_hour'       => ['ip' => (string) ($p['ip'] ?? ''), 'user' => $list('users') ?: '?', 'hours' => watchmanHourNames((array) ($p['hours'] ?? []))],
        'snap_gone', 'snap_hold_released'
                         => ['where' => (string) ($p['where'] ?? ''), 'names' => watchmanNames((array) ($p['names'] ?? []), 3),
                             'datasets' => watchmanNames((array) ($p['datasets'] ?? []), 3) ?: (string) ($p['where'] ?? '')],
        'log_cleared'    => ['log' => (string) ($p['log'] ?? '')],
        'user_ram'       => ['user' => (string) ($p['user'] ?? ''), 'uid' => (int) ($p['uid'] ?? 0)],
        'listen_new'     => ['prog' => (string) ($p['prog'] ?? ''), 'port' => isset($p['port']) ? (string) (int) $p['port'] : '*'],
        'proc_odd'       => ['prog' => (string) ($p['prog'] ?? '') ?: '?', 'exe' => (string) ($p['exe'] ?? ''),
                             'where' => ($p['where'] ?? null) === null
                                 ? ($lang === null ? '' : officeNotifyText('watchman', 'where.host', [], $lang)) : (string) $p['where']],
        'door_new'       => ['door' => watchmanDoorWords($p, $lang), 'name' => (string) ($p['name'] ?? '')],
        'api_key_new', 'api_key_changed'
                         => ['name' => (string) ($p['name'] ?? ''), 'roles' => $list('roles') ?: '–', 'perms' => (int) ($p['perms'] ?? 0)],
        'array_stop', 'array_start', 'server_boot' => ['who' => watchmanArrayWho((array) ($p['logins'] ?? []))],
        'parity_check'   => ['why' => $lang === null ? '' : paritywhyWhy($p, $lang)],     // the page words it itself (desk.js parityWhy())
        'partner_paired' => ['name' => (string) ($p['name'] ?? ''), 'address' => (string) ($p['address'] ?? '')],
        'door_changed'   => ['name' => (string) ($p['name'] ?? ''), 'what' => $lang === null ? '' : implode(', ', array_map(
                                 fn ($w) => officeNotifyText('watchman', WATCH_DOOR_WHAT[$w] ?? 'door_what.options', [], $lang), (array) ($p['what'] ?? [])))],
        'door_key_moved' => ['name' => (string) ($p['name'] ?? ''), 'ip' => (string) ($p['ip'] ?? '')],
        'door_refused'   => ['name' => (string) ($p['name'] ?? '')],
        'watch'          => isset($p['too_much']) ? watchnetText(['kind' => 'net_too_much', 'p' => $p])
                              : ($lang === null ? array_map('intval', $p) : watchmanCountWords($p, $lang)),     // the page words the numbers itself
        default          => watchnetText($e, $lang),     // the network (agent/lib/watchnet.php); [] for anything else
    };
}

/** A way in, in words: in $lang (notifications, the team lead), or '' — the page writes it itself (door.<what>) */
function watchmanDoorWords(array $p, ?string $lang): string
{
    if ($lang === null) {
        return '';
    }
    $what = in_array($p['door'] ?? null, ['ssh', 'upnp', 'connect', 'oidc', 'wg', 'api_sandbox', 'api_origin', 'api_sso'], true) ? $p['door'] : 'ssh';
    return officeNotifyText('watchman', "door.$what", ['name' => (string) ($p['name'] ?? ''),
        'port' => (string) ($p['port'] ?? '–'), 'issuer' => (string) ($p['issuer'] ?? ''), 'n' => (int) ($p['new_peers'] ?? 0)], $lang);
}

/**
 * The user share a ZFS dataset belongs to: <pool>/<share>/…; a share put whole into Ms. Dustdevil's
 * storeroom (<pool>/<storeroom>-<stamp>-<share>/…, either name of it) still counts for it. Null: the pool's top.
 */
function watchmanGoneShare(string $ds): ?string
{
    $p = explode('/', $ds, 3);
    if (count($p) < 2) {
        return null;
    }
    if (storeroomName($p[1])) {
        return preg_match('/^[._]UnraidSecretaryOffice-trash-\d{8}-\d{6}(?:-\d+)?-(.+)$/D', $p[1], $m) ? $m[1] : null;
    }
    return $p[1];
}

/** Where something vanished, in a few words: the share, or the disk with the shares that have a folder there ("disk3 (Filme, Serien)") */
function watchmanGoneName(array $p): string
{
    if (($p['disk'] ?? null) === null) {
        return (string) ($p['share'] ?? '');
    }
    $shares = array_map('strval', array_slice((array) ($p['shares'] ?? []), 0, 3));
    return (string) $p['disk'] . ($shares ? ' (' . implode(', ', $shares) . (count((array) $p['shares']) > 3 ? ', …' : '') . ')' : '');
}

/**
 * What else an entry of vanished data says, in words of $lang (null: left out — the page writes it
 * itself): how much its snapshots still hold, who moved data over SMB, NFS or SSH then — or nobody, so
 * it came from the server itself —, the SMB users connected, a disk measured as a whole, pools asleep.
 */
function watchmanGoneMore(array $p, ?string $lang): string
{
    if ($lang === null) {
        return '';
    }
    $t = fn (string $k, array $x = []) => officeNotifyText('watchman', $k, $x, $lang);
    $out = [];
    if ((int) ($p['kept'] ?? 0) > 0) {
        $out[] = $t('gone.kept', ['size' => watchmanSize((int) $p['kept'], $lang)]);
    }
    if (($p['from'] ?? null) === 'clients') {
        $out[] = $t('gone.who_clients', ['list' => implode(', ', array_map('strval', (array) ($p['clients'] ?? [])))]);
    } elseif (($p['from'] ?? null) === 'server') {
        $out[] = $t('gone.who_server');
    }
    if ((array) ($p['users'] ?? [])) {
        $out[] = $t('gone.who_smb', ['users' => implode(', ', array_map('strval', (array) $p['users']))]);
    }
    if (($p['disk'] ?? null) !== null) {
        $out[] = $t('gone.disk', ['fs' => (string) ($p['fs'] ?? '')]);
    }
    if ((array) ($p['asleep'] ?? [])) {
        $out[] = $t('gone.asleep', ['pools' => implode(', ', array_map('strval', (array) $p['asleep']))]);
    }
    return $out ? ' ' . implode(' ', $out) : '';
}

/** "SSH (publickey)", "WebGUI" — names, not words */
function watchmanServiceName(string $s): string
{
    if ($s === 'web') {
        return 'WebGUI';
    }
    return str_starts_with($s, 'ssh:') ? 'SSH (' . substr($s, 4) . ')' : 'SSH';
}

/** The names of a list of services; a bare "SSH" (an invalid user, no method) only when no SSH method is named */
function watchmanServiceNames(array $services): array
{
    $names = array_values(array_unique(array_map(fn ($s) => watchmanServiceName((string) $s), $services)));
    $methods = array_filter($names, fn ($n) => str_starts_with($n, 'SSH ('));
    return array_values(array_filter($names, fn ($n) => $n !== 'SSH' || !$methods));
}

/**
 * For the team lead: one recommended finding per kind with open entries —
 * how many, and the newest's words (another entry, another finding: his «I
 * know, thanks» then counts for what he saw).
 */
function watchmanFindings(array $book): array
{
    $by = [];
    foreach ($book as $e) {
        if (watchmanOpen($e)) {
            $by[$e['kind']][] = $e;
        }
    }
    $out = [];
    foreach (array_keys(WATCH_KINDS) as $kind) {
        if (empty($by[$kind])) {
            continue;
        }
        $list = $by[$kind];
        usort($list, fn ($a, $b) => [(int) $a['last'], $a['id']] <=> [(int) $b['last'], $b['id']]);
        // what moves while a pull goes on stays out (his «I know, thanks» there must hold); a size may grow (CARETAKER_ACK_DRIFT)
        $out[] = finding($kind, 'recommended', false, ['n' => count($list)] + array_diff_key(watchmanText(end($list)), ['minutes' => 1, 'usual' => 1]), '#/watchman');
    }
    return $out;
}

/**
 * For the team lead: his findings (one per kind with open entries, or "quiet"), and — good to know, never
 * a finding — how many of his security advice (posture tips you haven't noted) there are.
 */
function watchmanChecks(?string $dir = null): array
{
    $dir ??= watchmanDir();
    $d = watchmanLoad($dir);
    if (!is_array($d['baseline'])) {
        return [];                  // his first round is still to come
    }
    $out = watchmanFindings($d['book']) ?: [finding('quiet', 'recommended', true, [], '#/watchman')];
    $posture = watchmanPosturePage($d['state']['posture'] ?? null, readJson("$dir/posture.json"));
    if ($posture !== null && $posture['open'] > 0) {
        $out[] = finding('posture', 'hint', null, ['n' => $posture['open']], '#/watchman');
    }
    return $out;
}

/** The team lead's to-do after an unclean stop (agent/lib/paritywhy.php) — his state file only; null: none, or not on watch */
function watchmanParityFinding(?string $dir = null): ?array
{
    $d = watchmanLoad($dir ?? watchmanDir());
    return is_array($d['baseline']) ? paritywhyFinding($d['state']) : null;
}

/**
 * The important kinds go to Unraid's notifications right away — per kind
 * one message for what is new, then quiet for WATCH_NOTIFY_QUIET (what comes
 * meanwhile is told together after it). Noted entries are never told.
 * @return list<array{kind: string, n: int, sent: bool}>
 */
function watchmanNotifyDue(array &$book, array &$st, int $now, bool $send, ?string $lang = null): array
{
    $told = [];
    $on = ($st['notify'] ?? true) !== false;
    foreach (WATCH_KINDS as $kind => [, $important]) {
        $new = [];
        foreach ($book as $i => $e) {
            if ($e['kind'] === $kind && ($important || !empty($e['important'])) && watchmanOpen($e) && empty($e['told']) && empty($e['muted'])) {
                $new[] = $i;
            }
        }
        if (!$important && !$new) {
            continue;
        }
        if (!$on) {
            foreach ($new as $i) {
                $book[$i]['muted'] = $now;      // switched off: never told, also not once switched on again
            }
            continue;
        }
        if (!$new || $now - (int) ($st['notified'][$kind] ?? 0) < WATCH_NOTIFY_QUIET) {
            continue;
        }
        $sent = $send && watchmanNotifySend($kind, array_map(fn ($i) => $book[$i], $new), $lang ?? officeNotifyLang());
        foreach ($new as $i) {
            $book[$i]['told'] = $now;
        }
        $st['notified'][$kind] = $now;
        $told[] = ['kind' => $kind, 'n' => count($new), 'sent' => $sent];
    }
    if ($told) {
        $st['last_notify'] = ['time' => $now, 'items' => $told];
    }
    return $told;
}

/** The switch on his page (like the team lead's): report to Unraid's notifications or not — default on */
function watchmanNotifySet(mixed $on, ?string $dir = null, bool $page = true): array
{
    if (!is_bool($on)) {
        throw new Problem('bad_request');
    }
    $dir ??= watchmanDir();
    watchmanLocked($dir, function () use ($dir, $on): void {
        $d = watchmanLoad($dir);
        $st = $d['state'];
        $st['notify'] = $on;
        watchmanSave($dir, $d, ['state' => $st]);
    });
    if (!$page) {
        return ['ok' => true];
    }
    logLine("Night watchman: reports to Unraid's notifications " . ($on ? 'on' : 'off'));
    watchmanMirrorKeep($dir);
    return ['ok' => true, 'state' => watchmanPageState()];
}

/**
 * For a SIEM: each new entry of his book as one line in Unraid's syslog (tag uso-watchman, JSON: id, kind, group, the
 * ATT&CK technique, important, noted by himself or not, the time, the entry in English words) — Unraid's Settings →
 * Syslog Server → Remote syslog server sends it on to Wazuh, Graylog, Splunk, Elastic … Off by default
 * (`syslog_set`). Never more than the book says: no hashes, no evidence lines, at most WATCH_SYSLOG_MAX a round.
 */
const WATCH_SYSLOG_MAX = 20;
const WATCH_SYSLOG_OWN = '/\suso-watchman(?:\[\d+\])?:/';

function watchmanSyslogLine(array $e): string
{
    $kind = (string) $e['kind'];
    $text = officeNotifyText('watchman', "entry.$kind", ['n' => (int) ($e['count'] ?? 1)] + watchmanText($e, 'en'), 'en');
    return jsonEncode(['v' => 1, 'id' => (string) $e['id'], 'kind' => $kind, 'group' => WATCH_KINDS[$kind][0] ?? 'watch',
        'attack' => WATCH_ATTACK[$kind] ?? null, 'important' => (bool) (WATCH_KINDS[$kind][1] ?? false), 'noted' => $e['by'] ?? null,
        'time' => date('c', (int) ($e['time'] ?? time())), 'text' => mb_strimwidth(watchmanClean($text, 1200), 0, 700, '…')]);
}

function watchmanSyslogForward(string $logger, array $entries, array $chains = []): void
{
    foreach (array_slice($entries, 0, WATCH_SYSLOG_MAX) as $e) {
        run([$logger, '-t', 'uso-watchman', '-p', 'user.notice', '--', watchmanSyslogLine($e)], 5);
    }
    foreach ($chains as $c) {               // a chain that formed: the incident, with the ids of its entries
        run([$logger, '-t', 'uso-watchman', '-p', 'user.warning', '--', watchmanSyslogChainLine($c)], 5);
    }
}

/** A chain for a SIEM: its entries' ids and groups, in English words */
function watchmanSyslogChainLine(array $c): string
{
    $ids = array_values(array_map('strval', (array) ($c['ids'] ?? [])));
    return jsonEncode(['v' => 1, 'kind' => 'chain', 'ids' => array_slice($ids, 0, 20), 'groups' => array_values((array) ($c['groups'] ?? [])),
        'important' => true, 'time' => date('c'), 'text' => officeNotifyText('watchman', 'notify.chain', ['n' => count($ids)], 'en')]);
}

/**
 * The «whole LAN» switch (the network): per device on the LAN how often it connected and how many security detections
 * named it — counts only, never a destination, an address or a time; off by default, and its counts go when switched off.
 */
function watchmanNetLanSet(mixed $on, ?string $dir = null, bool $page = true): array
{
    if (!is_bool($on)) {
        throw new Problem('bad_request');
    }
    $dir ??= watchmanDir();
    watchmanLocked($dir, function () use ($dir, $on): void {
        $d = watchmanLoad($dir);
        $st = $d['state'];
        $st['net_lan'] = $on;
        watchmanSave($dir, $d, ['state' => $st]);
    });
    if (!$page) {
        return ['ok' => true];
    }
    logLine('Night watchman: the whole LAN (per-device counts) ' . ($on ? 'on' : 'off'));
    return ['ok' => true, 'state' => watchmanPageState()];
}

/** The switch on his page: also write the book's new entries to the syslog, for a SIEM — default off */
function watchmanSyslogSet(mixed $on, ?string $dir = null, bool $page = true): array
{
    if (!is_bool($on)) {
        throw new Problem('bad_request');
    }
    $dir ??= watchmanDir();
    watchmanLocked($dir, function () use ($dir, $on): void {
        $d = watchmanLoad($dir);
        $st = $d['state'];
        $st['siem'] = $on;
        watchmanSave($dir, $d, ['state' => $st]);
    });
    if (!$page) {
        return ['ok' => true];
    }
    logLine('Night watchman: new entries to the syslog (for a SIEM) ' . ($on ? 'on' : 'off'));
    watchmanMirrorKeep($dir);
    return ['ok' => true, 'state' => watchmanPageState()];
}

/*
 * What may belong together (a SOC's correlation): important entries not noted yet, of at least two different
 * groups, each first seen within WATCH_CHAIN_WINDOW of another, that are a way in and something else (a login from a
 * new address and, minutes later, a new cron line or a program listening on a new port) or damage of two sorts
 * (snapshots gone and much written into a share, logs emptied). Each entry is
 * still told by its own kind; a chain is told once more when it forms (one notification, its entries in words).
 * Noting an entry takes it out of every chain.
 */
const WATCH_CHAIN_WINDOW = 3600;            // first seen this close to another entry of the chain
const WATCH_CHAIN_KEEP   = 7 * 86400;       // entries older than this start no chain
// a chain is a way in and something else (a login from a new address, then a cron line), or damage of two sorts (snapshots
// gone and much written) — a plugin installed (its plugin, cron file and port at once) alone is none
const WATCH_CHAIN_ACCESS = ['login_new_ip', 'login_failures', 'smb_user', 'door_new', 'door_key_moved', 'door_refused',
                            'net_router_login', 'net_vpn_login', 'net_new_device', 'net_spoof'];
const WATCH_CHAIN_IMPACT = ['snap_gone' => 'snap', 'snap_hold_released' => 'snap', 'flow_written' => 'flow', 'flow_gone' => 'flow', 'log_cleared' => 'log',
                            'net_blocked_from_server' => 'exfil'];

/**
 * The chains in the book now: list of [key (its first entry's id), ids (oldest first), groups, first, last].
 * @return list<array{key: string, ids: list<string>, groups: list<string>, first: int, last: int}>
 */
function watchmanChains(array $book, int $now): array
{
    $list = array_values(array_filter($book, fn ($e) => watchmanOpen($e) && watchmanImportant($e)
        && (int) $e['time'] >= $now - WATCH_CHAIN_KEEP));
    usort($list, fn ($a, $b) => [(int) $a['time'], $a['id']] <=> [(int) $b['time'], $b['id']]);
    $chains = [];
    $cur = [];
    foreach ($list as $e) {
        if ($cur && (int) $e['time'] - (int) end($cur)['time'] > WATCH_CHAIN_WINDOW) {
            $chains[] = $cur;
            $cur = [];
        }
        $cur[] = $e;
    }
    if ($cur) {
        $chains[] = $cur;
    }
    $out = [];
    foreach ($chains as $c) {
        $groups = array_values(array_unique(array_map(fn ($e) => WATCH_KINDS[$e['kind']][0], $c)));
        $access = array_filter($c, fn ($e) => in_array($e['kind'], WATCH_CHAIN_ACCESS, true));
        $other = array_filter($c, fn ($e) => !in_array($e['kind'], WATCH_CHAIN_ACCESS, true));
        $impact = array_unique(array_filter(array_map(fn ($e) => WATCH_CHAIN_IMPACT[$e['kind']] ?? null, $c)));
        if (count($groups) < 2 || (!($access && $other) && count($impact) < 2)) {
            continue;
        }
        $out[] = ['key' => (string) $c[0]['id'], 'ids' => array_column($c, 'id'), 'groups' => $groups,
                  'first' => (int) $c[0]['time'], 'last' => (int) end($c)['time']];      // first seen: when each came
    }
    return watchnetChains($book, $out, $now);       // a new device on the LAN and a login from its address (agent/lib/watchnet.php)
}

/** Told to Unraid's notifications and joining chains: the kind's word, or the entry's own (a new device taking a known name) */
function watchmanImportant(array $e): bool
{
    return (bool) (WATCH_KINDS[$e['kind'] ?? ''][1] ?? false) || !empty($e['important']);
}

/**
 * A chain that formed (or grew) since the last round: one notification (the notify switch counts; at most one an
 * hour, like a kind) — state.json chains: key → how many entries were told.
 * @return list<array{kind: string, n: int, sent: bool}>
 */
function watchmanChainsDue(array $book, array &$st, int $now, bool $send, ?string $lang = null): array
{
    $chains = watchmanChains($book, $now);
    $known = (array) ($st['chains'] ?? []);
    $keep = [];
    $told = [];
    $on = ($st['notify'] ?? true) !== false;
    $byId = array_column($book, null, 'id');
    foreach ($chains as $c) {
        $was = (int) ($known[$c['key']] ?? 0);
        $keep[$c['key']] = max($was, $on ? 0 : count($c['ids']));      // switched off: never told later
        if (!$on || count($c['ids']) <= $was || $now - (int) ($st['notified']['chain'] ?? 0) < WATCH_NOTIFY_QUIET) {
            continue;
        }
        $sent = $send && watchmanChainSend(array_map(fn ($id) => $byId[$id], $c['ids']), $c, $lang ?? officeNotifyLang());
        $keep[$c['key']] = count($c['ids']);
        $st['notified']['chain'] = $now;
        $told[] = ['kind' => 'chain', 'n' => 1, 'sent' => $sent, 'ids' => $c['ids'], 'groups' => $c['groups']];     // one chain (of count($c['ids']) entries)
    }
    $st['chains'] = $keep;
    return $told;
}

function watchmanChainSend(array $entries, array $c, string $lang): bool
{
    $lines = [];
    foreach (array_slice($entries, 0, 10) as $e) {
        $lines[] = '• ' . date('H:i', (int) $e['time']) . ' ' . officeNotifyText('watchman', "entry.{$e['kind']}", ['n' => (int) $e['count']] + watchmanText($e, $lang), $lang);
    }
    if (count($entries) > 10) {
        $lines[] = officeNotifyText('watchman', 'notify.more', ['n' => count($entries) - 10], $lang);
    }
    $params = ['n' => count($entries), 'minutes' => max(1, (int) round(($c['last'] - $c['first']) / 60))];
    return officeNotify(
        officeNotifyText('watchman', 'notify.chain', $params, $lang),
        officeNotifyText('watchman', 'chain.text', $params, $lang),
        'warning',
        implode("\n", $lines) . "\n\n" . officeNotifyText('watchman', 'notify.footer', [], $lang),
        officeNotifyLink('#/watchman'),
    );
}

/** One notification for a kind: the bell's line, the newest in its words, each entry with its time */
function watchmanNotifySend(string $kind, array $entries, string $lang): bool
{
    usort($entries, fn ($a, $b) => (int) $b['last'] <=> (int) $a['last']);
    $n = count($entries);
    $params = ['n' => $n] + watchmanText($entries[0], $lang);
    $lines = [];
    foreach (array_slice($entries, 0, 10) as $e) {
        $lines[] = '• ' . officeNotifyText('watchman', "entry.$kind", ['n' => (int) $e['count']] + watchmanText($e, $lang), $lang)
                 . ' — ' . date('Y-m-d H:i', (int) $e['last']);
        $src = $kind === 'flow_written' && is_array($e['p']['src'] ?? null) ? $e['p']['src'] : [];
        if ($src && ($src[0]['t'] ?? '') !== 'none') {         // who wrote it: a section, one source per line
            $lines[] = '  ' . officeNotifyText('watchman', 'src.title', [], $lang);
            foreach ($src as $x) {
                $lines[] = '    – ' . watchmanFlowSourceLine((array) $x, $lang);
            }
        }
    }
    if ($n > 10) {
        $lines[] = officeNotifyText('watchman', 'notify.more', ['n' => $n - 10], $lang);
    }
    return officeNotify(
        officeNotifyText('watchman', "notify.$kind", $params, $lang),
        officeNotifyText('watchman', "check.$kind", $params, $lang),
        'warning',
        implode("\n", $lines) . "\n\n" . officeNotifyText('watchman', 'notify.footer', [], $lang),
        officeNotifyLink('#/watchman'),
    );
}

/** For the office's metrics (Prometheus): open entries per kind, when the last round ended — from his state file only */
function watchmanMetrics(?string $dir = null): array
{
    if ($dir === null && watchmanHiredSince() === null) {
        return [];
    }
    $st = readJson(($dir ?? watchmanDir()) . '/state.json') ?? [];
    $samples = [];
    foreach (array_keys(WATCH_KINDS) as $kind) {
        $samples[] = [['kind' => $kind], (int) ($st['open'][$kind] ?? 0)];
    }
    $last = (int) ($st['round']['time'] ?? 0);
    $flow = (array) ($st['flow'] ?? []);
    $sent = $written = [];
    foreach ((array) ($flow['sent'] ?? []) as $svc => $bytes) {
        if (isset(WATCH_FLOW_SERVICES[$svc])) {
            $sent[] = [['service' => (string) $svc], (int) $bytes];
        }
    }
    foreach (array_slice((array) ($flow['written'] ?? []), 0, WATCH_FLOW_TOP, true) as $share => $bytes) {
        $written[] = [['share' => (string) $share], (int) $bytes];
    }
    return [
        ['name' => 'uso_watchman_open_findings', 'type' => 'gauge', 'help' => 'Night watchman findings not noted yet', 'samples' => $samples],
        ['name' => 'uso_watchman_last_round_timestamp_seconds', 'type' => 'gauge', 'help' => 'When the night watchman last finished a round (unix time)',
         'samples' => $last > 0 ? [[[], $last]] : []],
        ['name' => 'uso_watchman_sent_bytes_total', 'type' => 'counter',
         'help' => 'Bytes the server sent to clients per file service (SMB, NFS, SSH, WebGUI) since the night watchman watches the data flow', 'samples' => $sent],
        ['name' => 'uso_watchman_written_bytes_total', 'type' => 'counter',
         'help' => 'Bytes written into ZFS shares since the night watchman watches the data flow (the ' . WATCH_FLOW_TOP . ' shares with the most)', 'samples' => $written],
        ...watchnetMetrics($st, readJson(($dir ?? watchmanDir()) . '/net.json')),      // the network (agent/lib/watchnet.php)
    ];
}

// ===================================================================== the page

/**
 * What the page shows (data/watchman.json): the last and next round, how
 * secure it stands (his posture tips), the watch book (newest first), what he
 * keeps an eye on, and where Grafana keeps the data flow's history. Never the
 * internals (positions, hashes). $grafana: the consultant's state file (tests).
 */
function watchmanPageState(?string $dir = null, ?int $now = null, bool $write = true, ?string $grafana = null): array
{
    $dir ??= watchmanDir();
    $now ??= time();
    $d = watchmanLoad($dir);
    $b = $d['baseline'];
    $st = $d['state'];
    $since = $write ? watchmanHiredSince() : (int) ($b['hired'] ?? 0);
    $last = isset($st['round']['time']) ? (int) $st['round']['time'] : null;
    $book = [];
    foreach ($d['book'] as $e) {
        $book[] = ['id' => $e['id'], 'kind' => $e['kind'], 'group' => WATCH_KINDS[$e['kind']][0] ?? 'watch', 'tell' => watchmanImportant($e),
                   'attack' => WATCH_ATTACK[$e['kind']] ?? null,
                   'time' => (int) $e['time'], 'last' => (int) $e['last'], 'count' => (int) $e['count'],
                   'open' => watchmanOpen($e), 't' => watchmanText($e),
                   'p' => array_filter((array) ($e['p'] ?? []), fn ($k) => !str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY),
                   'noted' => $e['noted'] ?? null, 'by' => $e['by'] ?? null, 'told' => $e['told'] ?? null, 'muted' => $e['muted'] ?? null,
                   'night' => !empty($e['night'])];
    }
    usort($book, fn ($x, $y) => [$y['last'], $y['time']] <=> [$x['last'], $x['time']]);
    $onWatch = is_array($b) && $since !== null && (int) ($b['hired'] ?? -1) === $since;
    $state = [
        'time'     => $now,
        'hired'    => $since !== null,
        'round'    => [
            'last'        => $last,
            'next'        => $since === null ? null : max($now, ($last ?? $now) + WATCH_EVERY),
            'running'     => !empty($GLOBALS['wmRound']) || !empty($GLOBALS['wmOrphan']),
            'failed'      => !empty($st['round']['failed']),
            'duration_ms' => (int) ($st['round']['duration_ms'] ?? 0),
            'skipped'     => (int) ($st['round']['skipped'] ?? 0),
            'docker'      => $st['round']['docker'] ?? null,
            'shares'      => $st['round']['shares'] ?? null,
        ],
        'on_watch' => $onWatch ? (int) $b['time'] : null,
        'open'     => watchmanOpenCounts($d['book']),
        'book'     => $book,
        'watch'    => $onWatch ? watchmanSummary($b, readJson("$dir/seen.json")) : null,
        'flow'     => $onWatch ? watchmanFlowSummary(is_array($b['flow'] ?? null) ? $b['flow'] : null, readJson("$dir/flow.json"), $now) : null,
        'snaps'    => $onWatch ? watchmanSnapSummary($st['snaps'] ?? null, is_array($b['snaps'] ?? null) ? $b['snaps'] : null) : null,
        // the network (agent/lib/watchnet.php): Unraid's syslog server, the senders, counts — never another client's address
        'net'      => $onWatch ? watchnetSummary(is_array($b['net'] ?? null) ? $b['net'] : null, readJson("$dir/net.json"), !empty($st['net_lan']), $now) : null,
        'net_lan'  => !empty($st['net_lan']),
        'posture'  => $onWatch ? watchmanPosturePage($st['posture'] ?? null, readJson("$dir/posture.json")) : null,
        'grafana'  => $onWatch ? watchmanGrafana($grafana) : null,
        'notified' => $st['last_notify'] ?? null,
        'notify'   => ['on' => ($st['notify'] ?? true) !== false, 'available' => is_executable(OFFICE_NOTIFY_BIN)],
        'syslog'   => ['on' => !empty($st['siem'])],
        'chains'   => array_map(fn ($c) => array_diff_key($c, ['key' => 1]), watchmanChains($d['book'], $now)),
        // the last night shift (the array stopped, or from boot to the array's start): from, until, from which mirror
        'night'    => is_array($st['night'] ?? null) && isset($st['night']['until']) ? array_map(fn ($v) => is_int($v) ? $v : (string) $v, $st['night']) : null,
        'limits'   => ['every' => WATCH_EVERY, 'burst' => WATCH_FAIL_BURST, 'window' => WATCH_FAIL_WINDOW,
                       'quiet' => WATCH_NOTIFY_QUIET, 'keep' => WATCH_BOOK_MAX, 'days' => WATCH_BOOK_DAYS],
    ];
    if ($write && is_dir(DATA_DIR)) {
        writeAtomic(deskFile('watchman'), jsonEncode($state));
    }
    return $state;
}

/**
 * The data flow's history in Grafana: the office's dashboard (uid unraid-secretary-office) at the panel
 * of each group that has one — only when the consultant saw Grafana there with its web address and the
 * office's dashboard provisioned (advisorGrafanaDashboard(): his state file, no docker call). A link,
 * nothing embedded; without the consultant's desk, null.
 *
 * @return array<string, string>|null  group => address
 */
function watchmanGrafana(?string $file = null): ?array
{
    $url = function_exists('advisorGrafanaDashboard') ? advisorGrafanaDashboard($file) : null;
    return $url === null ? null : array_map(fn (int $panel) => "$url?viewPanel=$panel", WATCH_GRAFANA_PANELS);
}

/**
 * "What I keep an eye on": the baseline, summarised — of the containers, plugins and shares only what
 * is there now ($seen: what the last round saw). Gone ones stay in his memory for WATCH_FORGET, so
 * one that comes back (a Compose Down and Up, an update, a reinstall) is compared with what it was,
 * not reported as new; but they are no longer counted or listed.
 */
function watchmanSummary(array $b, ?array $seen = null): array
{
    $there = function (string $part) use ($b, $seen): array {
        $known = is_array($b[$part] ?? null) ? $b[$part] : [];
        return is_array($seen[$part] ?? null) ? array_intersect_key($known, $seen[$part]) : $known;
    };
    $ips = [];
    foreach ((array) ($b['ips'] ?? []) as $ip => $k) {
        $ips[] = ['ip' => (string) $ip, 'first' => (int) ($k['first'] ?? 0), 'last' => (int) ($k['last'] ?? 0),
                  'users' => (array) ($k['users'] ?? []), 'services' => watchmanServiceNames((array) ($k['services'] ?? []))];
    }
    usort($ips, fn ($x, $y) => [$y['last'], $x['ip']] <=> [$x['last'], $y['ip']]);
    $fails = [];
    foreach ((array) ($b['fail_ips'] ?? []) as $ip => $k) {
        $fails[] = ['ip' => (string) $ip, 'since' => (int) ($k['since'] ?? 0), 'last' => (int) ($k['last'] ?? 0),
                    'n' => (int) ($k['n'] ?? 0), 'quiet' => (int) ($k['quiet'] ?? 0)];
    }
    usort($fails, fn ($x, $y) => $y['last'] <=> $x['last']);
    $containers = null;
    if (is_array($b['containers'] ?? null)) {
        $special = [];
        $now = $there('containers');
        foreach ($now as $name => $c) {
            if ((array) ($c['tokens'] ?? [])) {
                $special[] = ['name' => (string) $name, 'tokens' => (array) $c['tokens'], 'rights' => watchmanRights((array) $c['tokens']) !== []];
            }
        }
        $containers = ['count' => count($now), 'special' => $special];
    }
    $plugins = [];
    foreach ($there('plugins') as $name => $p) {
        $plugins[] = ['name' => (string) $name, 'source' => (string) ($p['source'] ?? ''), 'version' => $p['version'] ?? null];
    }
    $f = (array) ($b['flash'] ?? []);
    $keys = [];
    foreach ((array) ($f['keys'] ?? []) as $user => $list) {
        foreach ((array) $list as $fp => $k) {
            $keys[] = ['user' => (string) $user, 'fp' => (string) $fp, 'type' => (string) ($k['type'] ?? ''), 'comment' => (string) ($k['comment'] ?? '')];
        }
    }
    $extra = [];
    foreach ((array) ($f['extra'] ?? []) as $file => $x) {
        $extra[] = ['file' => (string) $file, 'size' => (int) ($x['size'] ?? 0), 'time' => (int) ($x['time'] ?? 0)];
    }
    $shares = null;
    if (is_array($b['shares'] ?? null)) {
        $open = [];
        $now = $there('shares');
        foreach ($now as $share => $s) {
            if (($s['smb'] ?? 0) > 0 || ($s['nfs'] ?? 0) > 0) {
                $open[] = ['share' => (string) $share, 'smb' => (int) ($s['smb'] ?? 0), 'nfs' => (int) ($s['nfs'] ?? 0)];
            }
        }
        $shares = ['count' => count($now), 'open' => $open];
    }
    return [
        'ips'        => $ips,
        'fail_ips'   => $fails,
        'containers' => $containers,
        'plugins'    => $plugins,
        'flash'      => ['go' => is_array($f['go'] ?? null) ? ['lines' => count((array) $f['go']['lines'])] : null,
                         'extra' => $extra, 'users' => array_values((array) ($f['users'] ?? [])), 'keys' => $keys],
        'shares'     => $shares,
        'sched'      => watchmanSchedSummary(is_array($b['sched'] ?? null) ? $b['sched'] : null),
        'host'       => watchmanHostSummary(is_array($b['host'] ?? null) ? $b['host'] : null, is_array($seen['host'] ?? null) ? $seen['host'] : null),
        'partner'    => watchmanPartnerSummary(is_array($b['partner'] ?? null) ? $b['partner'] : null, is_array($seen['partner'] ?? null) ? $seen['partner'] : null),
    ];
}

/** "What I keep an eye on" of the server itself: the ports, the programs from odd places, the ways in — what is normal and there now */
function watchmanHostSummary(?array $h, ?array $seen): ?array
{
    if ($h === null || $seen === null) {
        return null;
    }
    $listen = [];
    foreach (array_intersect_key((array) ($seen['listen'] ?? []), (array) ($h['listen'] ?? [])) as $key => $l) {
        $listen[] = ['key' => (string) $key, 'prog' => (string) ($l['prog'] ?? ''), 'port' => $l['port'] ?? null, 'addr' => (array) ($l['addr'] ?? [])];
    }
    usort($listen, fn ($a, $b) => [$a['port'] === null, $a['port'], $a['key']] <=> [$b['port'] === null, $b['port'], $b['key']]);     // by port, the dynamic ones last
    $procs = [];
    foreach (array_intersect_key((array) ($seen['procs'] ?? []), (array) ($h['procs'] ?? [])) as $x) {
        $procs[] = ['prog' => (string) ($x['prog'] ?? ''), 'exe' => (string) ($x['exe'] ?? ''), 'where' => $x['where'] ?? null];
    }
    $doors = [];
    foreach ((array) ($h['doors'] ?? []) as $d) {
        if (!empty($d['on'])) {
            $doors[] = ['what' => (string) $d['what'], 'name' => (string) $d['name'], 'port' => $d['port'] ?? null, 'issuer' => $d['issuer'] ?? null,
                        'peers' => count((array) ($d['peers'] ?? []))];
        }
    }
    // Unraid's API keys as he knows them and they are there now: names and roles (never more of them)
    $api = null;
    if (is_array($h['api'] ?? null)) {
        $api = [];
        foreach (array_intersect_key($h['api'], is_array($seen['api'] ?? null) ? $seen['api'] : $h['api']) as $k) {
            if (is_array($k)) {
                $api[] = ['name' => (string) ($k['name'] ?? ''), 'roles' => array_values((array) ($k['roles'] ?? [])), 'perms' => (int) ($k['perms'] ?? 0),
                          'full' => !empty($k['full'])];
            }
        }
        usort($api, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
    }
    return ['listen' => $listen, 'procs' => $procs, 'doors' => $doors, 'users' => count((array) ($h['users'] ?? [])), 'api' => $api];
}

/** "What I keep an eye on" of what starts on its own: root's crontab lines, the .cron files, User Scripts, at, agents */
function watchmanSchedSummary(?array $s): ?array
{
    if ($s === null) {
        return null;
    }
    $c = is_array($s['crontab'] ?? null) ? $s['crontab'] : null;
    $files = [];
    foreach ((array) ($s['files'] ?? []) as $name => $f) {
        $files[] = ['file' => (string) $name, 'lines' => count((array) ($f['lines'] ?? []))];
    }
    $scripts = [];
    foreach ((array) ($s['scripts'] ?? []) as $name => $x) {
        $scripts[] = ['name' => (string) $name, 'cron' => (string) ($x['cron'] ?? '')];
    }
    return [
        'crontab' => $c === null ? null : array_slice(array_values(array_unique(array_merge(array_values((array) $c['lines']), array_values((array) $c['twice']),
                                                                                         array_values((array) $c['office'])))), 0, 100),
        'twice'   => $c === null ? 0 : count((array) $c['twice']) + count((array) $c['office']),
        'files'   => is_array($s['files'] ?? null) ? $files : null,
        'scripts' => is_array($s['scripts'] ?? null) ? $scripts : null,
        'at'      => is_array($s['at'] ?? null) ? count($s['at']) : null,
        'agents'  => is_array($s['agents'] ?? null) ? array_map('strval', array_keys($s['agents'])) : null,
    ];
}
