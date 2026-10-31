#!/bin/bash
###############################################################################
# unraid-backup - backup.sh                       Version 2.85 - 2026-10-31
#   2.85 A stop by signal ends the future-files find and removes the run's files in /tmp (lib/common.sh tmp_files_stop in
#        cleanup; what a hard kill left is swept at the next start, tmp_files_sweep). A run's warning is keyed on its item
#        codes (lib/common.sh ub_items_codes, notifygate.sh ung_key): another set of causes within 20 h is mailed, not counted.
#   2.84 state/results.json is written again on a real server (lib/common.sh results_write): the history went to jq as ONE
#        argument and hit the kernel's 128 KiB limit per argument once history.jsonl passed it (~70 runs) - now fed from
#        files. What never leaves the flash (lib/common.sh FLASH_NEVER: the USO network's auth key) is one list for both
#        ways: the archive's tar excludes (flash_tar) and the Kopia source _flash's ignore rules, which the run itself
#        sets on the flash's policy before its upload (kopia_flash_one, flash_policy_align) - no rule, no upload while
#        such a file lies in the snapshot (err_code flash_never_policy)
#   2.83 The run's notification names its coded warnings and errors (lib/common.sh ub_run_items): the description «2
#        warnings: nextcloud_data: 1 file dated in the future · Backups: 7 files dated in the future. 11 min 5 s, …» -
#        errors first, the first three in short words (notify_item.<code>), «and n more» -, the long text one line per
#        item in the page's words (warn_code.<code>) under its headline. The log stays English
#   2.82 A scheduled start (--scheduled: the plugin's cron through job.sh) without settings.ini starts no run: no lock, no
#        log, no status.json, no notification - one history line «skipped», reason settings_missing, that counts on
#        (count, first) while it is the newest (lib/common.sh history_count) instead of a failed run with an alert a night.
#        What an aborted run left that an Apply couldn't bring back yet, or brought back, is a warning with a code
#        (lib/common.sh warn_as: recover_docker_silent, recover_libvirt_silent, recover_ct_not_ready, recover_nc_gone,
#        recover_nc_not_running, recover_nc_no_occ, recover_back)
#   2.81 What an aborted run left and doesn't come back is an error with a code (lib/common.sh err_as) - a run logs it as
#        before; a setup's Apply records it for the office (setup.sh ub_msg_as). An app's or VM's own Kopia source carries
#        «uso-layout:own» (kopia_item_tags): without «uso-split» it is not split for sure (2.80: no tag at all - a guess).
#        A run without settings.ini ends with the code settings_missing (die_code; up to 2.80 the English text)
#   2.80 The rest of T01 (lib/notifygate.sh, the same as the office's gate): «Backup failed» / «Backup not started» with
#        the very same cause again within 20 h (NOTIFY_FAILED_SAME), no success between, is counted - with how many
#        times (the decision's «times», a «counted» line in the guard) - instead of an alert every run; another cause
#        always goes, a daily schedule still reminds daily. Other alerts keep the minute (NOTIFY_ALERT_SAME).
#        Kopia snapshots say how their source was put together (review M4, lib/common.sh kopia_share_tags): a share's
#        «uso-layout:<single|overlay|split|live>» (as mounted this run), split also «uso-parts:<base>|…»; an app's or
#        VM's own source with parts in a share mounted split «uso-split:<share>|…» - only from Kopia 0.13.
#        state/results.json: per source and unit (local snapshot, package, Kopia, partner) its newest result {result,
#        code, time, run, good} - written by status_finish (lib/common.sh results_write) at the end of every real run,
#        this run's shares from results_local_json; the first time history.jsonl folded in. Past 200 runs nothing lost.
#   2.79 The offsite repository emptied while Kopia's cache still opens it (B17): listing the snapshots fails with
#        «BLOB not found» - the storage itself is asked (kopia blob list) whether the repository's description is still
#        there; gone is kopia_gone (once, an alert, the hold) and the Kopia step holds - up to 2.78 the run just ended
#        with Kopia's errors. --kopia-count says repository_gone then. Kopia's cache is never cleared for it.
#        Files dated in the future (more than UB_FUTURE_SLACK, a day, ahead: a clock that ran ahead, a camera with the
#        wrong date) are said: right after Kopia read a share or an app's or VM's source, one find over the same mount
#        (lib/common.sh future_look) - warning future_files with the source, how many, one example and its date
#   2.77 How many at once (2.73) measured in milliseconds: the settling and the window's time no longer whole seconds
#        read at any fraction of one (the total rate up to a second's worth off; a short window far more)
#   2.76 Code review 1.72.0: a VM's or app's own dataset in a share sent to a partner whose unit doesn't go there stays
#        here and is said (status.json partner.left_out with owner; up to 2.75 left off silently); a dataset inside an
#        app's own dataset is left out of app:<name> (named there, no longer under the share). The flash archive never
#        holds the USO network's auth key (usonet/authkey, whatever tar_exclude says). status.json partner.partners[]
#        seconds: the wall time of a partner's transfers (several at once - the office's Mbit/s). A rate change is told
#        only to a pid that is still pv.
#   2.75 Docker switched off by the user while something goes offsite: no longer «Kopia container '<name>' does not
#        exist» (an error, the run ending «errors») - Kopia can't be asked, kopia.state docker_off, a warning
#        docker_off_offsite (warning_items); the local part and the partners as usual, the run ends with warnings. No
#        container named in settings.ini is «gone» (ct_gone, app_source_orphan) while Docker is off - nobody knows
#   2.74 One child dataset that is no unit of its own (no VM's, no app's, not excluded) no longer keeps a whole share at
#        home: the share goes to the partner without it (zfs send without -R), each such child said in the plan and the
#        log and listed in status.json partner.left_out ({id, unit, dataset, folder}); an app's own dataset is also known
#        from Docker's binds (only its containers bind into it), not only from its [app] folder - such a child is the
#        app's (its own level), and an app ticked for a partner without an [app] folder finds its dataset that way.
#        Notifications: an alert is never folded into «the same» (lib/notifygate.sh: its key also covers its
#        description; only the very same alert within a minute is counted) - a second «Backup failed» within a day goes
#   2.73 Several datasets to one partner at once, only while that clearly moves more (partner_adapt): one first, one more
#        while the total rate rises by more than UB_PARTNER_GAIN %, up to UB_PARTNER_STREAMS and what the partner's door
#        takes (its ping's streams; a door before 1.72: one). Each dataset stays one ordered, resumable stream; the rate
#        limit is the total; status.json partner.streams (current = the one going on longest), interrupted_all
#   2.72 A partner's address and port from the Team Lead's pairs.json when it names the pair (lib/common.sh partner_addr):
#        «Change the address…» there reaches the run without Mr. Backupsy's Apply; else settings.ini as before
#   2.71 The office's USO network (its own Tailscale node): a partner whose address is a peer of that node is called from
#        inside the network's namespace (lib/common.sh partner_ssh_cmd, usonet_peer) - nothing else changes
#   2.70 The office's order with its mover run (Ms. Moverelli): a scheduled run that waited for it before it started (the
#        office's job waits before it starts the engine) says so - status.json and history "order" {code: waited_night,
#        seconds} from UB_ORDER (lib/common.sh order_load), one log line. The office's mover run reads the phase.
#   2.69 An app stopped for the snapshot whose folders lie on a pool with almost no room (below 2 % of it, at least 1
#        and at most 10 GiB, pool_low) is said before it stops: warning pool_low_space {pool, free, size, floor, apps, n}
#        - it is still stopped and started as set up (a ZFS snapshot itself takes next to no room; a consistent
#        snapshot is worth most just then), but it may not start cleanly there and the new snapshot keeps what it
#        overwrites. A share Unraid deleted whose dataset only its snapshots keep (share_deleted_left) is no longer
#        snapshotted again as the share: drift share_deleted_snapshots {name, datasets, snapshots}; nothing destroyed.
#   2.68 An upload that takes nothing no longer holds the run for ever: Kopia's upload keeps no time limit, but a stall
#        watchdog - its process reading and writing less than UB_KOPIA_STALL_BYTES (1 MiB, /proc/<pid>/io) for
#        UB_KOPIA_STALL (900 s) ends it (INT, KILL after UB_KOPIA_STALL_END); a warning kopia_stalled {name, minutes,
#        since}, the later uploads skipped at once, no further Kopia step waits (kopia_silent); the run ends as always
#        (unmount, cleanup, the lock freed); local snapshots, packages and partners as always. Every other Kopia
#        call but the upload and the policies (--version, repository status, snapshot list) has UB_KOPIA_TIMEOUT
#        (300 s) instead of the exec's 900 s.
#        --kopia-watch: does the offsite storage still take writes? From the Kopia server's own content log only
#        (state/kopia-watch.json; a turn to «stuck» told once, notify kopia_writes; a run's stall and upload write it too).
#   2.67 A docker exec ended by its time limit no longer leaves its process running inside the container (ub_exec_end:
#        exactly that command, started since the exec, and its children - TERM, then KILL). A Kopia call ended by its limit
#        means Kopia doesn't answer: the run's later Kopia calls with a limit are refused at once (kopia_silent) - a share
#        whose policy then can't be aligned is skipped as before; the uploads run as long as they take, as before.
#        A policy look or change: UB_KOPIA_POLICY_TIMEOUT (120 s).
#   2.66 Every docker call has a time limit (docker() in lib/common.sh): a look or a change UB_DOCKER_TIMEOUT (120 s; docker
#        stop plus its own -t), an exec UB_DOCKER_EXEC_TIMEOUT (900 s); a dump and Kopia's upload run as long as they take
#        (UB_DOCKER_LONG). Docker switched on but hung (dockerd stopped) ends the run at its start after UB_DOCKER_ANSWER
#        (20 s) with the code docker_silent, before anything is stopped - up to 2.65 `docker info` waited for ever.
#        Kopia connected but unable to reach its repository (the storage down, the way there closed) is said as such: drift
#        kopia_unreachable (up to 2.65 «not connected»); state/kopia-reach.json {v, since, run, last, last_run} keeps «offsite
#        unreachable since» the first real run that couldn't reach it, from run to run, until a run reaches it (or Kopia is
#        switched off); Kopia's own words go to the log only.
#        MariaDB 11.4+ makes a TLS certificate of its own when the server starts, and its client checks it even over the
#        socket: after the clock was set back below that start every login failed («certificate is not yet valid») and the
#        dump said «no database found». Asked once (SELECT 1); such a TLS error: that container's client goes through its
#        socket without TLS (--skip-ssl), said in the log. The database list refused otherwise: the client's own words.
#        A run's name (the minute: run id, log, uso-backup-<minute> snapshots) is never used twice: besides its log (2.34) a
#        history row of a run with this id or a snapshot of the name on a pool or disk awake (ZFS <dataset>@<prefix><minute>,
#        btrfs <base>/<btrfs_snap_dir>/<minute>) ends the run right after taking the lock, touching nothing (the autumn hour
#        02:00-03:00 comes twice; a data folder set back or new knows nothing of the snapshots) - up to 2.65 the snapshot
#        phase met the existing name: btrfs «Read-only file system», ZFS «dataset already exists», errors.
#        The office's notification centre decides what the engine's notifications do (lib/notifygate.sh, the same gate
#        as the office's agent): its settings in RAM (notify.cfg - none: everything goes, as before) - Mr. Backupsy's
#        threshold, his successes (then notify_success no longer decides), right away or held for the Team Lead's
#        summary, quiet hours, «silent until»; the flood guard (the same once until a run ends ok or a day passed, caps
#        per desk and office with one notice, alerts bundled past ten an hour). Alerts always go. Every decision as a
#        small JSON for the office's history (notify-in/). ub_tell passes its code as the event.
#   2.65 Nextcloud no longer stays in maintenance mode after an array stop or a power cut during a run: Unraid's array
#        start (every boot too) resets a share root that isn't of the group users to 0777 nobody:users - Nextcloud's data
#        folder then refuses every occ call, and --recover could never switch the mode off (its note kept for ever). The
#        run notes the data folder's root as it found it before the mode went on (state/nc-datadir: owner, group, mode -
#        closed, since occ answered); when occ refuses for it, --recover (and the run's own end) closes exactly that root
#        again - only the folder itself, never looser than the owner had - and switches the mode off.
#        Too little room in the backup place for the packages (min_free_gb) is coded: no_space_packages {place, free,
#        need - bytes; free_h, need_h readable}, status.json message_params (die_code's params) - up to 2.64 an English
#        sentence on every page.
#        A new VM whose disk lies on a file system without snapshots (xfs) or is a whole device is no longer «in the
#        snapshot of its share» (drift new_vm): new_vm_unheld, and what holds the disk as for a VM set up -
#        vm_disk_live (nothing), vm_disk_live_kopia {name, share} (only Kopia's copy of its share, read live).
#        A share config without a root anywhere counts as a share only while Unraid lists it (shares.ini): one Unraid no
#        longer lists (its roots gone, e.g. after Unraid renamed it) is gone - setup.sh offered it Kopia («still empty»).
#   2.64 A container the user paused (docker pause, Unraid's Pause) is left exactly as it is: never stopped, never
#        started, so it is still paused after the run - up to 2.63 docker's State.Running (true for a paused one) had it
#        stopped and started, and it ran afterwards. Its files hold still for the snapshot; a paused database gets no
#        dump (warning dump_paused {container}; a stopped one: dump_stopped, up to 2.63 a plain warning), a paused
#        Nextcloud no maintenance mode, a paused media server no SQLite copy. The app's manifest says paused per container.
#        A running Nextcloud whose database is stopped no longer fails the run (every occ call fails then - Nextcloud
#        connects to its database first - and the run died at the maintenance mode, nothing backed up): no maintenance mode,
#        Nextcloud stopped with the apps, everything else backed up; warning db_unreachable {app, container} (config.php's
#        dbhost names a stopped or paused container: occ not asked) or db_unreachable_occ {app} (occ's database error),
#        a notification in the office's language. Any other refusal still ends the run, now coded nc_maintenance_failed.
#   2.63 The btrfs emergency brake says what it did, in codes: each early delete is a warning btrfs_brake (base,
#        free_gb, floor_gb, snap) - up to 2.62 a plain warning. status.json "btrfs" says per base what holds (kept,
#        oldest, released, none_left - the brake reached with none of ours left, still no warning (2.23) -, brake_last,
#        held); state/btrfs-brake.txt remembers the newest snapshot the brake released per base: while it is younger
#        than keep_days, the retention doesn't hold there
#   2.62 Nextcloud refusing to work because its data folder is readable by others (Unraid resets a share's root to
#        0777 nobody:users when any share is added or saved) no longer fails the run: backed up without the
#        maintenance mode (stopped with the apps), a warning nc_datadir_readable with the folder and the lasting fix
#   2.61 An app's package inside never goes into a database's data folder (an app folder bound as /var/lib/mysql,
#        /var/lib/mariadb or /var/lib/postgresql/data, or holding ibdata1, aria_log_control, mysql.ibd or PG_VERSION at
#        its top: MariaDB listed .uso-backup there as a database «#mysql50#.uso-backup» and its dump failed every run
#        from the second on) - one log line, no warning; a .uso-backup an earlier run wrote there is moved away once
#        into state/inside-aside/<run>/ (2.57); the MariaDB dump leaves out the engine's own folder (#mysql50#.uso-backup)
#   2.60 No change here (the engine's version: setup.sh keeps what is stored for a stopped Nextcloud, database or VM)
#   2.59 No change here (the engine's version: setup.sh --orphans offers the old sources of units no longer offsite)
#   2.58 No change here (the engine's version: setup.sh's OK and error lines as codes)
#   2.57 An app's package inside goes only into its configuration folder: inside Unraid's place for app configurations
#        (docker.cfg DOCKER_APP_CONFIG_PATH: appdata/<app>, or a dataset only it binds inside appdata) - never a share's
#        root (folder or dataset: an S3 gateway sees .uso-backup there as a bucket), never a folder of another share
#        (the app's data); each place left out is one log line. A .uso-backup an earlier run wrote there or into
#        the root of a snapshotted share (its README.txt the engine's) is moved away once into
#        state/inside-aside/<run>/, said in the log
#   2.56 A repository suddenly empty holds the Kopia step: far fewer snapshots than at the last count (or than when the
#        hold began) is an error (warning_items kopia_held), nothing goes offsite, the local part and the partners go on;
#        state/kopia-held.json keeps it from run to run (an alert when it begins) until the snapshots are back, Kopia is
#        connected to another repository, or --kopia-accept=<repo> («yes, this repository is new»). Object Lock kept:
#        state/kopia-lock.json (the lock's mode and period, Kopia's maintenance owner, extend, full cycle and its last
#        runs) after the Kopia phase and by --kopia-lock (the office judges whether the locks still get extended)
#   2.55 Plex's database copies are checked by Plex's own SQLite (media_sqlite_check in lib/common.sh): Unraid's sqlite3
#        can't read its ICU collation and FTS tokenizer and failed every copy; that sqlite3 failing on them is «unchecked»
#   2.54 Docker switched off (Settings > Docker, or no docker at all) is a server of shares and VMs: the run backs them
#        and the flash up, no apps (ub_docker_service_off); Docker switched on but silent still ends the run
#   2.53 The offsite repository emptied is said at once: before the upload the snapshots of this identity are counted
#        against the last run's end (state/kopia-count.json, kept after the Kopia phase and after --orphans-delete) -
#        none left or less than half, in the same repository and identity, is warning_items kopia_emptied and a
#        notification (alert); the run uploads everything anew. Kopia not answering counts nothing. Kopia unable to
#        open the repository because its format blob is gone (BLOB not found): kopia_gone, once. --kopia-count:
#        the count as JSON, read only (the office's Night Watchman; why repository_gone for the format blob gone)
#   2.52 The notifications speak the office's language (ub_tell in lib/common.sh): subject «<Mr. Backupsy's name>: …»
#        and description from his language files (notify.*), the long report stays English
#   2.51 No change here (the engine's version: setup.sh --orphans / --orphans-delete)
#   2.50 A unit that goes to a partner office never keeps «0 0 0» local snapshots: settings.ini refused (cfg_validate) -
#        the partner's incremental send needs one snapshot both sides still have
#   2.49 Review: UB_DATA a real folder of root's that nobody else can write, settings.ini a plain root-owned file -
#        else refused before anything is read or written there (ub_data_trusted, exit 4, an alert)
#   2.48 Review: the ZFS retention never thins a partner office's copies here (<pool>/UnraidSecretaryOffice-partners/...,
#        the door's retention keeps them) nor a dataset in the storeroom; a second stop during the cleanup never cuts it
#        short; MariaDB names one a line (a space, a * - never split or globbed; / ' \ said, not dumped); a failed thaw
#        tried twice, then an alert, a failed freeze thawed before the pause; keep_logs = 0 keeps the run's own log
#   2.47 No change here (the engine's version: setup.sh finds a partner paired anew by its name too)
#   2.46 A partner that got nothing or not all of a run is said in that run (no nights of grace): unreachable, its array
#        stopped, a refusal (an older door's refused_asleep, refused_window, not_agreed), a failed transfer - one coded
#        warning per partner (warning_items partner_missed, partner + why) unless another coded one named it; the
#        nights counter and state/partner-skips.json are gone (a stale file is harmless), UB_PARTNER_NIGHTS no longer read
#   2.45 A partner settings.ini still names but the Team Lead no longer pairs (ended there, perhaps paired anew) is
#        skipped as ended, not no_key - the log says so and that Set up... > Apply gives its units to the partnership
#        made anew; a skip that means the setup or pairing is broken (ended, no_key, snap_prefix, no_ssh) warns every
#        run with what to do (warning_items partner_*), array_stopped counts nights like unreachable before it warns,
#        the mail's TO <partner> lines give the reason in words; a full quota at the partner warns every run (no
#        longer once a day), its pool asleep at the door (refused_asleep) counts nights like unreachable; status.json
#        names the run's partners from its start (the run card's partner step), their units after the inventory;
#        apps whose folder is a dataset of its own are partner units (app:<name>, [app] partner = <id>; such a dataset
#        is no «children» of its share), an older door refusing one is a skip (old_partner, a warning every run); an
#        app's manifest names its folders (dirs: <share>/<folder>) for a restore elsewhere. A tour's and a dry run's log
#        carry the second (check-|dryrun-YYYYMMDD-HHMMSS.log): one a minute holds only for a real backup (its snapshots).
#        setup.sh: a gone partner whose address (or host key, partner/ended.json) a current pair has gets
#        its units carried over to that pair (hint partner_anew); without a match they go as before (partner_gone)
#   2.44 The package inside the unit's folder (2.43) goes into every backed-up VM's folder (domains/<vm>/.uso-backup/)
#        and every app's first-level folder (appdata/<app>/.uso-backup/) whatever dataset they lie in - a share's copy
#        at a partner carries the VM folders, so they must carry the configuration; 2.43 wrote it only into a folder
#        that was a dataset of its own
#   2.43 Every unit's copy is whole: a VM's package (XML, NVRAM, TPM state, snapshotdb, manifest.json) is also written
#        into the VM's own dataset as <folder>/.uso-backup/ right after it is built (the VM shut down or held, before
#        the snapshots); an app whose folder is a dataset of its own (no other app binds into it) gets its package
#        there too (template or compose files, the manifest, this run's database dumps - never the SQLite copies).
#        Small files, overwritten each run; a README.txt says what it is. The backup place keeps the packages as
#        before. A partner office's copy of the dataset thus carries the configuration (its Mr. Restori starts from it)
#   2.42 Partner offices, simple: the run no longer skips a unit as not_agreed (the partner's door takes any unit
#        up to its quota; a door up to 1.59 that still answers unit_not_agreed makes it skipped, not failed);
#        status.json carries skip_kopia (a --no-kopia run) and, while a unit goes to a partner, current.bytes every
#        10 s with current.estimate (zfs send -nP before the send; null when a transfer is resumed).
#        A VM held otherwise than set up warns with a code (status.json warning_items); one off after its deadline doesn't
#   2.41 Every drift note carries a code and its parameters (drift.json items: params {key: value}, beside code and
#        value - value only for the codes from before 2.41): the office says them in its own words and languages; the
#        English text stays as it was (log, drift.txt, the notification). Nothing else changed
#   2.40 (setup.sh only: an [app] section whose app is gone is removed like a gone share's entry)
#   2.39 (setup.sh only: every hint and warning of the setup carries a code for the office)
#   2.38 A new folder of an app/VM share inherits the level of its app or VM also when that one was set up before and
#        is only local ([docker] known / a [vm] section, no kopia = yes): decided, it stays local silently - no
#        state/new-local.json entry, no notification, no drift; the run still leaves it out of the share's Kopia
#        policy right before the upload (state/kept-local.json, part of the wanted policy) and takes the rule away
#        once the folder is gone or its app/VM goes offsite. A new app's/VM's folder and one of no owner wait as before
#   2.37 A new top-level folder inherits its share's level: in a data share that goes to Kopia it goes there too
#        (nextcloud_data: a new user's folder), only the user's kopia_ignore leaves one out. Only in the shares Unraid
#        names for app configs and VMs (docker.cfg DOCKER_APP_CONFIG_PATH, domain.cfg DOMAINDIR) a new folder follows
#        its app or VM (container binds, VM disks): one going to Kopia takes it along, any other leaves it new and
#        local as before. Rules a run set up to 2.36 for a data share's folders are taken away right before the upload
#        (tolerated by the policy comparison until then); drift known_missing only for app/VM shares
#   2.36 Ms. Dustdevil's storeroom is a hidden folder (.UnraidSecretaryOffice-trash, issue #7): datasets put away under
#        either name are never snapshotted; [kopia] ignore with the old name's rule leaves out the new name too
#   2.35 cfg_list readers no longer cut the pipe: a unit kept by two partners is never dropped from a run (partner_units
#        read the list through grep -q - SIGPIPE under pipefail counted a match as none); readers that stop early
#        (grep -q, break/return in a loop) take a $( ) of the helper, no «printf: write error: Broken pipe» either.
#   2.34 One run a minute: a run whose log (run-|check-|dryrun-<minute>.log) is there already ends right after taking
#        the lock with an ERROR line and exit 1, touching nothing - never a snapshot phase into an existing name.
#        The «not agreed» line names the server as Unraid does (ident.cfg NAME); setup.sh's hints go out as codes.
#   2.33 (setup.sh only: the backup place's share row in the plan takes the agreement of the unit `place`)
#   2.32 (setup.sh only: the plan flags Unraid's syslog share every time - `syslog: true` per share)
#   2.31 (setup.sh only: [general] preset_new, Mr. Backupsy's default for new things - a run accepts the key
#        and never reads it)
#   2.30 Sleeping pools: only a ROTATING disk Unraid spun down counts as asleep (disks.ini spundown=1 and
#        rotational != 0; ub_asleep_load) - an SSD in standby wakes in milliseconds and is never left out
#   2.29 Partner offices: a unit ticked for a partner that the partner hasn't agreed to keep (the Team Lead's
#        pairs.json send.units - asked for with «Change what <host> sends…») is skipped as not_agreed when
#        the phase makes its plan, before the door is asked (until 2.28 the door refused it: unit_not_agreed,
#        counted as refused). Without pairs.json, or for a pair it doesn't name, the door decides as before
#   2.28 Sleeping pools: [general] asleep_pools = wake (default, as before) | skip. With skip a ZFS pool of the
#        snapshot plan whose disks sleep (disks.ini, read once - nothing on the pool is asked) is left out that
#        night - no snapshot, no retention, no mount -, so is a btrfs disk or pool that sleeps; decided when the
#        run makes its plan, so a VM whose disks lie there is not frozen, paused or shut down and an app whose
#        backed-up data all lies there keeps running. Kopia sources with a part there are skipped (why asleep,
#        not failed), a partner's unit there too. The backup place's pool is always woken (the packages are the
#        point). status.json "asleep", kopia.skipped_why, last-run asleep=<n>; the result stays ok. A share left
#        out UB_ASLEEP_NIGHTS (7) nights in a row warns once (state/asleep.json)
#   2.27 Partner offices: the phase "partner" - right after the snapshots and the apps' restart, before
#        Kopia - sends this run's ZFS snapshots of the units the setup ticked ([share|vm] partner = <id>,
#        [general] partner_place = <id>; [partner "<id>"] name, address, port, rate_mbit) to each partner
#        through its door (ssh, the pair's own key and pinned host key, AES-GCM, no compression): zfs send
#        incremental from the newest snapshot both have (or the bookmark <ds>#uso-partner-<id> of the last
#        one sent, once the retention took that snapshot here), whole when there is none; mbuffer between,
#        pv for the rate and the bytes; an interrupted transfer continues from its resume token. The
#        backup place first, then the shares and VMs by size. Unreachable: skipped, a warning only from the
#        third night in a row; the partner's window/quota: skipped, a warning once a day; a failed receive:
#        a warning. The array stop ends the phase like Kopia's (interrupted, never failed). status.json
#        "partner", partner_ok/failed/skipped in status.json, last-run and history
#   2.26 The Kopia order reckons a VM's own source by its disk files' own sizes (VM_APPARENT: a sparse
#        vdisk's full virtual size), not by their allocated blocks: Kopia reads a sparse file whole, its
#        holes as zeros - on 2026-10-07 a 1.6 TB vdisk holding 21 GB was 2 TB of reading at its first
#        upload (2.7 h), its VM sorted as a small source. The setup's plan carries both sizes per VM
#        (bytes, apparent) so the office can say what a VM's first upload really reads.
#   2.25 backup.sh --recover: what a run left stopped, in maintenance mode or held (state/stopped,
#        maintenance, vms - an array stop leaves them for after the array start) is brought back right
#        after the array start - the plugin's event/started hands it to atd when such a note exists -,
#        not only by the next run (often the next night). Takes the lock without waiting (busy: the run
#        holding it brings them back itself - exit 75, quietly), never into a stopping array, waits a
#        bounded time for Docker and libvirt to answer; leaves status.json, last-run.json and history
#        alone (no run: its log is logs/recover.log). A run that finds a recover holding the lock waits
#        for it instead of skipping the night. A note stays while Docker (libvirt) doesn't answer.
#        The Kopia phase goes small and important first: the flash, the apps' own sources, then the
#        shares and the VMs' own sources by their expected size, smallest first (the larger of the
#        newest complete Kopia snapshot's size and ZFS's referenced or the VM's disk files; unknown
#        last; a newer Kopia checkpoint counts as a lower bound) - a first upload of terabytes no longer
#        holds back everything behind it for days. At the array stop nothing of the engine stays
#        mounted, whoever holds the lock: a run, check or dry run ending in a stopping array releases
#        everything under its mount roots, whatever it mounted (keep_mounts, a killed run's), busy ones
#        lazily (umount -l); the plugin runs --unmount with UB_ARRAY_STOP=1 (no wait for the lock) unless
#        a live run holds it, and UB_KEEP_LATEST=1: latest.log stays the last run's. The notes of an
#        interrupted run keep exactly what didn't come back; its containers start network first, then
#        databases, then apps; a Nextcloud's container is waited for; with the VM service off the VMs'
#        note goes; «Aborted run not fully repaired» (warning) says what didn't come back. The
#        notifications take turns with the office's (a stamp in RAM): one second, one notification
#   2.24 The run notices the array being stopped (var.ini fsState Stopping - minutes before Unraid
#        stops the VMs and Docker) at its safe points and every few seconds while Kopia uploads, and
#        ends at once: the Kopia snapshot going on is interrupted inside the container (Kopia keeps
#        what it uploaded, the next run continues), the remaining sources are skipped (not failed),
#        no retention, no new snapshot or dump, mounts, lock and lock note released. Nothing is
#        started into the stopping array (what the run stopped stays noted for the next run); frozen
#        or paused VMs are released, a running Nextcloud leaves maintenance mode. Result "aborted",
#        message array_stopping, one normal notification - never "errors" or an alert; exit code 3
#   2.23 The btrfs emergency brake scales with the disk: its floor is [btrfs] min_free_gb, but at most a
#        tenth of the disk (small disks were always "short"); with no earlier snapshot of ours left to
#        release it is a log line only, no warning and no notification every run
#   2.22 VMs with prepare = shutdown go down BEFORE anything stops: their shutdowns are requested and
#        waited for (one deadline, the request again every 60 s) while the apps still run, before
#        Nextcloud's maintenance mode - the apps' downtime (downtime_s) no longer includes waiting for a
#        VM. Freezing and pausing stay right before the snapshots, so does pausing a VM that wasn't off
#        by its deadline (one that went off later is started again like the others). A run stopped
#        while a VM goes down waits for it (until its deadline) and starts it again
#   2.21 New things stay local and keep running until the user decided: a top-level folder of a share
#        that goes to Kopia which is neither in its kopia_known (recorded by the setup) nor left out is
#        left out of the share's Kopia source for the run (rules in the share's policy, taken away again
#        once it is decided or gone) - the local snapshot holds it. Said in the log, in status.json
#        new_local, state/new-local.json, drift info new_waiting and once in a notification (normal).
#        A container not in [docker] known keeps running instead of being stopped for the snapshot.
#        Shares without kopia_known work as before (drift info known_missing)
#   2.21 state/pruned.json: the snapshots the retention destroyed, run by run (ZFS dataset@name, btrfs
#        paths; the last 30 runs within 30 days) - the night watchman need not guess or read logs
#   2.20 Names: what the office makes is called uso-...: ZFS snapshots uso-backup-YYYYMMDD-HHMM (default
#        [general] snap_prefix; the old default unraidbackup- counts as the default - those snapshots
#        stay the engine's and age out by the retention, matched exactly), Kopia snapshot descriptions
#        "uso-backup <run>". The Kopia container path recommended for new setups is /uso.
#   2.20 A run that finds the lock busy (another run still going - a first Kopia upload takes longer
#        than a day -, the setup, a restore) is never lost silently: it leaves status.json and
#        everything else alone and says so in state/skipped.json, in history.jsonl ("result":
#        "skipped", reason skipped_busy_<holder>) and, for a real backup, in a notification (warning);
#        exit code 75. Whoever holds the lock notes it in state/lock-holder.json (backup.sh, setup.sh,
#        Mr. Restori's restores). VMs with prepare = shutdown wait for one shared deadline (timeout
#        from their shutdown request), not one timeout after the other, and get the request again every
#        60 s (Windows swallows the first one). An app folder that is empty in the snapshot is a note in
#        the log, not a warning
#   2.20 An app whose compose services build their own image (build:) gets build/<service>/ in its
#        package: the Dockerfile and the small files of the build context's folder (top level, <= 1 MB
#        each, at most 100 files / 10 MB), unless compose/ holds them already
#   2.19 Apps and VMs at "local + Kopia" are Kopia sources of their own ([app|vm "<name>"] kopia = yes):
#        their folders and their package, joined read-only under <mount_root>/.apps|.vms/<name>, with
#        their own retention; the shares leave those parts out. Apps first, then the shares, then the
#        VMs. Media servers that keep running get a consistent copy of their SQLite databases in their
#        package (db/sqlite_*.db, SQLite's backup API, checked); the apps' own backups (Emby's plugin,
#        Jellyfin, Plex, Immich) are named in the package's manifest
#   2.18 Packages instead of run folders: per app (compose project or single container) and per VM
#        a folder in the backup place with its small files - templates or compose files, docker
#        inspect, database dumps, XML/NVRAM/TPM state - overwritten every run, swapped in only when
#        complete; a failed dump keeps the last good one. Their history lies in the snapshots of the
#        backup place's share. keep_runs is gone; the first run clears away the old run folders.
#        drift.json items carry a code for the messages the office translates
#   2.17 [docker] skip (apps not backed up keep running); datasets in Ms. Dustdevil's storeroom
#        (_UnraidSecretaryOffice-trash-*) are never snapshotted; btrfs disks with data of held
#        containers/VMs/the backup place are snapshotted before the restart, the others after it;
#        VM shutdowns begin together with pausing the apps; notifications say "Unraid Secretary
#        Office: …" (Unraid adds the server's name) and carry a short report of the run
#   2.16 VMs: [vm "<name>"] prepare = freeze | pause | shutdown | none for the seconds of the
#        snapshot (released right after the snapshot that holds their disks), mode = off and an own
#        retention for VMs in a dataset of their own; the libvirt archive after the VMs are held
#   2.15 Also part of the office's Unraid plugin: code in RAM, data from the plugin's DATA_DIR,
#        the nightly run scheduled by the plugin's cron file instead of User Scripts.
#   2.14 The whole script is one { ... } block: bash reads it completely before it
#        starts, so replacing the file while it runs no longer breaks the run
#   2.14 Nothing of ours directly in /mnt: mount_root and view_root under
#        /mnt/addons/UnraidSecretaryOffice; in the office's share UnraidSecretaryOffice the
#        dumps go to backup/; a changed backup place is moved by the next run
#   2.14 drift.json says per Kopia target whether its policy matches settings.ini ("policies",
#        differences as codes) - Mr. Backupsy shows it per share
#   2.13 Messages, logs and comments in English (WARNING:/ERROR: in the log, manifest/drift.txt,
#        state/last-run with English keys); the office reads interface 1 as before
#   2.12 Dumps and archives in a backup share of their own (general|dumps_share), never in appdata -
#        no valid place, no run; existing dumps move there on the first run.
#   2.12 Nextcloud permission error named clearly (code nc_datadir_readable); the manifest reads btrfs
#        only from the kernel (--mounted, time limit) instead of every device raw
#   2.11 The User Scripts entry is called unraid-secretary-office_backup (English description)
#   2.10 VM configuration from libvirt.img (XML, NVRAM, TPM state) as an
#        archive next to the dumps - [libvirt] mode = tar (default) | off
#   2.9  (setup.sh only: unknown size -> proposed as local only)
#   2.8  Pause apps before the dumps: dumps then match the files in the
#        snapshot, also for apps without a maintenance mode (Immich & co.)
#   2.7  (setup.sh only: --plan / --apply)
#   2.6  Part of the Unraid Secretary Office: code in <office>/backup, data in
#        <office>/data/unraid-backup (UB_DATA); --about names both folders
#   2.5  Status for other programs: state/status.json (while running), last-run.json,
#        history.jsonl, drift.json with fixed English keys; --about.
#        Kopia runs in the background so that aborting (SIGTERM) works at once -
#        the Kopia snapshot inside the container is ended cleanly
#   2.4  Nextcloud from several containers (app + cron, shared config.php)
#        is recognised as ONE instance - the second container reported the
#        maintenance mode just switched on as "already on" and aborted the run;
#        maintenance mode with 3 attempts, occ's messages go into the log
#   2.3  Shares without data (Unraid config only) no longer count as deleted;
#        reported is when data disappears or appears
#   2.2  General version without server-specific leftovers
#   2.1  Kopia optional ([kopia] enabled), databases detected by image,
#        environment and port, MongoDB dumps, Compose stacks
#   2.0  First version
#
# The nightly backup run for Unraid servers. Everything specific to the
# server is in settings.ini, written by setup.sh. This script only reads
# settings.ini - it never changes it.
#
# STEPS
#    1. Load settings.ini, take inventory (pools, disks, shares, containers)
#    2. Check for and report drift: new / renamed / deleted shares,
#       new containers, Kopia mapping and policies. None of it is "repaired"
#       here - new shares are only backed up once setup.sh has run.
#    3. VMs with prepare = shutdown: shut down and waited for, while everything
#       else still runs (a guest may take minutes or ignore the request)
#    4. Nextcloud into maintenance mode (aborts if it was already on)
#    5. Packages: per app its templates or compose files and docker inspect,
#       the server's lists (images, share configs, settings.ini)
#    6. Pause apps, then database dumps (MariaDB/MySQL, Postgres, MongoDB) into
#       the apps' packages, checked right away - so dumps and files match, also
#       for apps without a maintenance mode (e.g. Immich); the app packages
#       are swapped in  -> the downtime begins here
#    7. Stop databases and network containers, hold the VMs (freeze, pause); their
#       packages (XML, NVRAM, TPM state) and the libvirt archive, swapped in
#    8. ZFS snapshots (atomic per pool) and btrfs snapshots - they hold this
#       run's packages too
#    9. Start containers, maintenance mode off  -> the downtime ends here
#    9b. Partners (2.27): this run's ZFS snapshots of the ticked units to each partner office -
#       zfs send through its door (ssh), incremental where both have a snapshot in common
#   10. Mount the snapshots per share under <mount_root>/<share> (read-only), and
#       join each app's and VM's own source under <mount_root>/.apps|.vms/<name>
#   11. Kopia backs up the flash, the apps, then every share from <mount_root>/<share>
#       and the VMs, the smallest first (only with [kopia] enabled = yes - without
#       Kopia 10/11 end here: local snapshots and dumps are then the whole backup)
#   12. Unmount, clean up (ZFS, btrfs, logs; once: the run folders of engines
#       before 2.18), notification
#
# KOPIA CONTAINER (once) - only this one data mapping is needed:
#   Host <mount_root> (/mnt/addons/UnraidSecretaryOffice/snapshots) -> Container e.g. /uso
#   (Kopia names its sources after the container path: changing it later makes new sources - same
#   repository, so nothing is uploaded twice, but every file is read once more)
#   Access Mode: Read Only - Slave
#   "Slave" is what matters: only then does the running container see the
#   mounts this script creates after it started. Kopia is therefore never
#   stopped. setup.sh checks this with a live test.
#
# USAGE
#   Scheduled:    the plugin's cron file (Mr. Backupsy -> Schedule...) calls scripts/job.sh backup
#                 (-> backup.sh --scheduled: without settings.ini no run starts - one counted «skipped» line, 2.82)
#   Terminal:     bash /usr/local/emhttp/plugins/unraid-secretary-office/backup/backup.sh [option]
#
# VARIANTS (environment variable - the option after it is a shortcut)
#   UB_MODE=backup                 full run (default)
#   UB_MODE=check     --check      only check and report drift
#   UB_MODE=unmount   --unmount    release all snapshot mounts
#                                  (the plugin does it at the array stop
#                                  when keep_mounts = yes left them)
#   UB_MODE=recover   --recover    bring back what an interrupted run left
#                                  stopped (the plugin, at the array start)
#   UB_DRY_RUN=1      --dry-run    show the plan, change nothing
#   UB_SKIP_KOPIA=1   --no-kopia   dumps and snapshots yes, Kopia no
#   UB_SCHEDULED=1    --scheduled  started by the plugin's cron (job.sh): without settings.ini no run
#                                  starts - a counted «skipped» history line, no notification (2.82)
#   UB_NC_PREEXISTING=abort|continue
#                     Nextcloud was already in maintenance mode:
#                     abort = abort the run, continue = back up anyway and
#                     leave maintenance mode ON afterwards (applies to all
#                     Nextclouds and overrides settings.ini)
#   UB_NO_NOTIFY=1                 no Unraid notifications
#   UB_KEEP_LATEST=1               --unmount: leave logs/latest.log at the last run's log
#                                  (the plugin's array-stop hook, 2.25)
#   UB_ARRAY_STOP=1                --unmount: the array is being stopped - don't wait for the
#                                  lock, detach busy mounts (umount -l) at once (the hook, 2.25)
#                     --about      name, version and interface as JSON
#                     --kopia-count the offsite repository's snapshots and sources now, as JSON (read only, 2.53)
#                     --kopia-lock  Object Lock and Kopia's maintenance now -> state/kopia-lock.json (2.56; Kopia read only)
#                     --kopia-watch does the offsite storage still take writes? -> state/kopia-watch.json (2.68; Kopia's server log only)
#                     --kopia-accept=<repo>  «yes, this repository is new»: the hold of the Kopia step ends (2.56)
#   UB_DATA=/path                  another data folder (default <office>/data/unraid-backup)
#   UB_SETTINGS=/path/settings.ini another settings file
#   Example: UB_DRY_RUN=1 bash /usr/local/emhttp/plugins/unraid-secretary-office/backup/backup.sh
#
# FILES (in the data folder <office>/data/unraid-backup, nothing on /boot)
#   settings.ini        settings (from setup.sh)
#   logs/run-*.log      one log per run
#   state/              lock file, last run, reported drift,
#                       status.json & co. for other programs (lib/common.sh, 7.)
# In the backup place ([general] dumps_share: <share>/unraid-backup, in the office's
# share <share>/backup) the packages: apps/<app>/, vms/<vm>/, server/, flash/
# (lib/common.sh, 8.)
###############################################################################

# One block up to the end: bash parses all of it before running any of it. A run
# takes hours - without the block bash would read on from a file replaced meanwhile.
# (lib/common.sh is safe anyway: "source" reads a file completely.)
{

set -uo pipefail

# shellcheck source=lib/common.sh
source "$(dirname "$(readlink -f "$0")")/lib/common.sh" \
    || { echo "lib/common.sh is missing next to backup.sh"; exit 1; }

usage() { awk 'NR>1 && /^#+$/ {next} NR>1 && /^#/ {sub(/^# ?/,""); print; next} NR>1 {exit}' "$0"; }

for a in "$@"; do
    case "$a" in
        --check)    UB_MODE="check" ;;
        --unmount)  UB_MODE="unmount" ;;
        --recover)  UB_MODE="recover" ;;
        --kopia-count) UB_MODE="kopia_count" ;;
        --kopia-lock) UB_MODE="kopia_lock" ;;
        --kopia-watch) UB_MODE="kopia_watch" ;;
        --kopia-accept=*) UB_MODE="kopia_accept"; KA_REPO="${a#--kopia-accept=}" ;;
        --dry-run)  UB_DRY_RUN=1 ;;
        --no-kopia) UB_SKIP_KOPIA=1 ;;
        --scheduled) UB_SCHEDULED=1 ;;
        --about)    jq -nc --arg n "$UB_NAME" --arg v "$UB_VERSION" --argjson i "$UB_INTERFACE" --arg c "$UB_DIR" --arg d "$UB_DATA" \
                        '{name: $n, version: $v, interface: $i, code: $c, data: $d}'; exit 0 ;;
        -h|--help)  usage; exit 0 ;;
        *) echo "Unknown option: $a  (see --help)"; exit 2 ;;
    esac
done
UB_MODE="${UB_MODE:-backup}"
DRY="${UB_DRY_RUN:-0}"
UB_SCHEDULED="${UB_SCHEDULED:-0}"      # 2.82: started by the plugin's cron (job.sh) - skip_unset
SKIPK="${UB_SKIP_KOPIA:-0}"
case "$UB_MODE" in backup|check|unmount|recover|kopia_count|kopia_lock|kopia_watch|kopia_accept) ;; *) echo "UB_MODE=$UB_MODE is unknown"; exit 2 ;; esac

[[ $EUID -eq 0 ]] || { echo "Please run as root."; exit 1; }
# --recover (2.25): no note of an interrupted run - nothing to do, nothing written; the array being
# stopped - not now (the notes stay for after the array start), exit 3 like a run the stop ends
ub_data_trusted || ub_untrusted_refuse     # 2.49: nothing read or written in a folder others can write or replace
# --kopia-count (2.53): how many snapshots and sources of this identity the offsite repository holds now, one line of
# JSON ({ok: true, repo, identity, storage, time, snapshots, sources, by: "watch"} or {ok: false, why}) - read only: no
# lock, no log, nothing written (the office's Night Watchman looks once a day; lib/common.sh kopia_count_load)
if [[ "$UB_MODE" == "kopia_count" ]]; then
    LOG_FILE=""
    kc_not() { jq -nc --arg why "$1" '{ok: false, why: $why}'; exit 0; }
    load_settings >/dev/null 2>&1 || kc_not no_settings
    is_yes "$KOPIA_ENABLED" || kc_not off
    [[ -n "$KOPIA_CONTAINER" ]] || kc_not no_container
    if ! kopia_status_load >/dev/null 2>&1; then
        [[ "$KOPIA_FORMAT_GONE" == "yes" ]] && kc_not repository_gone      # the storage answers, the repository's format blob is gone
        [[ "$KOPIA_RUNNING" == "yes" ]] && kc_not not_connected; kc_not not_running
    fi
    if ! kopia_count_load; then
        # 2.79: the repository's files gone while Kopia's cache still opens it - asked of the storage itself
        [[ "$KC_BLOB_GONE" == "yes" ]] && kopia_format_gone_look && kc_not repository_gone
        kc_not no_answer
    fi
    kopia_count_json watch | jq -c '{ok: true} + .'
    exit 0
fi
# --kopia-lock (2.56): Object Lock and Kopia's maintenance now, kept in state/kopia-lock.json and printed - Kopia only read,
# no log; not while the engine's lock is held (a run writes it itself after its Kopia phase): {ok: false, why: busy}, nothing written
if [[ "$UB_MODE" == "kopia_lock" ]]; then
    LOG_FILE=""
    kl_not() { [[ "$1" == "busy" ]] || kopia_lock_save "$1"; jq -nc --arg why "$1" '{ok: false, why: $why}'; exit 0; }
    ub_data_dirs || { echo "Cannot create folders in $UB_DATA"; exit 1; }
    exec 9>>"$UB_STATE/lock"
    flock -n 9 || kl_not busy
    load_settings >/dev/null 2>&1 || kl_not no_settings
    is_yes "$KOPIA_ENABLED" || kl_not off
    [[ -n "$KOPIA_CONTAINER" ]] || kl_not no_container
    if ! kopia_status_load >/dev/null 2>&1; then
        [[ "$KOPIA_RUNNING" == "yes" ]] && kl_not not_connected; kl_not not_running
    fi
    kopia_lock_save
    cat "$KL_FILE"
    exit 0
fi
# --kopia-watch (2.68): does the offsite storage still take what Kopia writes? Kopia's server's own content log, read through
# one docker exec (lib/common.sh kopia_watch) - the repository never opened, nothing written there; no lock (a run may be
# going on: it reads the same), no log; state/kopia-watch.json written and printed, a turn to «stuck» told once
if [[ "$UB_MODE" == "kopia_watch" ]]; then
    LOG_FILE=""
    kw_not() { jq -nc --arg why "$1" '{ok: false, why: $why}'; exit 0; }
    ub_data_dirs || { echo "Cannot create folders in $UB_DATA"; exit 1; }
    load_settings >/dev/null 2>&1 || kw_not no_settings
    is_yes "$KOPIA_ENABLED" || kw_not off
    [[ -n "$KOPIA_CONTAINER" ]] || kw_not no_container
    kopia_watch || kw_not write
    exit 0
fi
# --kopia-accept=<repo> (2.56): «yes, this repository is new» - the office's one click, behind the engine's lock (busy: refused)
if [[ "$UB_MODE" == "kopia_accept" ]]; then
    LOG_FILE=""
    [[ "${KA_REPO:-}" =~ ^[0-9a-f]{16,128}$ ]] || { jq -nc '{ok: false, why: "repo"}'; exit 0; }
    ub_data_dirs || { echo "Cannot create folders in $UB_DATA"; exit 1; }
    exec 9>>"$UB_STATE/lock"
    flock -n 9 || { jq -nc '{ok: false, why: "busy"}'; exit 0; }
    kopia_accept "$KA_REPO"
    exit 0
fi
if [[ "$UB_MODE" == "recover" ]]; then
    recover_notes || exit 0
    array_stopping && { echo "The array is being stopped - nothing is started now; the notes stay for after the array start."; exit 3; }
fi
ub_data_dirs || { echo "Cannot create folders in $UB_DATA"; exit 1; }

TS="$(date +%Y%m%d-%H%M)"
TSS="$(date +%Y%m%d-%H%M%S)"     # 2.45: a tour or a dry run makes no snapshot - its log carries the second, never one a minute
STARTED_AT="$(date +%s)"
case "$UB_MODE" in
    backup)  LOG_FILE="$UB_LOGS/run-$TS.log" ;;
    check)   LOG_FILE="$UB_LOGS/check-$TSS.log" ;;
    unmount) LOG_FILE="$UB_LOGS/unmount.log" ;;
    recover) LOG_FILE="$UB_LOGS/recover.log" ;;
esac
[[ "$DRY" == "1" && "$UB_MODE" == "backup" ]] && LOG_FILE="$UB_LOGS/dryrun-$TSS.log"
# latest.log points at this run's log only once it has the lock (Start, at the end)

STOPPED=()                  # containers actually stopped
declare -A NC_ON=()         # Nextclouds whose maintenance mode WE switched on
declare -A NC_OCC=() NC_USER=()
declare -A NC_SAME=()       # container -> first container of the same Nextcloud instance
declare -A NC_DBDOWN=()     # (2.64) Nextclouds whose database couldn't be reached: no maintenance mode, no occ lists
declare -A NC_DIR=()        # (2.65) container -> "<folder>\t<uid>\t<gid>\t<mode>": its data folder's root before the mode went on
declare -A BTRFS_OK=()     # btrfs base -> snapshot path
declare -A BTRFS_TRIED=()  # 2.80: btrfs base -> 1 once its snapshot was tried
SNAPS_TAKEN="no"            # 2.80: the run took its ZFS snapshots (results_local_json: a share's local result)
declare -A LAYER_MNT=()     # ZFS dataset -> mount point under .layers
declare -A SHARE_MOUNTED=() # share -> single|overlay|split
declare -A ZFS_FAILED=()    # pool -> 1
MOUNTED="no"
CLEANUP_DONE="no"
DOWNTIME=0                  # the apps' downtime (downtime_s): from stopping the first app until all run again -
                            # since 2.22 without waiting for VMs to shut down (that comes before, vm_shutdowns)
SNAP_NAME=""
KOPIA_PID=""                # running Kopia snapshot (background, see kopia_one)
KOPIA_CP=""
PARTNER_TMP=""              # the transfers' files (the door's answers, pv's count; a folder per slot, SL_PID), in RAM
ARRAY_STOP="no"             # the array is being stopped: the run ends at once, starts nothing (2.24, array_stop_check)
CLEANUP_ARMED="no"          # the cleanup trap is set (from then on an array stop ends the run through it)
UB_ARRAY_LOOK="${UB_ARRAY_LOOK:-5}"   # seconds between looks at var.ini while Kopia uploads
UB_LIBVIRT="${UB_LIBVIRT:-/etc/libvirt}"              # libvirt's folder (the VMs' XML, nvram, swtpm, snapshotdb); tests point it elsewhere
UB_RECOVER_WAIT="${UB_RECOVER_WAIT:-300}"            # --recover: at most so long for Docker and libvirt to answer (2.25)
UB_RECOVER_LOOK="${UB_RECOVER_LOOK:-5}"              #   asking every so many seconds
UB_RECOVER_LOCK_WAIT="${UB_RECOVER_LOCK_WAIT:-900}"  # a run that finds a --recover holding the lock waits so long for it

dur_h() { local s="${1:-0}"; if (( s >= 60 )); then printf '%d min %d s' $(( s / 60 )) $(( s % 60 )); else printf '%d s' "$s"; fi; }

die() {
    # a failure while the array is being stopped is that stop (Docker gone, a pool going): ended as such
    [[ "$CLEANUP_ARMED" == "yes" && "$CLEANUP_DONE" != "yes" ]] && array_stopping && { log "  ($*)"; array_stop_abort; }
    err "$*"
    status_finish failed "$*"
    ub_tell failed alert "Log: $LOG_FILE" "Backup failed" "The backup failed - Mr. Backupsy's page in the office says what to do: $*" "why=$*"
    exit 1
}
# die_code <code> <text> [key=value]...: like die, but status.json carries the code - the office translates it - and,
# since 2.65, its params (message_params: {key: value}), which the office's words and the notification fill in
die_code() {
    local code="$1" text="$2" kv; shift 2
    [[ "$CLEANUP_ARMED" == "yes" && "$CLEANUP_DONE" != "yes" ]] && array_stopping && { log "  ($text)"; array_stop_abort; }
    err "$text"
    ST_MESSAGE_PARAMS=""; for kv in "$@"; do ST_MESSAGE_PARAMS+=$'\x1f'"$kv"; done
    status_finish failed "$code"
    local why; local -a say=()
    for kv in "$@"; do [[ "${kv%%=*}" == *_h ]] && say+=( "${kv%%_h=*}=${kv#*=}" ); done   # <key>_h: <key> readable
    why="$(ub_say "message.$code" "${say[@]}" "$@")" || why="$text"
    ub_tell failed alert "Log: $LOG_FILE" "Backup failed" "The backup failed - Mr. Backupsy's page in the office says what to do: $why" "why=$why"
    exit 1
}

##############################################################################
# Stopping and starting containers
##############################################################################
T_APP=(); T_DB=(); T_NET=(); T_NEW=(); T_REST=(); T_PAUSED=()

# 2.64: a container the user paused (docker pause, Unraid's Pause) is left exactly as it is - never stopped, never started,
# so it is paused again afterwards (up to 2.63 docker's State.Running, true for a paused one, had it stopped and started:
# it ran after the run). Its processes are frozen: its files don't change while the snapshot is taken (as a paused VM's
# disks); a dump or a SQLite copy can't be made of it (exec refused) - the package keeps the last one
build_stop_tiers() {
    local n prov
    local -A provider=() isdb=()
    T_APP=(); T_DB=(); T_NET=(); T_NEW=(); T_REST=(); T_PAUSED=()
    for n in "${CT_NAMES[@]}"; do
        [[ "${CT_PAUSED[$n]:-}" == "true" && "$n" != "$KOPIA_CONTAINER" ]] && T_PAUSED+=( "$n" )
    done
    [[ "$DOCKER_STOP" == "none" ]] && return 0
    for n in "${CT_NAMES[@]}"; do
        [[ "${CT_NET[$n]}" == container:* ]] || continue
        prov="$(ct_resolve "${CT_NET[$n]#container:}")" && provider[$prov]=1
    done
    for n in $(cfg_names dump); do isdb[$n]=1; done
    for n in "${CT_NAMES[@]}"; do
        [[ "${CT_RUNNING[$n]}" == "true" ]] || continue
        [[ "$n" == "$KOPIA_CONTAINER" ]] && continue
        [[ "${CT_PAUSED[$n]:-}" == "true" ]] && continue         # paused by the user: left as it is (2.64)
        in_list "$n" "${DOCKER_NO_STOP[@]}" "${DOCKER_SKIP[@]}" && continue
        # a container the setup hasn't seen ([docker] known) keeps running until the user decided (2.21)
        if (( ${#DOCKER_KNOWN[@]} )) && ! in_list "$n" "${DOCKER_KNOWN[@]}"; then T_NEW+=( "$n" ); continue; fi
        # all its backed-up data on pools left out this run (2.28, asleep_pools = skip): stopping it changes no snapshot
        if ct_rests "$n"; then T_REST+=( "$n" ); ST_ASLEEP_CTS+=( "$n" ); continue; fi
        if [[ -n "${provider[$n]:-}" ]]; then T_NET+=( "$n" )
        elif [[ -n "${isdb[$n]:-}" || -n "$(ct_db_type "$n")" ]]; then T_DB+=( "$n" )
        else T_APP+=( "$n" ); fi
    done
}

# Notes in state/: if a run is killed hard (kill -9, crash), the next start
# of backup.sh/setup.sh brings the services back.
RUN_NOTED="no"              # this run wrote notes of its own (save_restore_state) - only then are they its to rewrite
save_restore_state() {
    RUN_NOTED="yes"
    if [[ ${#STOPPED[@]} -gt 0 ]]; then printf '%s\n' "${STOPPED[@]}" >"$UB_STATE/stopped"; else rm -f "$UB_STATE/stopped"; fi
    if [[ ${#NC_ON[@]} -gt 0 ]]; then printf '%s\n' "${!NC_ON[@]}" >"$UB_STATE/maintenance"; else rm -f "$UB_STATE/maintenance"; fi
    # 2.65: their data folders as the run found them (lib/common.sh nc_reclose) - written after state/maintenance, never older
    local c; : >"$UB_STATE/.nc-datadir.$$"
    for c in "${!NC_ON[@]}"; do [[ -n "${NC_DIR[$c]:-}" ]] && printf '%s\t%s\n' "$c" "${NC_DIR[$c]}" >>"$UB_STATE/.nc-datadir.$$"; done
    if [[ -s "$UB_STATE/.nc-datadir.$$" ]]; then mv -f "$UB_STATE/.nc-datadir.$$" "$UB_STATE/nc-datadir"; else rm -f "$UB_STATE/.nc-datadir.$$" "$UB_STATE/nc-datadir"; fi
    local v; : >"$UB_STATE/.vms.$$"
    for v in "${!VM_HELD[@]}"; do printf '%s|%s\n' "$v" "${VM_HELD[$v]}" >>"$UB_STATE/.vms.$$"; done
    if [[ -s "$UB_STATE/.vms.$$" ]]; then mv -f "$UB_STATE/.vms.$$" "$UB_STATE/vms"; else rm -f "$UB_STATE/.vms.$$" "$UB_STATE/vms"; fi
}

##############################################################################
# VMs around the snapshots
##############################################################################
# A running VM's disks change while the snapshot is taken. [vm "<name>"] prepare:
#   freeze    the guest agent flushes and freezes the file systems inside (seconds)
#   pause     the VM stops for the seconds of the snapshot (no guest agent needed)
#   shutdown  shut down cleanly before, started again after (minutes)
#   none      keeps running - its disks are only crash-consistent (like pulling the plug)
# Shutdowns come first (2.22): requested together and waited for while everything else still
# runs, before Nextcloud's maintenance mode and the apps stop - their downtime never includes a
# guest taking minutes or ignoring the request (vm_shutdowns). Freezing and pausing - also of a VM
# that wasn't off by its deadline - come right before the snapshots (vm_hold), and each VM is
# released right after the snapshot that holds its disks (ZFS first, btrfs after). Noted in
# state/vms before acting: a run stopped or killed starts again what it shut down or paused.
declare -A VM_HELD=()     # name -> frozen | paused | shutdown (asked to shut down: off, going down or ignoring it)
declare -A VM_HELD_AT=()  # name -> since when (s): the shutdown request, the freeze, the pause
declare -a VM_TODO=()     # running VMs whose disks this run snapshots, prepare != none
declare -A VM_SHUT_ASKED=()  # name -> 1: the shutdown request went out (vm_hold_begin; from before the request - see there)
declare -A VM_SHUT_DOWN=()   # name -> 1: seen shut off (vm_shutdown_wait, vm_hold)

vm_plan() {
    local n p
    VM_TODO=(); ST_VMS=()
    for n in "${VM_NAMES[@]}"; do
        p="$(vm_prepare "$n")"
        if [[ "$(vm_mode "$n")" == "off" ]]; then ST_VMS+=( "$n|$p|off|0|0" ); continue; fi
        # a disk on a pool left out this run (2.28): not snapshotted tonight - so neither frozen, paused nor shut down
        if asleep_vm "$n"; then ST_VMS+=( "$n|$p|asleep|0|0" ); ST_ASLEEP_VMS+=( "$n" ); continue; fi
        if ! vm_snapshotted "$n"; then ST_VMS+=( "$n|$p|kept_running|0|0" ); continue; fi
        if [[ "${VM_STATE[$n]}" != "running" ]]; then ST_VMS+=( "$n|$p|not_running|0|1" ); continue; fi
        if [[ "$p" == "none" ]]; then ST_VMS+=( "$n|$p|kept_running|0|1" ); continue; fi
        VM_TODO+=( "$n" ); ST_VMS+=( "$n|$p|planned|0|1" )
    done
}

vm_note() { # vm_note <name> <prepare> <done> <seconds>  - replaces the VM's line in ST_VMS
    local -a keep=(); local l
    for l in "${ST_VMS[@]}"; do [[ "${l%%|*}" == "$1" ]] || keep+=( "$l" ); done
    ST_VMS=( "${keep[@]}" "$1|$2|$3|$4|1" ); status_write
}

vm_domstate() { timeout 10 virsh domstate "$1" 2>/dev/null | head -1; }

# The VMs to shut down, before anything else stops: requested together, then waited for. A run
# without such a VM does nothing here (no phase, no line).
vm_shutdowns() {
    local n k=0
    for n in "${VM_TODO[@]}"; do [[ "$(vm_prepare "$n")" == "shutdown" ]] && k=$((k+1)); done
    (( k > 0 )) || return 0
    next_phase "vm_shutdown"
    log "VMs: shutting down $k before anything stops (the apps keep running meanwhile; waiting at most ${VM_SHUTDOWN_TIMEOUT} s)"
    vm_hold_begin
    vm_shutdown_wait
}

vm_hold_begin() { # the shutdown requests, all together
    local n
    for n in "${VM_TODO[@]}"; do
        [[ "$(vm_prepare "$n")" == "shutdown" ]] || continue
        # noted before asking (also when the request fails - it may have reached the guest): if it
        # goes off, the trap or the next run starts it again
        VM_HELD[$n]="shutdown"; VM_HELD_AT[$n]="$(date +%s)"; save_restore_state
        # asked counts from before the request: a stop (SIGTERM) while virsh runs is handled right
        # after it returns, before any line after it - set only on success, a run stopped then never
        # waited for the VM going down, and it stayed off (vm_release: «nothing to start»; seen in
        # testBackupVmOrder, 2026-10-09). A request that fails takes it back.
        VM_SHUT_ASKED[$n]=1
        if timeout 30 virsh shutdown "$n" >/dev/null 2>>"$LOG_FILE"; then log "  VM '$n': shutting down"
        else
            unset "VM_SHUT_ASKED[$n]"
            warn_code vm_shutdown_refused "VM '$n' did not take the shutdown request - it keeps running and is paused for the snapshot instead" name="$n"
        fi
    done
}

# vm_shutdown_wait [abort]
# The shutdowns were all requested together (vm_hold_begin), so they share one deadline: each VM
# its request time + VM_SHUTDOWN_TIMEOUT, all polled in one loop - at most one timeout in all,
# not one after the other. A VM that didn't take the request isn't waited for. While waiting, a VM
# still running gets the request again every VM_SHUTDOWN_RETRY seconds: Windows swallows the first
# ACPI power button event while idle with the display off (harmless while a guest shuts down).
# One not off by its deadline keeps running until vm_hold pauses it (never forced off) - the warning comes there (2.42):
# one that goes off meanwhile (while the apps stop) was shut down as set up.
# abort: a run stopped early waits only for the VMs still going down after its request, until their
# deadline, so that vm_release can start them again - no new requests.
vm_shutdown_wait() {
    local n now mode="${1:-}"
    local -a pending=() left=()
    local -A last=() told=() deadline=()
    for n in "${VM_TODO[@]}"; do
        [[ "${VM_HELD[$n]:-}" == "shutdown" && -n "${VM_SHUT_ASKED[$n]:-}" && -z "${VM_SHUT_DOWN[$n]:-}" ]] || continue
        last[$n]="${VM_HELD_AT[$n]:-$(date +%s)}"; deadline[$n]=$(( ${last[$n]} + VM_SHUTDOWN_TIMEOUT ))
        [[ "$mode" == "abort" ]] && (( $(date +%s) >= ${deadline[$n]} )) && continue    # past it: it ignored the request
        pending+=( "$n" )
    done
    (( ${#pending[@]} > 0 )) || return 0
    [[ "$mode" == "abort" ]] && log "  Waiting for ${#pending[@]} VM(s) going down, to start them again (at most until ${VM_SHUTDOWN_TIMEOUT} s after the request)"
    while (( ${#pending[@]} > 0 )); do
        [[ "$mode" == "abort" ]] || array_stop_check "while VMs shut down"
        left=()
        for n in "${pending[@]}"; do
            if [[ "$(vm_domstate "$n")" == "shut off" ]]; then
                VM_SHUT_DOWN[$n]=1; log "  VM '$n': shut down"; continue
            fi
            now="$(date +%s)"
            if (( now >= ${deadline[$n]} )); then
                [[ "$mode" == "abort" ]] \
                    || log "  VM '$n' did not shut down within ${VM_SHUTDOWN_TIMEOUT} s of the request - it keeps running and is paused for the snapshot unless it goes off by then"
                continue
            fi
            left+=( "$n" )
            [[ "$mode" == "abort" ]] && continue
            if (( now - ${last[$n]} >= VM_SHUTDOWN_RETRY )); then
                last[$n]="$now"
                timeout 30 virsh shutdown "$n" >/dev/null 2>>"$LOG_FILE" \
                    && [[ -z "${told[$n]:-}" ]] && { told[$n]=1; log "  VM '$n': still running - asked again to shut down (every ${VM_SHUTDOWN_RETRY} s)"; }
            fi
        done
        pending=( "${left[@]}" )
        (( ${#pending[@]} > 0 )) && sleep 2
    done
    return 0
}

vm_hold() { # right before the snapshots: freeze, pause - also a VM that wasn't off by its deadline
    local n p
    for n in "${VM_TODO[@]}"; do
        p="$(vm_prepare "$n")"
        if [[ "$p" == "shutdown" ]]; then
            [[ -n "${VM_SHUT_DOWN[$n]:-}" ]] && continue
            # off after all, later than its deadline (it went down while the apps stopped): it stays
            # off for the snapshot and is started again like the others
            if [[ "$(vm_domstate "$n")" == "shut off" ]]; then
                VM_SHUT_DOWN[$n]=1; log "  VM '$n': shut down (after its deadline)"; continue
            fi
            # never forced off: paused instead, the guest decides about its own shutdown. It ran
            # until now - held from the pause on. A VM that didn't take the request was warned about then
            [[ -n "${VM_SHUT_ASKED[$n]:-}" ]] && warn_code vm_not_shut_down \
                "VM '$n' did not shut down within ${VM_SHUTDOWN_TIMEOUT} s of the request - paused for the snapshot instead" name="$n" seconds="$VM_SHUTDOWN_TIMEOUT"
            unset "VM_HELD_AT[$n]"; p="pause"
        fi
        if [[ "$p" == "freeze" ]]; then
            VM_HELD[$n]="frozen"; VM_HELD_AT[$n]="$(date +%s)"; save_restore_state
            if timeout 60 virsh domfsfreeze "$n" >/dev/null 2>>"$LOG_FILE"; then log "  VM '$n': file systems frozen"; continue; fi
            warn_code vm_not_frozen "VM '$n': the guest agent did not freeze its file systems - paused instead" name="$n"
            # 2.48: a freeze that ran into its time may have frozen some of them after all - thawed before the pause
            timeout 30 virsh domfsthaw "$n" >/dev/null 2>&1
            unset "VM_HELD[$n]"; p="pause"
        fi
        if [[ "$p" == "pause" ]]; then
            VM_HELD[$n]="paused"; VM_HELD_AT[$n]="${VM_HELD_AT[$n]:-$(date +%s)}"; save_restore_state
            if timeout 30 virsh suspend "$n" >/dev/null 2>>"$LOG_FILE"; then log "  VM '$n': paused"
            else
                warn_code vm_not_paused "VM '$n' could not be paused - it keeps running (crash-consistent)" name="$n"
                unset "VM_HELD[$n]" "VM_HELD_AT[$n]"; save_restore_state
                vm_note "$n" "$(vm_prepare "$n")" "failed" 0
            fi
        fi
    done
}

vm_release() { # vm_release [btrfs]  - without an argument the VMs on ZFS only
    local n how secs st
    for n in "${!VM_HELD[@]}"; do
        if [[ "${1:-}" != "btrfs" ]] && vm_on_btrfs "$n"; then continue; fi
        how="${VM_HELD[$n]}"
        case "$how" in
            # 2.48: a guest left frozen writes nothing until someone thaws it - a second try, then an alert like a paused one
            frozen)   timeout 60 virsh domfsthaw "$n" >/dev/null 2>>"$LOG_FILE" \
                          || { sleep 5; timeout 60 virsh domfsthaw "$n" >/dev/null 2>>"$LOG_FILE"; } \
                          || { warn "VM '$n': thawing its file systems failed - check the VM"
                               ub_tell vm_not_thawed alert "" "VM still frozen after the backup" "'$n' may still be frozen after the snapshot and write nothing. Restart it on the VMs page (or in a terminal: virsh domfsthaw $n)." "vm=$n"; } ;;
            paused)   timeout 30 virsh resume "$n" >/dev/null 2>>"$LOG_FILE" \
                          || { warn "VM '$n' could NOT be resumed"; ub_tell vm_not_resumed alert "" "VM still paused after the backup" "'$n' is still paused after the snapshot. Resume it on the VMs page (or in a terminal: virsh resume $n)." "vm=$n"; } ;;
            shutdown) st="$(vm_domstate "$n")"
                      if [[ "$st" != "shut off" ]]; then
                          # only when a run stops before vm_hold: it never went down (or not by its deadline)
                          log "  VM '$n': not shut down (${st:-state unknown}) - nothing to start"
                          unset "VM_HELD[$n]" "VM_HELD_AT[$n]"; continue
                      fi
                      timeout 60 virsh start "$n" >/dev/null 2>>"$LOG_FILE" \
                          || { warn "VM '$n' could NOT be started"; ub_tell vm_not_started alert "" "VM not running after the backup" "'$n' could not be started again after the snapshot. Start it on the VMs page." "vm=$n"; } ;;
        esac
        secs=$(( $(date +%s) - ${VM_HELD_AT[$n]:-$(date +%s)} ))
        log "  VM '$n': released ($how, ${secs} s)"
        vm_note "$n" "$(vm_prepare "$n")" "$how" "$secs"
        unset "VM_HELD[$n]" "VM_HELD_AT[$n]"
    done
    save_restore_state
}

# --- btrfs snapshots ------------------------------------------------------
btrfs_snap() { # btrfs_snap <disk path> [note]
    local base="$1"
    BTRFS_TRIED[$base]=1
    mkdir -p "$base/$BTRFS_SNAP_DIR"
    if btrfs subvolume snapshot -r "$base" "$base/$BTRFS_SNAP_DIR/$TS" >/dev/null 2>>"$LOG_FILE"; then
        BTRFS_OK[$base]="$base/$BTRFS_SNAP_DIR/$TS"
        log "  btrfs $base${2:+ ($2)}"
    else
        err "btrfs snapshot of $base failed"
    fi
}

# results_local_json - state/results.json's local part of this run (2.80, results_write in lib/common.sh): per share
# with a snapshot method in the plan [{key: "share:<s>", result, code}] - ok when every part got this run's snapshot
# (ZFS: its pool's snapshot went, btrfs: its base's), failed (snapshot) when one didn't, skipped asleep (left out, its
# pool slept), skipped run_ended (the run ended before its snapshots: ZFS's all at once, btrfs one base after the other).
# The VMs come from status.json (vms[].snapshot).
results_local_json() {
    local s b m layer sub res code
    for s in "${PLAN_SNAP[@]}"; do
        [[ -n "$s" ]] || continue
        res=ok; code=""
        if in_list "$s" "${ST_ASLEEP_SHARES[@]}"; then res=skipped; code=asleep
        elif [[ "$SNAPS_TAKEN" != "yes" ]]; then res=skipped; code=run_ended
        else
            while IFS='|' read -r b m layer sub; do
                [[ -n "$b" ]] || continue
                case "$m" in
                    zfs)   in_list "$layer" "${PLAN_ZFS[@]}" && [[ -z "${ZFS_FAILED[${layer%%/*}]:-}" ]] || { res=failed; code=snapshot; } ;;
                    btrfs) if [[ -z "${BTRFS_OK[$layer]:-}" ]]; then
                               if [[ -n "${BTRFS_TRIED[$layer]:-}" ]]; then res=failed; code=snapshot
                               elif [[ "$res" == ok ]]; then res=skipped; code=run_ended; fi
                           fi ;;
                esac
            done <<<"${INV_LOCS[$s]:-}"
        fi
        printf '%s\x1f%s\x1f%s\n' "share:$s" "$res" "$code"
    done | jq -Rn '[inputs | select(length > 0) | split("\u001f") | {key: .[0], result: .[1], code: .[2]}]'
}

# The share a bind source lies in (/mnt/user/<share>/…, /mnt/<disk or pool>/<share>/…)
src_share() { # src_share <path>
    local p="${1%/}" r="" b
    if [[ "$p" == "$UB_MNT/user/"* || "$p" == "$UB_MNT/user0/"* ]]; then r="${p#"$UB_MNT"/user/}"; r="${r#"$UB_MNT"/user0/}"
    else
        for b in "${INV_BASES[@]}"; do [[ "$p" == "${INV_BASE_PATH[$b]}/"* ]] && { r="${p#"${INV_BASE_PATH[$b]}"/}"; break; }; done
    fi
    [[ -n "$r" ]] && printf '%s' "${r%%/*}"
}

# BTRFS_NEED[disk path]: btrfs disks with data of a stopped container, a held VM or the backup place
declare -A BTRFS_NEED=()
btrfs_needed() {
    local n src s t b fs share
    local -A shares=()
    for n in "${STOPPED[@]}"; do
        while IFS='|' read -r src _ _; do
            [[ -z "$src" ]] && continue
            s="$(src_share "$src")" && [[ -n "$s" ]] && shares[$s]=1
        done <<<"${CT_BINDS[$n]:-}"
    done
    for n in "${VM_TODO[@]}"; do
        while IFS='|' read -r t src b fs _ share; do [[ -n "$share" ]] && shares[$share]=1; done <<<"${VM_DISKS[$n]:-}"
    done
    [[ -n "${DUMPS_SHARE:-}" ]] && shares[$DUMPS_SHARE]=1
    for s in "${!shares[@]}"; do
        while IFS='|' read -r b m layer _; do [[ "$m" == "btrfs" ]] && BTRFS_NEED[$layer]=1; done <<<"${INV_LOCS[$s]:-}"
    done
    return 0
}

stop_tier() { # stop_tier <name...>
    [[ $# -eq 0 ]] && return 0
    local n
    # Write them down BEFORE stopping: if the run ends in the middle of it (signal,
    # kill -9), the trap or the next run starts exactly these containers.
    # "docker start" on a running container does no harm.
    STOPPED+=( "$@" ); save_restore_state
    docker stop -t "$DOCKER_STOP_TIMEOUT" "$@" >/dev/null 2>>"$LOG_FILE"
    for n in "$@"; do
        if [[ "$(docker inspect -f '{{.State.Running}}' "$n" 2>/dev/null)" != "false" ]]; then
            warn "Container '$n' would not stop - it keeps running during the snapshot"
            local -a remaining=(); local x
            for x in "${STOPPED[@]}"; do [[ "$x" != "$n" ]] && remaining+=( "$x" ); done
            STOPPED=( "${remaining[@]}" )
        fi
    done
    save_restore_state
}

# wait_ready <seconds> <name...>: lib/common.sh (a --recover starts noted containers the same way, 2.25)
restore_service() {
    local tier n started c i out rc
    if [[ ${#STOPPED[@]} -gt 0 ]]; then
        for tier in NET DB APP; do
            if [[ "$ARRAY_STOP" == "yes" ]] || array_stopping; then
                ARRAY_STOP="yes"
                log "  The array is being stopped - the containers not started yet stay stopped (state/stopped)"
                return 0
            fi
            local -n T="T_$tier"
            started=()
            for n in "${T[@]}"; do
                in_list "$n" "${STOPPED[@]}" || continue
                if docker start "$n" >/dev/null 2>>"$LOG_FILE"; then
                    started+=( "$n" )
                else
                    warn "Container '$n' would NOT start"
                    ub_tell app_not_started alert "" "Container not running after the backup" "'$n' could not be started again after the snapshot. Start it on the Docker page." "app=$n"
                fi
            done
            unset -n T
            [[ "$tier" != "APP" && ${#started[@]} -gt 0 ]] && \
                { wait_ready 120 "${started[@]}" || array_stopping || warn "Not all $tier containers are ready after 120 s"; }
        done
        log "  Containers started: ${#STOPPED[@]}"
        STOPPED=()
        save_restore_state
    fi

    for c in "${!NC_ON[@]}"; do
        for i in $(seq 1 60); do
            out="$(nc_occ "$c" status 2>&1)" && break
            grep -q "readable by other people" <<<"$out" && break      # stays so until the folder is closed (2.65)
            array_stopping && break
            sleep 2
        done
        out="$(nc_occ "$c" maintenance:mode --off 2>&1)"; rc=$?
        # 2.65: its data folder opened meanwhile (Unraid: Add Share or Apply of any share) - closed again as the run found it
        if (( rc )) && grep -q "readable by other people" <<<"$out" && nc_reclose "$c"; then
            out="$(nc_occ "$c" maintenance:mode --off 2>&1)"; rc=$?
        fi
        if (( rc == 0 )); then
            log "  Nextcloud '$c': maintenance mode off"
            unset "NC_ON[$c]"
            save_restore_state
        elif array_stopping; then
            ARRAY_STOP="yes"
            log "  Nextcloud '$c': maintenance mode stays on (the array is being stopped) - noted (state/maintenance) for after the array start"
        else
            nc_log_output "$out"
            warn "Nextcloud '$c': maintenance mode would NOT switch off"
            ub_tell nc_stuck alert "" "Nextcloud still in maintenance mode" "Nextcloud '$c' is still in maintenance mode after the backup, so nobody can get in. Switch it off in the container's console (occ maintenance:mode --off)." "app=$c"
        fi
    done
}

##############################################################################
# Mounts
##############################################################################
# umount_tree <root> [lazy|now]  - deepest first; 1 if something stayed mounted
#   lazy  the array is being stopped (2.25): what is still busy after the normal attempt (a Kopia in the container
#         that didn't end, an orphan of a killed run) is detached (umount -l) - the mount point goes at once, the
#         file system once its last user lets go (Docker ends the container in the stop), so the pool can go
#   now   the same without the second try 2 s later (the plugin's array-stop hook: --unmount with UB_ARRAY_STOP=1,
#         a few seconds in all)
umount_tree() {
    local root="$1" how="${2:-}" mp rc=0
    while IFS= read -r mp; do
        [[ -z "$mp" ]] && continue
        umount "$mp" 2>/dev/null && continue
        if [[ "$how" != "now" ]]; then
            sleep 2
            umount "$mp" 2>>"$LOG_FILE" && continue
        fi
        if [[ -n "$how" ]] && umount -l "$mp" 2>>"$LOG_FILE"; then
            log "  $mp was busy - detached (umount -l): it is gone once its last user lets go"
            continue
        fi
        warn "Could not unmount $mp (busy?)"; rc=1
    done < <(mounts_below "$root")
    [[ -d "$root" ]] && find "$root" -xdev -mindepth 1 -depth -type d -empty -delete 2>/dev/null
    return $rc
}

unmount_all() { # unmount_all [lazy|now]  - everything of the engine: <mount_root>, <view_root>, its staging area
    local rc=0 how="${1:-}"
    if [[ -n "$(mounts_below "$MOUNT_ROOT")" ]]; then
        log "Unmounting snapshots under $MOUNT_ROOT ..."
        umount_tree "$MOUNT_ROOT" "$how" || rc=1
    fi
    # Only symlinks belong in <view_root> - mounts there (e.g. from an
    # earlier script) would hold disks and are released
    if [[ -n "$(mounts_below "$VIEW_ROOT")" ]]; then
        log "Releasing old bind mounts under $VIEW_ROOT ..."
        umount_tree "$VIEW_ROOT" "$how" || rc=1
    fi
    if [[ -n "$(mounts_below "$UB_STAGE")" ]]; then
        umount_tree "$UB_STAGE" "$how" || rc=1
    fi
    MOUNTED="no"; LAYER_MNT=(); SHARE_MOUNTED=()
    return $rc
}

# The way out of every run holding the lock (2.25): while the array is being stopped nothing of the engine stays
# mounted - neither what this run mounted nor what keep_mounts or a killed run left. The plugin's array-stop hook
# (agent.sh backup_release) leaves the engine's mounts to a live backup run, check or dry run: this is where it
# keeps that promise, whatever MOUNTED says. The cleanup trap does the same (cleanup); a check or dry run that
# ends normally comes through here.
run_exit() {
    [[ -n "${MOUNT_ROOT:-}" ]] && array_stopping && unmount_all lazy
    ub_holder_clear
}

# Before 2.14 mount_root and view_root were folders directly in /mnt (backup-snapshots,
# btrfs-snap) - Fix Common Problems rightly complains. Once settings.ini points elsewhere
# and nothing is mounted there any more, they go.
legacy_dirs_remove() {
    local d
    for d in "$UB_MNT/backup-snapshots" "$UB_MNT/btrfs-snap"; do
        [[ -d "$d" && ! -L "$d" && "$d" != "$MOUNT_ROOT" && "$d" != "$VIEW_ROOT" ]] || continue
        mountpoint -q "$d" && continue
        [[ -z "$(mounts_below "$d")" ]] || continue
        find "$d" -mindepth 1 -maxdepth 1 -type l -delete 2>/dev/null          # btrfs-snap: only symlinks
        find "$d" -xdev -mindepth 1 -depth -type d -empty -delete 2>/dev/null  # backup-snapshots: empty mount points
        rmdir "$d" 2>/dev/null && log "  Old folder $d removed (now under $UB_MNT/addons)"
    done
    return 0
}

# Private staging area (created once per boot, stays).
# What is mounted here does not travel into other mount namespaces.
stage_ready() {
    if ! mountpoint -q "$UB_STAGE"; then
        mkdir -p "$UB_STAGE" || return 1
        mount -t tmpfs -o size=1m,mode=0700 "$UB_NAME-stage" "$UB_STAGE" || return 1
    fi
    mount --make-private "$UB_STAGE" || return 1
    mkdir -p "$UB_STAGE/layers" "$UB_STAGE/bind"
}

# A read-only bind that also arrives read-only in the Kopia container.
# A bind created directly under <mount_root> is passed on to the container
# writable - the later "remount,ro" does not reach it.
# Hence: bind in the private staging area, make it ro there and only
# then move it to <target>. The copy in the container is ro from the start.
ro_bind() { # ro_bind <source> <target>
    local st="$UB_STAGE/bind/b$$-$RANDOM"
    mkdir -p "$st" "$2" || return 1
    mount --bind "$1" "$st"                 || { rmdir "$st"; return 1; }
    mount --make-private "$st"              || { umount "$st"; rmdir "$st"; return 1; }
    mount -o remount,ro,bind "$st"          || { umount "$st"; rmdir "$st"; return 1; }
    mount --move "$st" "$2"                 || { umount "$st"; rmdir "$st"; return 1; }
    rmdir "$st" 2>/dev/null
    return 0
}

zfs_layer_mount() { # mounts <dataset>@SNAP in the staging area, prints the path
    local ds="$1" mp
    if [[ -n "${LAYER_MNT[$ds]:-}" ]]; then printf '%s' "${LAYER_MNT[$ds]}"; return 0; fi
    mp="$UB_STAGE/layers/${ds//\//_}"
    mkdir -p "$mp" || return 1
    mount -t zfs -o ro "$ds@$SNAP_NAME" "$mp" 2>>"$LOG_FILE" || return 1
    LAYER_MNT[$ds]="$mp"
    printf '%s' "$mp"
}

# Path of one location of a share inside the snapshot (for overlay)
loc_snap_path() { # loc_snap_path <share> <location line>
    local s="$1" b m layer sub lp
    IFS='|' read -r b m layer sub <<<"$2"
    case "$m" in
        zfs)   lp="$(zfs_layer_mount "$layer")" || return 1
               if [[ -z "$sub" ]]; then printf '%s' "$lp"; else printf '%s' "$lp/$sub"; fi ;;
        btrfs) [[ -n "${BTRFS_OK[$layer]:-}" ]] || return 1
               printf '%s' "${BTRFS_OK[$layer]}/$sub" ;;
        *)     return 1 ;;
    esac
}

# Mount one location of a share, child datasets included, at <target>
mount_location() { # mount_location <share> <location line> <target>
    local s="$1" line="$2" target="$3" b m layer sub src
    IFS='|' read -r b m layer sub <<<"$line"
    if [[ "$m" == "zfs" && -z "$sub" && -z "${LAYER_MNT[$layer]:-}" ]]; then
        [[ -n "${ZFS_FAILED[${layer%%/*}]:-}" ]] && return 1
        mkdir -p "$target" && mount -t zfs -o ro "$layer@$SNAP_NAME" "$target" 2>>"$LOG_FILE" || return 1
    else
        # Already mounted in the staging area (overlay attempt) or a subfolder
        # of a pool's root dataset -> bind from there. The snapshot itself
        # cannot change, binding weakens nothing.
        [[ "$m" == "zfs" && -n "${ZFS_FAILED[${layer%%/*}]:-}" ]] && return 1
        src="$(loc_snap_path "$s" "$line")" || return 1
        [[ -d "$src" ]] || return 1
        ro_bind "$src" "$target" || return 1
    fi
    local cb cds cmp rel base="${INV_BASE_PATH[$b]}/$s"
    while IFS='|' read -r cb cds cmp; do
        [[ "$cb" == "$b" ]] || continue
        rel="${cmp#"$base"/}"
        if [[ -d "$target/$rel" ]]; then
            mount -t zfs -o ro "$cds@$SNAP_NAME" "$target/$rel" 2>>"$LOG_FILE" \
                || warn "Child dataset $cds could not be mounted"
        else
            warn "Child dataset $cds: mount point '$rel' is missing in the snapshot of $s"
        fi
    done < <(plan_children "$s")
    return 0
}

mount_share() { # mount_share <share>
    local s="$1" target="$MOUNT_ROOT/$1" line layout lowers=() p opt ok
    local -a locs
    mapfile -t locs < <(printf '%s' "${INV_LOCS[$s]}" | sed '/^$/d')
    layout="${INV_LAYOUT[$s]}"
    [[ "$(share_method "$s")" == "live" ]] && layout="live"

    if [[ "$layout" == "live" ]]; then
        # No snapshot possible: the live share, bound read-only
        ro_bind "$UB_MNT/user/$s" "$target" && { SHARE_MOUNTED[$s]="live"; return 0; }
        return 1
    fi

    if [[ "$layout" == "overlay" ]]; then
        for line in "${locs[@]}"; do
            p="$(loc_snap_path "$s" "$line")" || { lowers=(); break; }
            [[ -d "$p" ]] || { lowers=(); break; }
            lowers+=( "$(ovl_escape "$p")" )
        done
        if [[ ${#lowers[@]} -ge 2 ]]; then
            opt="ro,lowerdir=$(IFS=:; echo "${lowers[*]}")"
            if mkdir -p "$target" && mount -t overlay "ub-$s" -o "$opt" "$target" 2>>"$LOG_FILE"; then
                SHARE_MOUNTED[$s]="overlay"; return 0
            fi
        fi
        warn "Share '$s': overlay not possible - Kopia sees one subfolder per base"
        layout="split"
    fi

    if [[ "$layout" == "single" ]]; then
        mount_location "$s" "${locs[0]}" "$target" && { SHARE_MOUNTED[$s]="single"; return 0; }
        return 1
    fi

    # split: <mount_root>/<share>/<base>
    ok=0
    for line in "${locs[@]}"; do
        mount_location "$s" "$line" "$target/${line%%|*}" && ok=$((ok+1)) \
            || warn "Share '$s': location ${line%%|*} could not be mounted"
    done
    (( ok == ${#locs[@]} )) && { SHARE_MOUNTED[$s]="split"; return 0; }
    return 1
}

share_mount_points() { # mount points that must exist for a share
    local s="$1" line
    case "${SHARE_MOUNTED[$s]:-}" in
        single|overlay|live) printf '%s\n' "$MOUNT_ROOT/$s" ;;
        split) while IFS= read -r line; do [[ -n "$line" ]] && printf '%s\n' "$MOUNT_ROOT/$s/${line%%|*}"; done <<<"${INV_LOCS[$s]}" ;;
    esac
}

# --- the own sources of apps and VMs (lib/common.sh, section 9) ----------------
# <mount_root>/.apps/<name>/<share>/<path>: read-only binds of its folders and its package out of the
# shares' mounted snapshots - and of whatever is mounted below them (child datasets)
declare -A ITEM_MPS=()        # "kind:name" -> its mount points (lines)
declare -A ITEM_PARTS=()      # "kind:name" -> how many of its parts are in
item_bind() { # item_bind <key> <source> <target>  - 0 bound, 1 not there or failed, 2 empty
    local key="$1" src="$2" dst="$3" mp
    [[ -d "$src" ]] || return 1
    # never an empty folder (a child dataset left out shows as one): Kopia would keep it as a state and
    # age out the good ones. An app whose folder is simply empty (a tunnel with a token) is no problem:
    # a note in the log, not a warning
    if [[ -z "$(ls -A "$src" 2>/dev/null)" ]]; then log "  Kopia source of ${key/:/ }: $src is empty in this run's snapshot - nothing to back up there"; return 2; fi
    ro_bind "$src" "$dst" || { warn "Kopia source of ${key/:/ }: $src could not be bound"; return 1; }
    ITEM_MPS[$key]+="$dst"$'\n'
    # the binds are not recursive: the child datasets below the folder are bound one by one, the upper first
    while IFS= read -r mp; do
        [[ -z "$mp" ]] && continue
        if ro_bind "$mp" "$dst/${mp#"$src"/}"; then ITEM_MPS[$key]+="$dst/${mp#"$src"/}"$'\n'
        else warn "Kopia source of ${key/:/ }: $mp (mounted below $src) could not be bound"; fi
    done < <(mounts_below "$src" | awk '{print length($0) "\t" $0}' | sort -n | cut -f2-)
    return 0
}
# item_pkg <kind> <name>  -> its package folder in this run ("-" when it has none)
item_pkg() {
    local f
    if [[ "$1" == "app" ]]; then for f in "${PKG_APPS[@]}"; do [[ "${PKG_APP_NAME[$f]}" == "$2" ]] && { printf '%s' "$f"; return; }; done
    else for f in "${PKG_VMS[@]}"; do [[ "${PKG_VM_NAME[$f]}" == "$2" ]] && { printf '%s' "$f"; return; }; done; fi
    printf -- '-'
}
mount_item() { # mount_item <kind> <name> <folder>
    local t="$1" n="$2" f="$3" key="$1:$2" root sh rel b got empty rc
    root="$(item_hostpath "$t" "$f")"
    ITEM_MPS[$key]=""; ITEM_PARTS[$key]=0
    while IFS='|' read -r sh rel; do
        [[ -z "$sh" ]] && continue
        if [[ -z "${SHARE_MOUNTED[$sh]:-}" ]]; then
            warn "Kopia source of $t '$n': share '$sh' is not mounted - $sh/$rel is left out"; continue
        fi
        got=0; empty=0
        if [[ "${SHARE_MOUNTED[$sh]}" == "split" ]]; then
            while IFS='|' read -r b _; do
                [[ -n "$b" ]] || continue
                item_bind "$key" "$MOUNT_ROOT/$sh/$b/$rel" "$root/$sh/$b/$rel"; rc=$?
                if (( rc == 0 )); then got=1; elif (( rc == 2 )); then empty=1; fi
            done <<<"${INV_LOCS[$sh]:-}"
        else
            item_bind "$key" "$MOUNT_ROOT/$sh/$rel" "$root/$sh/$rel"; rc=$?
            if (( rc == 0 )); then got=1; elif (( rc == 2 )); then empty=1; fi
        fi
        # an empty folder was said above, once
        if (( got )); then ITEM_PARTS[$key]=$(( ${ITEM_PARTS[$key]} + 1 ))
        elif (( ! empty )); then warn "Kopia source of $t '$n': $sh/$rel is not in this run's snapshot"; fi
    done < <(kopia_item_parts "$t" "$n" "$(item_pkg "$t" "$n")")
    (( ${ITEM_PARTS[$key]} > 0 ))
}

##############################################################################
# Nextcloud
##############################################################################
nc_find_occ() { # sets NC_OCC[c], NC_USER[c]
    local c="$1" p u
    for p in /var/www/html/occ /app/www/public/occ /config/www/nextcloud/occ /var/www/nextcloud/occ; do
        if docker exec "$c" test -f "$p" 2>/dev/null; then
            NC_OCC[$c]="$p"
            u="$(docker exec "$c" stat -c %U "$p" 2>/dev/null)"
            [[ -z "$u" || "$u" == "UNKNOWN" || "$u" == "root" ]] && u="www-data"
            NC_USER[$c]="$u"
            return 0
        fi
    done
    return 1
}
nc_occ() { local c="$1"; shift; docker exec -u "${NC_USER[$c]}" "$c" php "${NC_OCC[$c]}" "$@"; }
# Output of a failed occ call into the log (at most 20 lines)
nc_log_output() {
    local l
    while IFS= read -r l; do
        l="${l%$'\r'}"
        [[ -n "${l//[[:space:]]/}" ]] && log "    occ: $l"
    done < <(head -20 <<<"$1")
}

# nc_datadir_open <container> (2.62) - occ refused because Nextcloud's data folder is readable by others. Unraid does
#   that: saving any share's settings or adding a share resets the root of every share to 0777 nobody:users unless it
#   belongs to the group users and an owner other than nobody (7.4.0-rc.1, emhttpd's «Restarting services»; exclusive
#   shares and array shares alike). Nextcloud would chmod its folder back itself, but it no longer owns it - it refuses
#   every request (its page too) until someone does. The run is not lost for that: Nextcloud is stopped with the
#   apps for the dumps and snapshots, like apps without a maintenance mode, and refuses its users anyway - so the run
#   goes on without the mode and says it as a warning (code nc_datadir_readable, the path and the lasting fix: owner
#   Nextcloud's user, group users, 0770 - Unraid leaves such a root alone). The engine never changes it here; only a folder
#   it found closed when it switched the mode on is closed again as it was, to switch the mode off (2.65, nc_reclose).
#   Siblings of one Nextcloud (the same data folder) are said once.
declare -A NC_OPEN_SAID=()      # host path of a data folder -> said this run
# nc_data_host <container> -> its data folder on the host: from its config.php, read on the host (occ may refuse
#   everything); else Nextcloud's default. The folder inside the container when no bind holds it.
nc_data_host() {
    local c="$1" occd hp where dir=""
    occd="${NC_OCC[$c]%/occ}"
    if hp="$(ct_host_path "$c" "$occd/config/config.php")" && where="$(ub_path_where "$hp")" && [[ -f "$where" && ! -L "$where" ]]; then
        dir="$(head -c 65536 -- "$where" 2>/dev/null | sed -n "s/.*'datadirectory'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/\1/p" | head -1)"
    fi
    dir="${dir:-$occd/data}"; dir="${dir%/}"
    ct_host_path "$c" "$dir" || printf '%s' "$dir"
}
# nc_dir_look <container> (2.65): NC_DIR - its data folder's root on the pool or disk as it is now (owner, group, mode), only
#   while closed (occ works then) and a real folder with a plain name - what nc_reclose may put back after an array start
nc_dir_look() {
    local c="$1" hp where u g m
    unset "NC_DIR[$c]"
    hp="$(nc_data_host "$c")" && [[ "$hp" == "$UB_MNT"/* ]] || return 0
    where="$(ub_path_where "$hp" 2>/dev/null)" && [[ -d "$where" && ! -L "$where" && "$where" != *[[:cntrl:]]* ]] || return 0
    read -r u g m <<<"$(stat -c '%u %g %a' -- "$where" 2>/dev/null)"
    is_uint "$u" && is_uint "$g" && [[ "$m" =~ ^[0-7]{3,4}$ ]] && (( (8#$m & 8#007) == 0 )) || return 0
    NC_DIR[$c]="$where"$'\t'"$u"$'\t'"$g"$'\t'"$m"
}
nc_datadir_open() {
    local c="$1" hp where uid st=""
    hp="$(nc_data_host "$c")"
    if [[ -n "${NC_OPEN_SAID[$hp]:-}" ]]; then
        log "  Nextcloud '$c': its data folder $hp is readable by others too - said with '${NC_OPEN_SAID[$hp]}'"; return 0
    fi
    NC_OPEN_SAID[$hp]="$c"
    uid="$(docker exec "$c" id -u "${NC_USER[$c]}" 2>/dev/null | tr -d '\r')"; is_uint "$uid" || uid=33
    where="$(ub_path_where "$hp" 2>/dev/null)" && st="$(stat -c '%a %U:%G' -- "$where" 2>/dev/null)"
    warn_code nc_datadir_readable "Nextcloud '$c': its data folder $hp${st:+ ($st)} is readable by others - Nextcloud refuses to work (its page too) and occ cannot switch on the maintenance mode; backed up without it (stopped with the apps). Unraid resets a share's root to 0777 nobody:users when a share is added or its settings are saved, unless the root belongs to the group users. Lasting fix (Unraid then leaves it alone): chown $uid:users '$hp' && chmod 0770 '$hp'" \
        name="$c" path="$hp" uid="$uid"
    ub_tell nc_datadir_readable warning "" "Nextcloud refuses to work" "Nextcloud '$c' refuses to work: its data folder is readable by others. Backed up without maintenance mode. (Lasting fix: chown $uid:users '$hp' && chmod 0770 '$hp')" \
        "app=$c" "path=$hp" "uid=$uid"
}

# nc_db_container <container> -> the container its config.php names as dbhost ('db', 'mariadb-nextcloud:3306', a Compose
#   service of its project); 1 = none (an address, localhost, a socket, a name no container has). Read while it runs.
nc_db_container() {
    local c="$1" cfg h n
    cfg="$(docker exec "$c" cat "$(dirname "${NC_OCC[$c]}")/config/config.php" 2>/dev/null)" || return 1
    h="$(sed -n "s/.*'dbhost'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/\1/p" <<<"${cfg:0:65536}")"
    h="${h%%$'\n'*}"; h="${h%%:*}"
    [[ "$h" =~ ^[A-Za-z][A-Za-z0-9_.-]*$ && "$h" != localhost ]] || return 1
    for n in "${CT_NAMES[@]}"; do [[ "$n" == "$h" ]] && { printf '%s' "$n"; return 0; }; done
    [[ -n "${CT_PROJECT[$c]:-}" ]] || return 1
    for n in "${CT_NAMES[@]}"; do
        [[ "${CT_PROJECT[$n]}" == "${CT_PROJECT[$c]}" && "${CT_SERVICE[$n]}" == "$h" ]] && { printf '%s' "$n"; return 0; }
    done
    return 1
}
# nc_maint_config <container> -> its config.php's 'maintenance' (true / false / "" unknown) - without occ
nc_maint_config() {
    local cfg
    cfg="$(docker exec "$1" cat "$(dirname "${NC_OCC[$1]}")/config/config.php" 2>/dev/null)" || return 0
    sed -n "s/.*'maintenance'[[:space:]]*=>[[:space:]]*\(true\|false\).*/\1/p" <<<"${cfg:0:65536}" | head -1
}
# nc_db_down <container> [database container] (2.64) - Nextcloud can't reach its database (stopped, paused, or occ says it
#   can't connect): occ answers nothing then, the maintenance mode can't be switched on. That is this app's problem, not the
#   run's: no maintenance mode, Nextcloud is stopped with the apps for the snapshot like an app without one, everything else
#   is backed up; said as a warning in the office's words (db_unreachable {app, container}, or db_unreachable_occ {app}
#   when no container is known) and a notification. Up to 2.63 the run died here and nothing was backed up.
nc_db_down() {
    local c="$1" db="${2:-}"
    NC_DBDOWN[$c]=1
    if [[ -n "$db" ]]; then
        warn_code db_unreachable "Nextcloud '$c': its database '$db' is not running - no maintenance mode; Nextcloud is backed up stopped with the apps, everything else as usual" \
            app="$c" container="$db"
        ub_tell db_unreachable warning "" "Nextcloud's database is not running" "Nextcloud '$c' was backed up without maintenance mode: its database '$db' is not running. Start it on the Docker page." \
            "app=$c" "container=$db"
    else
        warn_code db_unreachable_occ "Nextcloud '$c' cannot reach its database (occ's message is in the log) - no maintenance mode; Nextcloud is backed up stopped with the apps, everything else as usual" \
            app="$c"
        ub_tell db_unreachable_occ warning "" "Nextcloud cannot reach its database" "Nextcloud '$c' was backed up without maintenance mode: it cannot reach its database." "app=$c"
    fi
}

nextcloud_maintenance_on() {
    local c was pre id out try on db
    local -A inst=()           # instanceid -> first container of this instance
    while IFS= read -r c; do
        [[ -z "$c" ]] && continue
        if [[ "$(docker inspect -f '{{.State.Running}}' "$c" 2>/dev/null)" != "true" ]]; then
            warn "Nextcloud '$c' is not running - no maintenance mode"; continue
        fi
        # 2.64: paused by the user - it answers nothing and changes nothing until it is resumed: left as it is
        if [[ "${CT_PAUSED[$c]:-}" == "true" ]]; then
            log "  Nextcloud '$c' is paused (by you) - no maintenance mode, it stays paused"; continue
        fi
        nc_find_occ "$c" || { warn "Nextcloud '$c': occ not found - no maintenance mode"; continue; }
        # 2.64: its database container stopped or paused - occ can't answer; not asked at all (nc_db_down)
        if db="$(nc_db_container "$c")" && [[ "$(docker inspect -f '{{.State.Running}} {{.State.Paused}}' "$db" 2>/dev/null)" != "true false" ]]; then
            nc_db_down "$c" "$db"; continue
        fi
        # Several containers can serve ONE Nextcloud (e.g. app and cron
        # with a shared config.php). The maintenance mode lives in that
        # config.php - after the first container the second would find it
        # "already on". The instanceid from config.php spots such siblings;
        # the first one's setting applies.
        id="$(nc_occ "$c" config:system:get instanceid 2>/dev/null | tr -d '\r')"
        [[ "$id" =~ ^[A-Za-z0-9]+$ ]] || id=""      # 2.62: a refusing occ prints its complaint, not an id
        if [[ -n "$id" && -n "${inst[$id]:-}" ]]; then
            NC_SAME[$c]="${inst[$id]}"
            log "  Nextcloud '$c': same instance as '${inst[$id]}' - done there"
            continue
        fi
        [[ -n "$id" ]] && inst[$id]="$c"
        # 'maintenance:mode --on' reports a mode that is already on only with
        # "already enabled" and exit code 0 - the state has to be read first.
        was="$(nc_occ "$c" config:system:get maintenance 2>/dev/null | tr -d '\r')"
        pre="${UB_NC_PREEXISTING:-$(cfg "nextcloud|$c|preexisting_maintenance" abort)}"
        if [[ "$was" == "true" ]]; then
            [[ "$pre" == "abort" ]] && die "Nextcloud '$c' is already in maintenance mode - backup aborted (UB_NC_PREEXISTING=continue forces the run)"
            warn "Nextcloud '$c' was already in maintenance mode - it stays ON afterwards"
            ub_tell nc_was_on warning "" "Nextcloud was already in maintenance mode" "Nextcloud '$c' was backed up anyway and stays in maintenance mode." "app=$c"
            continue
        fi
        # Note it before switching on: if occ sets the mode and only fails
        # afterwards, the abort still switches it off again. Another attempt
        # after such a half success reports "already enabled" with 0.
        nc_dir_look "$c"
        NC_ON[$c]=1; save_restore_state
        on=0
        for try in 1 2 3; do
            if out="$(nc_occ "$c" maintenance:mode --on 2>&1)"; then on=1; break; fi
            nc_log_output "$out"
            grep -q "readable by other people" <<<"$out" && break      # stays so until someone changes it (2.62)
            grep -q "Failed to connect to the database" <<<"$out" && break   # nor does a database that isn't there (2.64)
            (( try < 3 )) && { warn "Nextcloud '$c': maintenance mode not switched on (attempt $try/3) - trying again in 5 s"; sleep 5; }
        done
        if (( on )); then
            log "  Nextcloud '$c': maintenance mode on"
        else
            # 2.64: no database - occ answers nothing, config.php says whether the mode is on (then it stays noted: switched
            # off at the end or by --recover once occ answers); the run goes on without it (nc_db_down)
            if grep -q "Failed to connect to the database" <<<"$out"; then
                [[ "$(nc_maint_config "$c")" == "true" ]] || { unset "NC_ON[$c]"; save_restore_state; }
                nc_db_down "$c" "$(nc_db_container "$c")"; continue
            fi
            # Keep noted only what is really on - otherwise the abort
            # reports a stuck maintenance mode that does not exist.
            [[ "$(nc_occ "$c" config:system:get maintenance 2>/dev/null | tr -d '\r')" == "true" ]] \
                || { unset "NC_ON[$c]"; save_restore_state; }
            # 2.62: the data folder readable by others - the run goes on without the maintenance mode (nc_datadir_open)
            if grep -q "readable by other people" <<<"$out"; then nc_datadir_open "$c"; continue; fi
            # 2.64: coded - the office says it in its own words (up to 2.63 the page showed this English sentence)
            die_code nc_maintenance_failed "Nextcloud '$c': maintenance mode could not be switched on (occ's message is in the log)"
        fi
    done < <(cfg_names nextcloud)
    [[ ${#NC_ON[@]} -gt 0 ]] && sleep "${UB_NC_SETTLE:-5}"      # let running requests finish (tests: shorter)
    return 0
}

##############################################################################
# Database dumps
##############################################################################
# Password via MYSQL_PWD instead of -p: that way it does not show in the process list.
# 2.66 (B43): MY_NOSSL[<container>] - its client goes without TLS (--skip-ssl, through the container's own socket): MariaDB
# 11.4+ makes a certificate of its own when the server starts, and its client checks it even over the socket - after the
# clock was set back below that start every login fails («TLS/SSL error: certificate is not yet valid»), so did each dump
declare -A MY_NOSSL=()
my_exec() { # my_exec <container> <bin> <login> <pw variable> <args...>
    local c="$1" bin="$2" login="$3" pwv="$4"; shift 4
    [[ -n "${MY_NOSSL[$c]:-}" ]] && set -- --skip-ssl "$@"
    docker exec "$c" sh -c 'eval "MYSQL_PWD=\${$3:-}"; export MYSQL_PWD; b="$1"; u="$2"; shift 3; exec "$b" -u"$u" "$@"' \
        sh "$bin" "$login" "$pwv" "$@"
}

# The dump functions write into the app's package (DUMP_DIR = <stage>/apps/<app>/db, DUMP_KEY =
# apps/<app>); a broken dump is removed, so the last good one stays in the package (pkg_keep_dumps).
# DUMP_AUTH notes which credentials a dump used - the names of the variables, never their values -
# so the restore help can use the same ones.
DUMP_DIR=""; DUMP_KEY=""
declare -A DUMP_STATE=()         # container -> ok | failed  (none: no dump this run)
declare -A DUMP_AUTH=()          # container -> "login<US>user variable<US>password variable<US>client"
declare -A DUMP_FILE_CT=()       # "apps/<app>/db/<file>" -> container
declare -a DUMPS_DONE=()         # "<file>|bytes|<container>" written this run
dump_done() { # dump_done <container> <file>
    local size; size="$(stat -c %s "$2" 2>/dev/null)"
    DUMP_FILE_CT[$DUMP_KEY/db/${2##*/}]="$1"; DUMPS_DONE+=( "${2##*/}|${size:-0}|$1" )
}

dump_mariadb() { # dump_mariadb <container>
    local c="$1" login="" pwv="" single="" v uv="" pv dv pair dump_bin sql_bin dbs db out size tbl_dump tbl_live
    local -a extra
    for v in MARIADB_ROOT_PASSWORD MYSQL_ROOT_PASSWORD; do
        if docker exec "$c" sh -c "[ -n \"\${$v:-}\" ]" 2>/dev/null; then login="root"; pwv="$v"; break; fi
    done
    if [[ -z "$pwv" ]]; then
        # No root password -> the application user, only its database
        for pair in MARIADB_USER:MARIADB_PASSWORD:MARIADB_DATABASE MYSQL_USER:MYSQL_PASSWORD:MYSQL_DATABASE; do
            IFS=: read -r uv pv dv <<<"$pair"
            if docker exec "$c" sh -c "[ -n \"\${$pv:-}\" ]" 2>/dev/null; then
                login="$(docker exec "$c" sh -c "printf %s \"\${$uv:-}\"")"; pwv="$pv"
                single="$(docker exec "$c" sh -c "printf %s \"\${$dv:-}\"")"
                break
            fi
        done
    fi
    [[ -n "$pwv" && -n "$login" ]] || { err "MariaDB '$c': no credentials found in the environment variables"; return 1; }

    if docker exec "$c" sh -c 'command -v mariadb-dump' >/dev/null 2>&1; then dump_bin="mariadb-dump"; sql_bin="mariadb"
    else dump_bin="mysqldump"; sql_bin="mysql"; fi
    if [[ "$login" == "root" ]]; then DUMP_AUTH[$c]="root"$'\x1f\x1f'"$pwv"$'\x1f'"$sql_bin"
    else DUMP_AUTH[$c]="user"$'\x1f'"$uv"$'\x1f'"$pv"$'\x1f'"$sql_bin"; fi
    # Without root the rights for routines and events are missing
    if [[ "$login" == "root" ]]; then extra=( --routines --triggers --events ); else extra=( --triggers ); fi

    # 2.66 (B43): asked once - its own TLS certificate refused (the clock set back since the server started): without TLS
    out="$(my_exec "$c" "$sql_bin" "$login" "$pwv" -N -B -e 'SELECT 1' 2>&1)"
    if [[ "$out" != "1" && ( "$out" == *"TLS/SSL error"* || "$out" == *"SSL connection error"* ) ]]; then
        MY_NOSSL[$c]=1
        log "  MariaDB '$c': ${out%%$'\n'*} - the server's own certificate (the clock went back since it started?): asked through its socket without TLS"
    fi
    local lerr=""
    if [[ -n "$single" ]]; then dbs="$single"
    else
        dbs="$(my_exec "$c" "$sql_bin" "$login" "$pwv" -N -B -e \
            "SELECT schema_name FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema','performance_schema','sys','mysql')" 2>"$DUMP_DIR/.${c}_list.err")"
        lerr="$(head -c 300 "$DUMP_DIR/.${c}_list.err" 2>/dev/null | tr -s '\n\t' '  ')"; rm -f "$DUMP_DIR/.${c}_list.err"
    fi
    # 2.66: the client's own words when it couldn't even list them (up to 2.65 dropped - «no database found» for any failure)
    [[ -n "$dbs" ]] || { err "MariaDB '$c': no database found${lerr:+ - $lerr}"; return 1; }

    local rc=0
    # one name a line (-N -B), never split at a space nor globbed (2.48); a name that can't be a file name or would end the
    # SQL quote below (/ ' \ - the batch mode writes a tab or newline as \t \n) is said, never dumped
    while IFS= read -r -u 3 db; do
        [[ -n "$db" ]] || continue
        # (2.61) the engine's own folder an earlier run wrote into the data folder (MariaDB lists every folder there as a
        # database) is no database - left out; this run moves it away (pkg_inside_app)
        if [[ "$db" == "#mysql50#$UB_INSIDE_DIR" || "$db" == "#mysql50#$UB_INSIDE_DIR.new-"* ]]; then
            log "  MariaDB '$c': $db is the backup engine's own folder in the data folder, no database - not dumped"; continue
        fi
        if [[ "$db" == */* || "$db" == *\'* || "$db" == *\\* ]]; then
            err "Dump $c/$db: a database name with / ' or \\ - not dumped (rename it, or dump it yourself)"; rc=1; continue
        fi
        out="$DUMP_DIR/mariadb_${c}_${db}.sql.gz"
        log "  MariaDB '$c' / $db ..."
        my_exec "$c" "$dump_bin" "$login" "$pwv" --single-transaction --quick --hex-blob "${extra[@]}" \
            --default-character-set=utf8mb4 --add-drop-database --databases "$db" \
            2>"$DUMP_DIR/${c}_${db}.stderr" | gzip -6 >"$out"
        if [[ ${PIPESTATUS[0]} -ne 0 ]]; then err "Dump $c/$db failed - see ${c}_${db}.stderr in the package"; rm -f "$out"; rc=1; continue; fi
        gzip -t "$out" 2>/dev/null                                   || { err "Dump $c/$db: gzip broken"; rm -f "$out"; rc=1; continue; }
        zcat "$out" | tail -5 | grep -q 'Dump completed'              || { err "Dump $c/$db incomplete (closing line missing)"; rm -f "$out"; rc=1; continue; }
        size=$(stat -c %s "$out")
        tbl_dump=$(zcat "$out" | grep -c '^CREATE TABLE')
        tbl_live=$(my_exec "$c" "$sql_bin" "$login" "$pwv" -N -B -e \
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$db' AND table_type='BASE TABLE'" 2>/dev/null)
        if [[ "$tbl_dump" != "$tbl_live" ]]; then err "Dump $c/$db: $tbl_dump tables in the dump, $tbl_live in the database"; rm -f "$out"; rc=1; continue; fi
        rm -f "$DUMP_DIR/${c}_${db}.stderr"
        dump_done "$c" "$out"
        log "    OK: $(human "$size"), $tbl_dump tables"
    done 3<<<"$dbs"
    return $rc
}

dump_postgres() { # dump_postgres <container>
    local c="$1" user out size
    user="$(docker exec "$c" sh -c 'printf %s "${POSTGRES_USER:-postgres}"')"
    out="$DUMP_DIR/postgres_${c}.sql.gz"
    DUMP_AUTH[$c]="user"$'\x1f'"POSTGRES_USER"$'\x1f'"POSTGRES_PASSWORD"$'\x1f'"psql"
    log "  Postgres '$c' (user $user) ..."
    # Without -t: with a TTY stderr ends up in the dump and line ends turn into CRLF
    docker exec "$c" sh -c 'PGPASSWORD="${POSTGRES_PASSWORD:-}" exec pg_dumpall --clean --if-exists --username="$1"' sh "$user" \
        2>"$DUMP_DIR/${c}.stderr" | gzip -6 >"$out"
    if [[ ${PIPESTATUS[0]} -ne 0 ]]; then err "Dump $c failed - see ${c}.stderr in the package"; rm -f "$out"; return 1; fi
    gzip -t "$out" 2>/dev/null || { err "Dump $c: gzip broken"; rm -f "$out"; return 1; }
    zcat "$out" | tail -5 | grep -q 'PostgreSQL database cluster dump complete' \
        || { err "Dump $c incomplete (closing line missing)"; rm -f "$out"; return 1; }
    size=$(stat -c %s "$out")
    rm -f "$DUMP_DIR/${c}.stderr"
    dump_done "$c" "$out"
    log "    OK: $(human "$size"), $(zcat "$out" | grep -c '^CREATE TABLE') tables"
}

dump_mongodb() { # dump_mongodb <container>
    local c="$1" out size
    out="$DUMP_DIR/mongodb_${c}.archive.gz"
    log "  MongoDB '$c' ..."
    docker exec "$c" sh -c 'command -v mongodump' >/dev/null 2>&1 || { err "MongoDB '$c': mongodump is missing in the container"; return 1; }
    if docker exec "$c" sh -c '[ -n "${MONGO_INITDB_ROOT_USERNAME:-}" ]' 2>/dev/null; then
        DUMP_AUTH[$c]="root"$'\x1f'"MONGO_INITDB_ROOT_USERNAME"$'\x1f'"MONGO_INITDB_ROOT_PASSWORD"$'\x1f'"mongorestore"
    else DUMP_AUTH[$c]="none"$'\x1f\x1f\x1f'"mongorestore"; fi
    # Credentials from the container's environment variables (official image)
    if ! docker exec "$c" sh -c 'if [ -n "${MONGO_INITDB_ROOT_USERNAME:-}" ]; then
            exec mongodump --quiet --archive --gzip --username "$MONGO_INITDB_ROOT_USERNAME" \
                 --password "$MONGO_INITDB_ROOT_PASSWORD" --authenticationDatabase admin
        else exec mongodump --quiet --archive --gzip; fi' >"$out" 2>"$DUMP_DIR/${c}.stderr"; then
        err "Dump $c failed - see ${c}.stderr in the package"; rm -f "$out"; return 1
    fi
    size=$(stat -c %s "$out")
    [[ "$size" -gt 0 ]] || { err "Dump $c is empty"; rm -f "$out"; return 1; }
    # Test: the archive must read completely (nothing is restored)
    if docker exec -i "$c" sh -c 'if [ -n "${MONGO_INITDB_ROOT_USERNAME:-}" ]; then
            exec mongorestore --dryRun --quiet --archive --gzip --username "$MONGO_INITDB_ROOT_USERNAME" \
                 --password "$MONGO_INITDB_ROOT_PASSWORD" --authenticationDatabase admin
        else exec mongorestore --dryRun --quiet --archive --gzip; fi' <"$out" >/dev/null 2>>"$DUMP_DIR/${c}.stderr"; then
        rm -f "$DUMP_DIR/${c}.stderr"
        dump_done "$c" "$out"
        log "    OK: $(human "$size") (archive reads completely)"
    else
        err "Dump $c: the archive does not read completely - see ${c}.stderr in the package"; rm -f "$out"; return 1
    fi
}

run_dumps() {
    local c t f rc st
    while IFS= read -r c; do
        [[ -z "$c" ]] && continue
        array_stop_check "before the dump of $c"
        t="$(cfg "dump|$c|type")"
        f="${PKG_APP_OF[$c]:-}"
        st="$(docker inspect -f '{{.State.Running}} {{.State.Paused}}' "$c" 2>/dev/null)"
        if [[ "$st" != "true"* || "$st" == "true true" ]]; then
            # 2.64: in the office's words (dump_paused / dump_stopped {container}): paused or stopped by the user - no exec answers
            if [[ "$st" == "true true" ]]; then
                warn_code dump_paused "Database container '$c' is paused - no dump (the package keeps its last one)" container="$c"
            else
                warn_code dump_stopped "Database container '$c' is not running - no dump (the package keeps its last one)" container="$c"
            fi
            [[ -n "$f" ]] && pkg_mark apps "$f" warnings
            continue
        fi
        [[ -n "$f" ]] || { warn "Database container '$c' belongs to no package - no dump"; continue; }
        DUMP_KEY="apps/$f"; DUMP_DIR="$PKG_STAGE/$DUMP_KEY/db"
        mkdir -p "$DUMP_DIR" || { err "Cannot create $DUMP_DIR"; DUMP_STATE[$c]="failed"; pkg_mark apps "$f" errors; continue; }
        # a dump takes as long as the database is big: no docker time limit inside (2.66, UB_DOCKER_LONG)
        case "$t" in
            mariadb)  UB_DOCKER_LONG=1 dump_mariadb "$c" ;;
            postgres) UB_DOCKER_LONG=1 dump_postgres "$c" ;;
            mongodb)  UB_DOCKER_LONG=1 dump_mongodb "$c" ;;
        esac
        rc=$?
        if (( rc == 0 )); then DUMP_STATE[$c]="ok"; else DUMP_STATE[$c]="failed"; pkg_mark apps "$f" errors; fi
        rmdir "$DUMP_DIR" 2>/dev/null       # nothing came of it (the last good dump comes along at the swap)
    done < <(cfg_names dump)
    return 0
}

##############################################################################
# Media servers' databases, the apps' own backups (since 2.19)
##############################################################################
# Emby, Jellyfin and Plex usually keep running during the snapshot (running streams): their SQLite
# databases there are only crash-consistent, and Jellyfin's and Plex's docs say to stop the server
# for a backup. Such a server that keeps running gets a consistent copy of its main databases in its
# package (db/sqlite_<container>_<file>): SQLite's backup API, read-only, through the very path the
# server uses (so locks and the WAL index are shared), checked with PRAGMA quick_check (Plex: by its own SQLite, 2.55,
# media_sqlite_check in lib/common.sh). Made while
# everything still runs - it doesn't lengthen the interruption. A copy that fails or takes too long
# keeps the last good one; an unchanged database keeps it too (a hard link: no new blocks in the
# snapshots of the backup place). Never on a sleeping disk.
SQ_TIMEOUT="${UB_SQLITE_TIMEOUT:-600}"
declare -a SQ_PLAN=()                    # "container|host path|path in the container|asleep"
declare -A SQ_STATE=() SQ_CHECK=()       # "container|file" -> ok | unchanged | failed | asleep;  ok | unchecked
declare -A SQ_UNCHANGED_BYTES=()         # package folder -> bytes of copies that stayed as they were

# media_db_candidates <kind>  -> its main databases, as paths inside the container (official and linuxserver images)
media_db_candidates() {
    local P="/config/Library/Application Support/Plex Media Server/Plug-in Support/Databases"
    case "$1" in
        emby)     printf '%s\n' /config/data/library.db /config/data/users.db /config/data/authentication.db ;;
        jellyfin) printf '%s\n' /config/data/jellyfin.db /config/data/library.db /config/data/data/jellyfin.db /config/data/data/library.db ;;
        plex)     printf '%s\n' "$P/com.plexapp.plugins.library.db" "$P/com.plexapp.plugins.library.blobs.db" ;;
    esac
}

# which databases this run copies: media servers that run, keep running for the snapshot and are backed up
sqlite_plan() {
    local c k p hp where
    SQ_PLAN=()
    command -v sqlite3 >/dev/null 2>&1 || return 0
    for c in "${CT_NAMES[@]}"; do
        [[ "${CT_RUNNING[$c]}" == "true" && "${CT_PAUSED[$c]:-}" != "true" ]] || continue   # paused: its files hold still (2.64)
        k="$(media_kind "${CT_IMAGE[$c]}")"; [[ -n "$k" ]] || continue
        in_list "$c" "${DOCKER_SKIP[@]}" && continue                         # not backed up on purpose
        in_list "$c" "${T_APP[@]}" "${T_DB[@]}" "${T_NET[@]}" && continue    # stopped: its snapshot is consistent
        [[ -n "${PKG_APP_OF[$c]:-}" ]] || continue
        while IFS= read -r p; do
            hp="$(ct_host_path "$c" "$p")" || continue
            where="$(ub_path_where "$hp")"
            if [[ -n "$where" && -f "$where" ]]; then SQ_PLAN+=( "$c|$hp|$p|" )
            elif [[ "$UB_WHERE_ASLEEP" == "yes" ]]; then SQ_PLAN+=( "$c|$hp|$p|asleep" ); fi
        done < <(media_db_candidates "$k")
    done
    return 0
}

run_sqlite_copies() {
    local line c hp p asl f file key out old r sz
    for line in "${SQ_PLAN[@]}"; do
        IFS='|' read -r c hp p asl <<<"$line"
        array_stop_check "before the SQLite copies of $c"
        f="${PKG_APP_OF[$c]}"; file="sqlite_${c}_${p##*/}"; key="$c|$file"
        out="$PKG_STAGE/apps/$f/db/$file"; old="$UB_DUMPS/apps/$f/db/$file"
        if [[ "$asl" == "asleep" ]]; then
            SQ_STATE[$key]="asleep"; log "  SQLite '$c' ${p##*/}: its disk sleeps - the package keeps its last copy"; continue
        fi
        # nothing changed since the last copy (the database and its WAL): that copy is still the current state
        if [[ -f "$old" && ! -L "$old" && -z "$(find "$hp" "$hp-wal" -newer "$old" 2>/dev/null)" ]]; then
            SQ_STATE[$key]="unchanged"; log "  SQLite '$c' ${p##*/}: unchanged since the last copy"; continue
        fi
        [[ "$out" != *"'"* ]] && mkdir -p "${out%/*}" || { SQ_STATE[$key]="failed"; warn "SQLite '$c' ${p##*/}: no place for the copy"; pkg_mark apps "$f" warnings; continue; }
        log "  SQLite '$c' ${p##*/} ..."
        if timeout "$SQ_TIMEOUT" sqlite3 "file:$(uri_escape "$hp")?mode=ro" ".backup '$out'" >/dev/null 2>>"$LOG_FILE" && [[ -s "$out" ]]; then
            media_sqlite_check "$c" "$(media_kind "${CT_IMAGE[$c]}")" "$out"; r="$SQ_CHECK_OUT"
            rm -f "$out-wal" "$out-shm" "$out-journal"
            if [[ "$r" == "ok" ]]; then SQ_CHECK[$key]="ok"
            elif [[ "$SQ_CHECK_BY" == "host" ]] && grep -qiE 'no such (collation|module|function|tokenizer)|unknown tokenizer' <<<"$r"; then
                SQ_CHECK[$key]="unchecked"; log "    (Unraid's sqlite3 can't check it - the server's own SQLite extensions: $r)"
            else
                SQ_STATE[$key]="failed"; rm -f "$out"
                warn "SQLite copy of '$c' ${p##*/} fails its check ($r) - the package keeps its last copy"; pkg_mark apps "$f" warnings; continue
            fi
            SQ_STATE[$key]="ok"; DUMP_FILE_CT[apps/$f/db/$file]="$c"
            sz="$(stat -c %s "$out" 2>/dev/null)"
            log "    OK: $(human "${sz:-0}")$([[ "${SQ_CHECK[$key]}" == "ok" ]] && echo ', checked')$([[ "${SQ_CHECK[$key]}" == "ok" && "$SQ_CHECK_BY" == "plex" ]] && echo " by Plex's own SQLite")"
        else
            SQ_STATE[$key]="failed"; rm -f "$out" "$out-journal"
            warn "SQLite copy of '$c' ${p##*/} failed or took longer than ${SQ_TIMEOUT} s - the package keeps its last copy"; pkg_mark apps "$f" warnings
        fi
    done
    return 0
}

# a copy that failed, wasn't made (sleeping disk) or wasn't needed (unchanged): the last one comes along
pkg_keep_sqlite() { # pkg_keep_sqlite <folder> <old dir> <new dir>
    local f="$1" old="$2" new="$3" line c hp p asl file key sz r
    for line in "${SQ_PLAN[@]}"; do
        IFS='|' read -r c hp p asl <<<"$line"
        [[ "${PKG_APP_OF[$c]:-}" == "$f" ]] || continue
        file="sqlite_${c}_${p##*/}"; key="$c|$file"
        [[ "${SQ_STATE[$key]:-}" == "ok" ]] && continue
        sz="$(pkg_keep "$old" "$new" "db/$file")" || continue
        DUMP_FILE_CT[apps/$f/db/$file]="$c"
        SQ_CHECK[$key]="$(jq -r --arg p "db/$file" 'first(.sqlite[]? | select(.file == $p) | .check) // ""' "$old/manifest.json" 2>/dev/null)"
        if [[ "${SQ_STATE[$key]}" == "unchanged" ]]; then
            SQ_UNCHANGED_BYTES[$f]=$(( ${SQ_UNCHANGED_BYTES[$f]:-0} + ${sz:-0} ))     # still the current state: this run's
        else
            r="$(jq -r --arg p "db/$file" 'first(.files[]? | select(.path == $p) | .run) // ""' "$old/manifest.json" 2>/dev/null)"
            PKG_FILE_RUN[apps/$f/db/$file]="$r"
            PKG_KEPT[apps/$f]=$(( ${PKG_KEPT[apps/$f]:-0} + 1 ))
            log "  db/$file of '$c' stays in the package${r:+ from run $r}"
        fi
    done
    return 0
}

# sqlite_json <folder>  -> the manifest's "sqlite": per planned copy its container, file, where the database
# lies (host and container), what this run did and whether the copy is checked
sqlite_json() {
    local f="$1" line c hp p asl file key
    for line in "${SQ_PLAN[@]}"; do
        IFS='|' read -r c hp p asl <<<"$line"
        [[ "${PKG_APP_OF[$c]:-}" == "$f" ]] || continue
        file="sqlite_${c}_${p##*/}"; key="$c|$file"
        printf '%s\x1f' "$c" "db/$file" "$hp" "$p" "${SQ_STATE[$key]:-failed}" "${SQ_CHECK[$key]:-}" "$([[ -f "$PKG_STAGE/apps/$f/db/$file" ]] && echo 1)"; echo
    done | jq -Rn '[inputs | split("\u001f") | {container: .[0], file: .[1], source: .[2], path: .[3], state: .[4], check: .[5], present: (.[6] == "1")}]'
}

# The apps' own backups - a second way back that the restore help names:
#   emby      the Backup & Restore plugin: <BackupDirectory> from plugins/configurations/MBBackup.xml
#   jellyfin  10.11 and newer: backups/ in its data folder
#   plex      its database backups every three days next to the database (com.plexapp.plugins.library.db-<date>)
#   immich    UPLOAD_LOCATION/backups (daily database dumps, 14 kept unless changed)
declare -A PKG_OWN=()                    # package folder -> JSON list
own_backup_line() { # own_backup_line <kind> <container> <host dir> <glob>  - a line when the folder is there
    local where n=0 newest=0 x m
    where="$(ub_path_where "$3")"
    if [[ -z "$where" ]]; then
        [[ "$UB_WHERE_ASLEEP" == "yes" ]] || return 1
        printf '%s\x1f%s\x1f%s\x1f0\x1f0\x1f1\n' "$1" "$2" "$3"; return 0        # its disk sleeps: named, not looked into
    fi
    [[ -d "$where" ]] || return 1
    for x in "$where"/$4; do
        [[ -e "$x" ]] || continue
        n=$((n+1)); m="$(stat -c %Y "$x" 2>/dev/null)"; (( ${m:-0} > newest )) && newest="$m"
    done
    printf '%s\x1f%s\x1f%s\x1f%s\x1f%s\x1f0\n' "$1" "$2" "$3" "$n" "$newest"
}
own_backups_of() { # own_backups_of <container>
    local c="$1" cfg where d hp x
    case "$(media_kind "${CT_IMAGE[$c]}")" in
        emby)
            cfg="$(ct_host_path "$c" /config/plugins/configurations/MBBackup.xml)" || cfg=""
            where=""; [[ -n "$cfg" ]] && where="$(ub_path_where "$cfg")"
            d=""; [[ -n "$where" && -f "$where" ]] && d="$(sed -n 's:.*<BackupDirectory>\([^<]*\)</BackupDirectory>.*:\1:p' "$where" | head -1)"
            [[ -n "$d" ]] && hp="$(ct_host_path "$c" "$d")" && own_backup_line emby "$c" "$hp" '*'
            ;;
        jellyfin)
            for x in /config/backups /config/data/backups /config/data/data/backups; do
                hp="$(ct_host_path "$c" "$x")" && own_backup_line jellyfin "$c" "$hp" '*' && break
            done
            ;;
        plex)
            hp="$(ct_host_path "$c" "/config/Library/Application Support/Plex Media Server/Plug-in Support/Databases")" \
                && own_backup_line plex "$c" "$hp" 'com.plexapp.plugins.library.db-20*'
            ;;
    esac
    if is_immich_server "${CT_IMAGE[$c]}"; then
        for x in /data /usr/src/app/upload; do
            hp="$(ct_host_path "$c" "$x/backups")" && own_backup_line immich "$c" "$hp" 'immich-db-backup-*' && break
        done
    fi
    return 0
}

##############################################################################
# Packages: one per app and VM in the backup place (lib/common.sh, section 8)
##############################################################################
# Built in <place>/.ub-stage-<run>/ while the run goes on, swapped in before the snapshots,
# so this run's snapshot of the backup place's share holds this run's packages:
#   pkg_plan        which apps and VMs (also in a dry run)
#   pkg_begin       the stage
#   pkg_server      server/: the lists and configs that belong to no app   (everything still runs)
#   pkg_apps_static apps/<app>/: templates, compose files, docker inspect   (everything still runs)
#   run_dumps       apps/<app>/db/                                          (apps paused)
#   flash_tar       flash/flash.tar.gz
#   pkg_commit_apps the app packages and flash/ swapped in                  (before the databases stop)
#   libvirt_tar, pkg_vms, pkg_commit_rest   VMs and server/                 (VMs held)
PKG_STAGE=""
PKG_COMMITTED="no"
declare -a PKG_APPS=() PKG_VMS=()            # package folders, in order
declare -A PKG_APP_NAME=() PKG_APP_TYPE=() PKG_APP_MEMBERS=() PKG_APP_OF=() PKG_APP_CM=()
declare -A PKG_VM_NAME=() PKG_VM_META=()
declare -A PKG_RESULT=()                     # "apps/<f>", "vms/<f>", "server", "flash" -> ok | warnings | errors
declare -A PKG_TPL=()                        # container -> its Unraid template (templates-user/my-*.xml)
declare -A PKG_PROJ_WD=() PKG_PROJ_CFG=()    # compose project -> working dir, config files (from its labels)
declare -A PKG_FILES=() PKG_BYTES=() PKG_KEPT=() PKG_RUN=()   # per package after the swap
declare -A PKG_FILE_RUN=()                   # "<package>/<path>" -> the run a kept file is from
PKG_WRITTEN_BYTES=0                          # what this run wrote into the packages (kept files not counted)
PKG_OLD_RUNS=0; PKG_OLD_ACTION=""            # run folders of engines before 2.18: how many, removed | would_remove | kept

pkg_mark() { # pkg_mark <sub> <folder> <ok|warnings|errors>  - only ever gets worse
    local k="$1/$2" now
    [[ "$1" == "." ]] && k="$2"
    now="${PKG_RESULT[$k]:-ok}"
    case "$3" in
        errors)   PKG_RESULT[$k]="errors" ;;
        warnings) [[ "$now" == "errors" ]] || PKG_RESULT[$k]="warnings" ;;
        *)        PKG_RESULT[$k]="$now" ;;
    esac
}

# The Compose Manager's project folder of a compose project: its working dir or the folder an
# "indirect" file points to, otherwise its project_name, name or folder name
cm_dir_of() { # cm_dir_of <project> <working dir>
    local p="$1" wd="${2%/}" d ind pn nm
    local root="$UB_BOOT/config/plugins/compose.manager/projects"
    if [[ -n "$wd" ]]; then
        for d in "$root"/*/; do
            d="${d%/}"; [[ -d "$d" ]] || continue
            ind="$(head -1 "$d/indirect" 2>/dev/null | tr -d '\r')"
            [[ "$d" == "$wd" || ( -n "$ind" && "${ind%/}" == "$wd" ) ]] && { printf '%s' "$d"; return 0; }
        done
    fi
    for d in "$root"/*/; do
        d="${d%/}"; [[ -d "$d" ]] || continue
        pn="$(head -1 "$d/project_name" 2>/dev/null | tr -d '\r')"
        nm="$(head -1 "$d/name" 2>/dev/null | tr -d '\r')"
        [[ "$pn" == "$p" || "${nm,,}" == "$p" || "${d##*/}" == "$p" || "${d##*/}" == "${p,,}" ]] && { printf '%s' "$d"; return 0; }
    done
    return 1
}

# Which apps and VMs get a package. An app is a compose project (named after it) or a single
# container (named after it); a database container of a project belongs to the project's app.
# Apps not backed up on purpose ([docker] skip for all their containers, none with a dump) get none.
pkg_plan() {
    local n t name key f m all_skip p wd cfgs
    local -a keys=() sorted=() ids=() vms=()
    local -A members=() taken=()
    PKG_APPS=(); PKG_APP_NAME=(); PKG_APP_TYPE=(); PKG_APP_MEMBERS=(); PKG_APP_OF=(); PKG_TPL=()
    PKG_PROJ_WD=(); PKG_PROJ_CFG=(); PKG_VMS=(); PKG_VM_NAME=()
    local tdir="$UB_BOOT/config/plugins/dockerMan/templates-user"
    for t in "$tdir"/*.xml; do
        [[ -f "$t" ]] || continue
        name="$(sed -n 's:.*<Name>\([^<]*\)</Name>.*:\1:p' "$t" 2>/dev/null | head -1)"
        [[ -n "$name" && -z "${PKG_TPL[$name]:-}" ]] && PKG_TPL[$name]="$t"
    done
    mapfile -t ids < <(docker ps -aq 2>/dev/null)
    if (( ${#ids[@]} )); then
        while IFS=$'\x1e' read -r p wd cfgs; do
            [[ -n "$p" && -z "${PKG_PROJ_WD[$p]+x}" ]] && { PKG_PROJ_WD[$p]="$wd"; PKG_PROJ_CFG[$p]="$cfgs"; }
        done < <(docker inspect "${ids[@]}" 2>/dev/null | jq -r '.[] | [(.Config.Labels["com.docker.compose.project"] // ""),
                    (.Config.Labels["com.docker.compose.project.working_dir"] // ""),
                    (.Config.Labels["com.docker.compose.project.config_files"] // "")] | map(gsub("[\n\u001e]"; " ")) | join("\u001e")')
    fi
    for n in "${CT_NAMES[@]}"; do
        [[ -z "${PKG_TPL[$n]:-}" && -f "$tdir/my-$n.xml" ]] && PKG_TPL[$n]="$tdir/my-$n.xml"
        if [[ -n "${CT_PROJECT[$n]}" ]]; then key="1|${CT_PROJECT[$n]}"; else key="2|$n"; fi
        [[ -z "${members[$key]+x}" ]] && keys+=( "$key" )
        members[$key]+="$n"$'\n'
    done
    # compose projects first: a single container named like a project gets the suffix
    mapfile -t sorted < <(printf '%s\n' "${keys[@]}" | LC_ALL=C sort)
    for key in "${sorted[@]}"; do
        [[ -z "$key" ]] && continue
        name="${key#*|}"; all_skip=1
        while IFS= read -r m; do
            [[ -z "$m" ]] && continue
            { in_list "$m" "${DOCKER_SKIP[@]}" && ! cfg_has "dump|$m"; } || all_skip=0
        done <<<"${members[$key]}"
        (( all_skip )) && continue
        f="$(pkg_folder "$name")"
        [[ -n "${taken[$f]:-}" ]] && f="$f-$(printf '%s' "$key" | md5sum | cut -c1-6)"
        taken[$f]=1
        PKG_APPS+=( "$f" ); PKG_APP_NAME[$f]="$name"; PKG_APP_MEMBERS[$f]="${members[$key]}"
        if [[ "$key" == 1\|* ]]; then PKG_APP_TYPE[$f]="compose"
        elif [[ -n "${PKG_TPL[$name]:-}" ]]; then PKG_APP_TYPE[$f]="template"
        else PKG_APP_TYPE[$f]="container"; fi
        while IFS= read -r m; do [[ -n "$m" ]] && PKG_APP_OF[$m]="$f"; done <<<"${members[$key]}"
    done
    [[ "$VM_SERVICE" == "yes" ]] || return 0
    taken=()
    mapfile -t vms < <(printf '%s\n' "${VM_NAMES[@]}" | LC_ALL=C sort | sed '/^$/d')
    for n in "${vms[@]}"; do
        vm_packed "$n" || continue
        f="$(pkg_folder "$n")"
        [[ -n "${taken[$f]:-}" ]] && f="$f-$(printf '%s' "$n" | md5sum | cut -c1-6)"
        taken[$f]=1
        PKG_VMS+=( "$f" ); PKG_VM_NAME[$f]="$n"
    done
    return 0
}

pkg_begin() {
    PKG_STAGE="$UB_DUMPS/.ub-stage-$TS"
    rm -rf -- "$PKG_STAGE"
    mkdir -p "$PKG_STAGE/apps" "$PKG_STAGE/vms" "$PKG_STAGE/server" && chmod 700 "$PKG_STAGE"
}

# a small file of a folder (a Compose Manager project) - logs and state files of the folder stay out
pkg_copy_small() { # pkg_copy_small <file> <target dir>
    [[ -f "$1" && ! -L "$1" ]] || return 1
    case "${1##*/}" in last_cmd.log|last_result.json|started_at|*.log) return 1 ;; esac
    (( $(stat -c %s "$1" 2>/dev/null || echo 0) <= 1048576 )) || return 1
    mkdir -p "$2" && cp -a "$1" "$2/" 2>>"$LOG_FILE"
}

# build/<service>/ of a compose app (since 2.20): per service with build: the small files of its build
# context's folder (its top level, pkg_copy_small's rules, at most PKG_BUILD_FILES files and PKG_BUILD_BYTES
# together) and its Dockerfile (at its place below the context, else by its name) - nothing that compose/
# holds already (a context that is the Compose Manager's folder). A context that is no local folder (git,
# a URL) or an inline Dockerfile: nothing to copy. A rebuild needs them: no registry has such an image.
PKG_BUILD_FILES=100
PKG_BUILD_BYTES=$(( 10 * 1048576 ))
pkg_build_files() { # pkg_build_files <app folder> <project> <working dir> <Compose Manager dir> <package dir>
    local f="$1" p="$2" wd="${3%/}" cm="${4%/}" A="$5" x svc ctx df rel T n bytes sz cut found=0
    local -a cfgs=() args=()
    IFS=',' read -r -a cfgs <<<"${PKG_PROJ_CFG[$p]:-}"
    for x in "${cfgs[@]}"; do
        [[ -f "$x" ]] || continue
        args+=( -f "$x" )
        grep -qE '^[[:space:]]+["'"'"']?build["'"'"']?[[:space:]]*:' "$x" 2>/dev/null && found=1
    done
    (( found )) || return 0
    [[ -n "$wd" && -d "$wd" ]] && args+=( --project-directory "$wd" )
    while IFS=$'\t' read -r svc ctx df; do
        [[ -n "$svc" && "$ctx" == /* && -d "$ctx" ]] || continue
        ctx="${ctx%/}"; [[ -n "$ctx" ]] || continue          # never the whole root
        [[ "$df" == /* ]] || df="$ctx/$df"
        T="$A/build/$(pkg_folder "$svc")"; n=0; bytes=0; cut=0
        if [[ "$ctx" != "$cm" ]]; then
            for x in "$ctx"/* "$ctx"/.[!.]*; do
                [[ -f "$x" && ! -L "$x" ]] || continue
                in_list "$x" "${cfgs[@]}" "$wd/.env" && continue       # in compose-files/ already
                sz="$(stat -c %s "$x" 2>/dev/null || echo 0)"
                if (( n >= PKG_BUILD_FILES || bytes + sz > PKG_BUILD_BYTES )); then cut=1; continue; fi
                pkg_copy_small "$x" "$T" && { n=$(( n + 1 )); bytes=$(( bytes + sz )); }
            done
        fi
        # the Dockerfile, where it isn't among them yet
        if [[ -f "$df" && ! -L "$df" && ! ( "${df%/*}" == "$cm" && -n "$cm" ) ]]; then
            rel="${df#"$ctx"/}"; [[ "$rel" == "$df" || "$rel" == *..* ]] && rel="${df##*/}"
            [[ -e "$T/$rel" ]] || { pkg_copy_small "$df" "$T/$(dirname "$rel")" && n=$(( n + 1 )); }
        fi
        if (( cut )); then
            log "  App '$p', service '$svc': only $n small files of its build context $ctx come into the package (at most $PKG_BUILD_FILES, $(( PKG_BUILD_BYTES / 1048576 )) MB)"
        elif (( n )); then
            log "  App '$p', service '$svc': $n files of its build context into build/${T##*/}/"
        fi
    done < <(timeout 30 docker compose -p "$p" "${args[@]}" config --format json 2>>"$LOG_FILE" \
                | jq -r '.services // {} | to_entries[] | select(.value.build != null)
                         | [.key, (.value.build.context // ""), (.value.build.dockerfile // "Dockerfile")] | map(gsub("[\t\n]"; " ")) | @tsv' 2>/dev/null)
    return 0
}

# server/: what belongs to no app - the manifest of the runs before 2.18
pkg_server() {
    local S="$PKG_STAGE/server" f c t d x
    local -A tpl_used=() cm_used=()
    mkdir -p "$S/shares"
    {
        echo "# Backup manifest $TS  ($UB_NAME $UB_VERSION)"
        echo "Host:        $(hostname)"
        echo "Unraid:      $(cat /etc/unraid-version 2>/dev/null)"
        echo "Kernel:      $(uname -r)"
        echo "Snapshot:    $SNAP_NAME"
        echo "Mounts:      $MOUNT_ROOT/<share>"
        echo "Kopia:       ${KOPIA_ID:-?} (${KOPIA_CONTAINER:-no container})"
        echo "Packages:    $UB_DUMPS"
        echo
        echo "## Shares to Kopia"
        printf '%s\n' "${PLAN_KOPIA[@]:-(none)}"
        echo
        echo "## Apps and VMs with a Kopia source of their own (kind|name|folder under <mount_root>/.<kind>s/)"
        printf '%s\n' "${PLAN_KITEMS[@]:-(none)}"
        echo
        echo "## ZFS datasets in the snapshot"
        printf '%s\n' "${PLAN_ZFS[@]:-(none)}"
        echo
        echo "## btrfs snapshots"
        printf '%s\n' "${PLAN_BTRFS[@]:-(none)}"
        echo
        echo "## App packages (folder: app, kind)"
        for f in "${PKG_APPS[@]}"; do echo "apps/$f: ${PKG_APP_NAME[$f]} (${PKG_APP_TYPE[$f]})"; done
        echo
        echo "## VM packages"
        for f in "${PKG_VMS[@]}"; do echo "vms/$f: ${PKG_VM_NAME[$f]}"; done
        echo
        echo "## Containers (name, image)"
        if [[ "$DOCKER_SERVICE" == "no" ]]; then echo "(Docker is switched off)"
        else docker ps -a --format '{{.Names}}\t{{.Image}}' | sort; fi
    } >"$S/manifest.txt"
    local -a ids=(); [[ "$DOCKER_SERVICE" == "no" ]] || mapfile -t ids < <(docker ps -aq 2>/dev/null)
    if (( ${#ids[@]} )); then
        docker inspect --format '{{.Name}}  {{.Config.Image}}  {{.Image}}' "${ids[@]}" >"$S/docker-images.txt" 2>&1
    else
        : >"$S/docker-images.txt"
    fi
    if [[ "$DOCKER_SERVICE" == "no" ]]; then echo "(Docker is switched off)" >"$S/docker-ps.txt"
    else docker ps -a --format '{{.Names}}\t{{.Image}}\t{{.Status}}' >"$S/docker-ps.txt" 2>&1; fi
    command -v zfs   >/dev/null && { zfs list -o name,used,refer,mountpoint >"$S/zfs-list.txt" 2>&1; zpool status >"$S/zpool-status.txt" 2>&1; }
    # --mounted: what the kernel knows - no raw reads of every device (a busy or sleeping disk held this up for minutes)
    command -v btrfs >/dev/null && timeout 30 btrfs filesystem show --mounted >"$S/btrfs-show.txt" 2>&1
    cp -a "$UB_SETTINGS" "$S/settings.ini" 2>/dev/null
    drift_text >"$S/drift.txt"
    cp -a "$UB_BOOT"/config/shares/*.cfg "$S/shares/" 2>/dev/null
    # templates no container uses any more (Unraid's "Previous Apps") and compose projects
    # without a container (a stack not started): they belong to no app
    for c in "${CT_NAMES[@]}"; do [[ -n "${PKG_TPL[$c]:-}" ]] && tpl_used[${PKG_TPL[$c]}]=1; done
    for x in "${!PKG_PROJ_WD[@]}"; do d="$(cm_dir_of "$x" "${PKG_PROJ_WD[$x]}")" && cm_used[$d]=1; done
    for t in "$UB_BOOT"/config/plugins/dockerMan/templates-user/*.xml; do
        [[ -f "$t" && -z "${tpl_used[$t]:-}" ]] || continue
        mkdir -p "$S/docker-templates" && cp -a "$t" "$S/docker-templates/" 2>>"$LOG_FILE"
    done
    for d in "$UB_BOOT"/config/plugins/compose.manager/projects/*/; do
        d="${d%/}"; [[ -d "$d" && -z "${cm_used[$d]:-}" ]] || continue
        for x in "$d"/* "$d"/.[!.]*; do pkg_copy_small "$x" "$S/compose/${d##*/}"; done
    done
    PKG_RESULT[server]="ok"
}

pkg_apps_static() {
    local f
    for f in "${PKG_APPS[@]}"; do pkg_app_static "$f"; done
    return 0
}

# apps/<app>/: its templates or compose files, docker inspect of its containers (with the images'
# digests), Nextcloud's config.php and occ lists - while everything still runs
pkg_app_static() {
    local f="$1" A="$PKG_STAGE/apps/$1" c x p wd cm
    local -a cts=() ids=() used=()
    PKG_RESULT[apps/$f]="ok"
    mkdir -p "$A"
    mapfile -t cts < <(sed '/^$/d' <<<"${PKG_APP_MEMBERS[$f]}")
    if ! docker inspect "${cts[@]}" >"$A/.inspect.json" 2>>"$LOG_FILE"; then
        warn "App '${PKG_APP_NAME[$f]}': docker inspect failed"; pkg_mark apps "$f" warnings
        jq -e 'type == "array"' "$A/.inspect.json" >/dev/null 2>&1 || echo '[]' >"$A/.inspect.json"
    fi
    mapfile -t ids < <(jq -r '.[].Image' "$A/.inspect.json" 2>/dev/null | sort -u)
    { (( ${#ids[@]} )) && docker image inspect "${ids[@]}" 2>/dev/null; } \
        | jq -c '[.[] | {id: .Id, tags: (.RepoTags // []), digests: (.RepoDigests // [])}]' >"$A/.images.json" 2>/dev/null
    jq -e 'type == "array"' "$A/.images.json" >/dev/null 2>&1 || echo '[]' >"$A/.images.json"
    for c in "${cts[@]}"; do
        [[ -n "${PKG_TPL[$c]:-}" ]] && { cp -a "${PKG_TPL[$c]}" "$A/" 2>>"$LOG_FILE" || { warn "App '${PKG_APP_NAME[$f]}': template ${PKG_TPL[$c]} not copied"; pkg_mark apps "$f" warnings; }; }
    done
    if [[ "${PKG_APP_TYPE[$f]}" == "compose" ]]; then
        p="${PKG_APP_NAME[$f]}"; wd="${PKG_PROJ_WD[$p]:-}"
        cm="$(cm_dir_of "$p" "$wd")" || cm=""
        PKG_APP_CM[$f]="$cm"
        if [[ -n "$cm" ]]; then
            for x in "$cm"/* "$cm"/.[!.]*; do pkg_copy_small "$x" "$A/compose"; done
        fi
        # the files docker compose read outside that folder (an indirect stack, docker compose by hand)
        IFS=',' read -r -a used <<<"${PKG_PROJ_CFG[$p]:-}"
        [[ -n "$wd" ]] && used+=( "${wd%/}/.env" )
        for x in "${used[@]}"; do
            [[ -n "$cm" && "$(dirname "$x")" == "$cm" ]] && continue
            pkg_copy_small "$x" "$A/compose-files"
        done
        pkg_build_files "$f" "$p" "$wd" "$cm" "$A"
        if [[ ! -d "$A/compose" && ! -d "$A/compose-files" ]]; then
            warn "App '$p': no compose files found (neither in the Compose Manager nor where docker compose read them)"
            pkg_mark apps "$f" warnings
        fi
    fi
    # the apps' own backups (since 2.19): named in the manifest, for the restore help
    PKG_OWN[$f]="$(for c in "${cts[@]}"; do own_backups_of "$c"; done | jq -Rn '[inputs | split("\u001f")
        | {kind: .[0], container: .[1], path: .[2], files: (.[3] | tonumber), newest: (.[4] | tonumber), asleep: (.[5] == "1")}]')" || PKG_OWN[$f]='[]'
    for c in "${cts[@]}"; do
        [[ -n "${NC_OCC[$c]:-}" && -z "${NC_SAME[$c]:-}" ]] || continue
        mkdir -p "$A/nextcloud"
        docker exec "$c" cat "$(dirname "${NC_OCC[$c]}")/config/config.php" >"$A/nextcloud/${c}_config.php" 2>/dev/null \
            || { warn "config.php of '$c' not readable"; rm -f "$A/nextcloud/${c}_config.php"; }
        [[ -n "${NC_DBDOWN[$c]:-}" ]] && continue          # no database: occ answers nothing (2.64)
        for x in status app:list "config:list system" files_external:list user:list; do
            # shellcheck disable=SC2086
            nc_occ "$c" $x >"$A/nextcloud/${c}_occ-$(tr ' :' '--' <<<"$x").txt" 2>&1
        done
    done
    return 0
}

# vms/<vm>/: its XML, NVRAM (also of its Unraid VM snapshots), TPM state and Unraid's snapshot list -
# after the VMs are held, so they match the disks in the snapshot
pkg_vms() {
    local f
    for f in "${PKG_VMS[@]}"; do pkg_vm "$f"; done
    return 0
}

pkg_vm() {
    local f="$1" n="${PKG_VM_NAME[$1]}" V="$PKG_STAGE/vms/$1" Q="$UB_LIBVIRT/qemu" uuid nv x disks auto=false
    local t s b fs ds share pth rel sz snap
    PKG_RESULT[vms/$f]="ok"
    mkdir -p "$V"
    if [[ -f "$Q/$n.xml" ]]; then cp -a "$Q/$n.xml" "$V/$f.xml" 2>>"$LOG_FILE"
    else timeout 20 virsh dumpxml --inactive --security-info "$n" >"$V/$f.xml" 2>>"$LOG_FILE"; fi
    if [[ ! -s "$V/$f.xml" ]]; then
        err "VM '$n': its configuration (XML) could not be read - its package of the last run stays"
        PKG_RESULT[vms/$f]="errors"; rm -rf -- "$V"; return 1
    fi
    uuid="$(sed -n 's:.*<uuid>\([^<]*\)</uuid>.*:\1:p' "$V/$f.xml" | head -1)"
    nv="$(sed -n 's:.*<nvram[^>]*>\([^<]*\)</nvram>.*:\1:p' "$V/$f.xml" | head -1)"
    if [[ -n "$nv" ]]; then
        if [[ -f "$nv" ]]; then mkdir -p "$V/nvram" && cp -a "$nv" "$V/nvram/" 2>>"$LOG_FILE"
        else warn "VM '$n': its NVRAM $nv is missing"; pkg_mark vms "$f" warnings; fi
        if [[ -n "$uuid" ]]; then
            for x in "$Q/nvram/$uuid"S*; do [[ -f "$x" ]] && { mkdir -p "$V/nvram"; cp -a "$x" "$V/nvram/" 2>>"$LOG_FILE"; }; done
        fi
    fi
    if grep -q '<tpm ' "$V/$f.xml"; then
        # a TPM state exists once the VM ran with its TPM
        if [[ -n "$uuid" && -d "$Q/swtpm/tpm-states/$uuid" ]]; then mkdir -p "$V/tpm" && cp -a "$Q/swtpm/tpm-states/$uuid" "$V/tpm/" 2>>"$LOG_FILE"
        else log "  VM '$n' has a TPM but no TPM state yet (never started with it?)"; fi
    fi
    [[ -d "$Q/snapshotdb/$n" ]] && cp -a "$Q/snapshotdb/$n" "$V/snapshotdb" 2>>"$LOG_FILE"
    [[ -e "$Q/autostart/$n.xml" ]] && auto=true
    # its disks: where they lie and which snapshot of this run holds them
    disks="$(while IFS='|' read -r t s b fs ds share; do
            [[ -z "$t" ]] && continue
            pth="$s"
            if [[ -n "$b" && ( "$s" == "$UB_MNT"/user/* || "$s" == "$UB_MNT"/user0/* ) ]]; then
                rel="${s#"$UB_MNT"/user/}"; rel="${rel#"$UB_MNT"/user0/}"; pth="${INV_BASE_PATH[$b]}/$rel"
            fi
            sz=""; [[ ( -z "$b" || -z "${ASLEEP_BASE[$b]:-}" ) && -f "$pth" ]] && sz="$(stat -c %s "$pth" 2>/dev/null)"     # its pool asleep (2.28): not looked at
            snap=""
            case "$fs" in
                zfs)   in_list "$ds" "${PLAN_ZFS[@]}" && snap="$ds@$SNAP_NAME" ;;
                btrfs) in_list "${INV_BASE_PATH[$b]:-?}" "${PLAN_BTRFS[@]}" && snap="${INV_BASE_PATH[$b]}/$BTRFS_SNAP_DIR/$TS" ;;
            esac
            printf '%s\x1f' "$t" "$s" "$b" "$fs" "$ds" "$share" "$sz" "$snap"; echo
        done <<<"${VM_DISKS[$n]:-}" | jq -Rn '[inputs | split("\u001f") | {target: .[0], source: .[1], base: .[2], fs: .[3],
            dataset: .[4], share: .[5], bytes: (if .[6] == "" then null else (.[6] | tonumber) end), snapshot: .[7]}]')" || disks='[]'
    PKG_VM_META[$f]="$(jq -nc --arg uuid "$uuid" --arg state "${VM_STATE[$n]:-}" --argjson auto "$auto" \
        --arg prepare "$(vm_prepare "$n")" --arg held "${VM_HELD[$n]:-no}" --argjson disks "${disks:-[]}" \
        '{uuid: $uuid, state: $state, autostart: $auto, prepare: $prepare, held: $held, disks: $disks}')" || PKG_VM_META[$f]='{}'
    return 0
}

# A unit's copy is whole (2.43): the package also INSIDE the unit's own dataset, <mountpoint>/.uso-backup/ (UB_INSIDE_DIR)
# - so every snapshot of the dataset and a partner office's copy of it carry the configuration, and the receiver's
# Mr. Restori can start from the copy alone («Start here…»). Written from the finished stage right before the package
# is swapped in (pkg_commit_apps / pkg_commit_rest): the apps are paused, the VMs shut down or held, the snapshots not
# taken yet. Small files only: a VM's XML, nvram, tpm, snapshotdb and manifest.json; an app's templates, compose files,
# the office's notes (manifest.json, nextcloud/) and this run's dumps (db/*.sql.gz, *.archive.gz) - never the media
# servers' SQLite copies (large; their data is in the snapshot anyway), never a second copy of the data. Overwritten
# each run (the snapshots are the history); built beside as .uso-backup.new-<pid>, then moved into place. A dataset that
# sleeps this run, isn't mounted, or a VM that runs unheld (prepare = none): nothing written, said in the log.
# Only a VM's OWN dataset (VM_OWN_DS: no other VM's disks in it) and an app's OWN dataset (app_own_datasets: no other
# app binds into it) - a shared dataset would be no unit of its own at a partner either.
declare -A APP_DS_USERS=()      # dataset -> the package folders whose containers bind into it (one per line)
APP_DS_USERS_BUILT=""

# path_dataset <path>  -> the backed-up ZFS dataset (PLAN_ZFS) a bind source lies in: the deepest of the share's own
# dataset and its child datasets whose mountpoint holds the path; nothing when not on a dataset of this run
path_dataset() {
    local p="${1%/}" s rel b m layer sub ds mp best="" bestrel="" r
    s="$(src_share "$p")"
    [[ -n "$s" ]] && inv_has_share "$s" || return 0
    if [[ "$p" == "$UB_MNT/user/"* || "$p" == "$UB_MNT/user0/"* ]]; then rel="${p#"$UB_MNT"/user/}"; rel="${rel#"$UB_MNT"/user0/}"
    else
        rel=""
        for b in "${INV_BASES[@]}"; do [[ "$p" == "${INV_BASE_PATH[$b]}/"* ]] && { rel="${p#"${INV_BASE_PATH[$b]}"/}"; break; }; done
    fi
    [[ -n "$rel" ]] || return 0
    while IFS='|' read -r b m layer sub; do
        [[ -n "$b" && "$m" == "zfs" && -n "$layer" && -z "$sub" ]] || continue
        r="$s"
        [[ "$rel" == "$r" || "$rel" == "$r/"* ]] && { best="$layer"; bestrel="$r"; }
    done <<<"${INV_LOCS[$s]:-}"
    while IFS='|' read -r b ds mp; do
        [[ -n "$ds" && -n "$mp" && "$mp" == "${INV_BASE_PATH[$b]:-?}/"* ]] || continue
        r="${mp#"${INV_BASE_PATH[$b]}"/}"
        [[ "$rel" == "$r" || "$rel" == "$r/"* ]] || continue
        (( ${#r} > ${#bestrel} )) && { best="$ds"; bestrel="$r"; }
    done <<<"${INV_CHILDREN[$s]:-}"
    [[ -n "$best" ]] && in_list "$best" "${PLAN_ZFS[@]}" && printf '%s\n' "$best"
    return 0
}

app_ds_users_build() {
    local f c src ds
    APP_DS_USERS=()
    for f in "${PKG_APPS[@]}"; do
        while IFS= read -r c; do
            [[ -n "$c" ]] || continue
            while IFS='|' read -r src _ _; do
                [[ -n "$src" ]] || continue
                ds="$(path_dataset "$src")"
                [[ -n "$ds" ]] || continue
                grep -Fxq -- "$f" <<<"${APP_DS_USERS[$ds]:-}" || APP_DS_USERS[$ds]+="$f"$'\n'
            done <<<"${CT_BINDS[$c]:-}"
        done <<<"${PKG_APP_MEMBERS[$f]}"
    done
    APP_DS_USERS_BUILT=1
}

# app_own_datasets <package folder>  -> the datasets only this app's containers bind into, one per line
app_own_datasets() {
    local ds
    [[ -n "$APP_DS_USERS_BUILT" ]] || app_ds_users_build
    for ds in "${!APP_DS_USERS[@]}"; do
        [[ "$(sed '/^$/d' <<<"${APP_DS_USERS[$ds]}" | sort -u | wc -l)" -eq 1 ]] && grep -Fxq -- "$1" <<<"${APP_DS_USERS[$ds]}" && printf '%s\n' "$ds"
    done | sort
    return 0
}

# pkg_inside <vm|app> <name> <stage dir> <folder>  - the package into <folder>/.uso-backup/ (2.44: the unit's folder in
# whatever snapshotted dataset it lies in - a VM's folder in the share's dataset travels with the share's copy, so it
# must carry the configuration too; up to 2.43 only a folder that was a dataset of its own)
pkg_inside() {
    local kind="$1" n="$2" src="$3" mp="$4" ds d tmp x y what
    ds="$(path_dataset "$mp")"
    if [[ -z "$ds" ]]; then log "  $kind '$n': $mp lies in no dataset of this run - $UB_INSIDE_DIR inside not written"; return 0; fi
    if [[ -n "${ASLEEP_DS[$ds]:-}" ]]; then log "  $kind '$n': its dataset $ds sleeps this run - $UB_INSIDE_DIR inside not written"; return 0; fi
    if [[ "$mp" != /* || ! -d "$mp" || -L "$mp" ]]; then log "  $kind '$n': $mp isn't there - $UB_INSIDE_DIR inside not written"; return 0; fi
    d="$mp/$UB_INSIDE_DIR"; tmp="$d.new-$$"
    rm -rf -- "$tmp"
    mkdir -m 700 "$tmp" 2>>"$LOG_FILE" || { warn "$kind '$n': $tmp could not be made - $UB_INSIDE_DIR inside not written"; return 0; }
    for x in "$src"/* "$src"/.[!.]*; do
        [[ -e "$x" ]] || continue
        case "${x##*/}" in .inspect.json|.images.json) continue ;; esac
        if [[ "$kind" == "app" && "${x##*/}" == "db" && -d "$x" ]]; then
            for y in "$x"/*; do
                [[ -f "$y" && "${y##*/}" != sqlite_* ]] || continue
                mkdir -p "$tmp/db" && cp -a "$y" "$tmp/db/" 2>>"$LOG_FILE" || warn "$kind '$n': ${y##*/} not copied into $d"
            done
            continue
        fi
        cp -a "$x" "$tmp/" 2>>"$LOG_FILE" || warn "$kind '$n': ${x##*/} not copied into $d"
    done
    if [[ "$kind" == "vm" ]]; then what="this VM's configuration as of the last backup (XML, UEFI variables, TPM state, Unraid's snapshot list, manifest.json)"
    else what="this app's package as of the last backup (template or compose files, the database dumps, the backup's notes)"; fi
    printf '%s\n' "Written by the Unraid Secretary Office's backup engine: $what, so that every snapshot of this folder and every partner office's copy of it carries the configuration - Mr. Restori's «Start here...» reads it." \
        "Overwritten by every backup run - nothing of yours belongs in here." >"$tmp/README.txt"
    if rm -rf -- "$d" 2>>"$LOG_FILE" && mv -f -- "$tmp" "$d" 2>>"$LOG_FILE"; then
        log "  $kind '$n': its package also inside its folder ($d, $(human "$(du -sb "$d" 2>/dev/null | cut -f1)"))"
    else
        warn "$kind '$n': $d could not be written"; rm -rf -- "$tmp"
    fi
    return 0
}

# ub_pool_path <path>  -> the path as the pool or disk holds it (/mnt/user/<share>/x -> <base>/<share>/x, the first base
# where it exists; a pool path stays); empty when it lies nowhere the inventory knows
ub_pool_path() {
    local p="${1%/}" rel b
    if [[ "$p" == "$UB_MNT/user/"* || "$p" == "$UB_MNT/user0/"* ]]; then
        rel="${p#"$UB_MNT"/user/}"; rel="${rel#"$UB_MNT"/user0/}"
        while IFS= read -r b; do
            [[ -n "$b" && -e "${INV_BASE_PATH[$b]}/$rel" ]] && { printf '%s\n' "${INV_BASE_PATH[$b]}/$rel"; return 0; }
        done < <(ub_path_bases "$p")
        return 0
    fi
    for b in "${INV_BASES[@]}"; do [[ "$p" == "${INV_BASE_PATH[$b]}/"* ]] && { printf '%s\n' "$p"; return 0; }; done
    return 0
}

# pkg_inside_vm <folder> <stage dir>  - a VM: the folder its disks lie in (the first disk's; every distinct one), only
# shut down or held (never running unpaused)
pkg_inside_vm() {
    local f="$1" src="$2" n="${PKG_VM_NAME[$1]}" t s b fs ds share pth dir
    local -A seen=()
    if [[ "${VM_STATE[$n]:-}" == "running" && -z "${VM_SHUT_DOWN[$n]:-}" && "${VM_HELD[$n]:-}" != "paused" && "${VM_HELD[$n]:-}" != "frozen" ]]; then
        log "  VM '$n' runs unheld (prepare none) - $UB_INSIDE_DIR inside its folder not written this run"
        return 0
    fi
    while IFS='|' read -r t s b fs ds share; do
        [[ -n "$t" && -n "$s" ]] || continue
        [[ "$s" == *.iso || "$s" == *.ISO || "$share" == "isos" ]] && continue      # a CD-ROM: not the VM's folder
        pth="$(ub_pool_path "$s")"; [[ -n "$pth" ]] || continue
        dir="${pth%/*}"
        [[ -n "${seen[$dir]:-}" ]] && continue
        seen[$dir]=1
        # never a share's root (a disk lying right in /mnt/user/<share>): the folder of a unit, not the share
        for b in "${INV_BASES[@]}"; do [[ "$dir" == "${INV_BASE_PATH[$b]}" || "${dir#"${INV_BASE_PATH[$b]}"/}" != */* && "$dir" == "${INV_BASE_PATH[$b]}/"* ]] && { dir=""; break; }; done
        [[ -n "$dir" ]] && pkg_inside vm "$n" "$src" "$dir"
    done <<<"${VM_DISKS[$n]:-}"
    return 0
}

# app_dirs_build / app_own_dirs <folder>  -> the first-level folders below a share an app's containers bind into
# (appdata/<app>, a folder of its own in another share) that no other app binds into - as pool paths; and the
# mountpoints of datasets only it binds into (a share of its own)
declare -A APP_DIR_USERS=()
APP_DIR_USERS_BUILT=""
app_dirs_build() {
    local f c src dir rel b ds
    APP_DIR_USERS=()
    app_cfg_rel_load
    for f in "${PKG_APPS[@]}"; do
        while IFS= read -r c; do
            [[ -n "$c" ]] || continue
            while IFS='|' read -r src _ _; do
                [[ -n "$src" ]] || continue
                dir="$(ub_pool_path "$src")"; [[ -n "$dir" ]] || continue
                for b in "${INV_BASES[@]}"; do
                    [[ "$dir" == "${INV_BASE_PATH[$b]}/"* ]] || continue
                    rel="${dir#"${INV_BASE_PATH[$b]}"/}"
                    [[ "$rel" == */* ]] || { dir=""; break; }                   # a whole share: no folder of a unit
                    if [[ "$APP_CFG_REL" == */* && "$rel" == "$APP_CFG_REL/"* ]]; then   # (2.57) appdata deeper in a share
                        rel="${rel#"$APP_CFG_REL"/}"; dir="${INV_BASE_PATH[$b]}/$APP_CFG_REL/${rel%%/*}"
                    else
                        dir="${INV_BASE_PATH[$b]}/${rel%%/*}/$(cut -d/ -f2 <<<"$rel")"
                    fi
                    break
                done
                [[ -n "$dir" ]] || continue
                grep -Fxq -- "$f" <<<"${APP_DIR_USERS[$dir]:-}" || APP_DIR_USERS[$dir]+="$f"$'\n'
            done <<<"${CT_BINDS[$c]:-}"
        done <<<"${PKG_APP_MEMBERS[$f]}"
    done
    APP_DIR_USERS_BUILT=1
}
app_own_dirs() {
    local dir ds
    [[ -n "$APP_DIR_USERS_BUILT" ]] || app_dirs_build
    {
        for dir in "${!APP_DIR_USERS[@]}"; do
            [[ "$(sed '/^$/d' <<<"${APP_DIR_USERS[$dir]}" | sort -u | wc -l)" -eq 1 ]] && grep -Fxq -- "$1" <<<"${APP_DIR_USERS[$dir]}" && printf '%s\n' "$dir"
        done
        while IFS= read -r ds; do [[ -n "$ds" && "${ZDS_MP[$ds]:-}" == /* ]] && printf '%s\n' "${ZDS_MP[$ds]}"; done < <(app_own_datasets "$1")
    } | sort -u
    return 0
}

# (2.57) An app's package goes only into the app's own CONFIGURATION folder: a folder inside Unraid's place for app
# configurations (docker.cfg DOCKER_APP_CONFIG_PATH, e.g. /mnt/user/appdata -> appdata/<app>, or a dataset only this app
# binds that lies inside it) - never a share's root (a folder or a dataset that is a whole share: an S3 gateway sees a
# dot folder there as a bucket) and never a folder of another share (that is the app's data: a versioning store, a
# drop folder). Up to 2.56 every folder and own dataset the app's containers bind got one.
APP_CFG_REL=""                  # DOCKER_APP_CONFIG_PATH below its base (/mnt/user/appdata -> appdata; system/appdata)
app_cfg_rel_load() {
    [[ -n "$APP_CFG_REL" ]] && return 0
    local p r
    p="$(sed -n 's/^DOCKER_APP_CONFIG_PATH="\{0,1\}\([^"]*\)"\{0,1\}[[:space:]]*$/\1/p' "$UB_BOOT/config/docker.cfg" 2>/dev/null | tail -n 1)"
    p="${p%/}"; r=""
    if [[ "$p" == /mnt/*/* && "/$p/" != *"/../"* && "/$p/" != *"/./"* && "$p" != *//* ]] && ub_path_share_part "$p" >/dev/null; then
        r="${p#/mnt/}"; r="${r#*/}"
    fi
    APP_CFG_REL="${r:-appdata}"         # docker.cfg unreadable or no share path: Unraid's default
    return 0
}

# app_cfg_place <pool path>  -> 0 when it lies inside the app configurations' place (never that place itself)
app_cfg_place() {
    local p="${1%/}" b
    app_cfg_rel_load
    for b in "${INV_BASES[@]}"; do
        [[ "$p" == "${INV_BASE_PATH[$b]}/"* ]] || continue
        [[ "${p#"${INV_BASE_PATH[$b]}"/}" == "$APP_CFG_REL/"?* ]]
        return
    done
    return 1
}

# inside_leftover <who> <folder>  - (2.57) a package an earlier engine wrote into a place the app's package no longer
# goes (<folder>/.uso-backup) is moved away once, into state/inside-aside/<run>/ (the engine's data folder, root's) -
# only a real folder whose README.txt the engine wrote, only on a dataset of this run that is awake; nothing else touched
inside_leftover() {
    local who="$1" dir="${2%/}" d first ds to
    d="$dir/$UB_INSIDE_DIR"
    ds="$(path_dataset "$dir")"
    [[ -n "$ds" && -z "${ASLEEP_DS[$ds]:-}" ]] || return 0
    [[ -d "$dir" && ! -L "$dir" && -d "$d" && ! -L "$d" && -f "$d/README.txt" && ! -L "$d/README.txt" ]] || return 0
    first=""; IFS= read -r first <"$d/README.txt" 2>/dev/null || true
    if [[ "$first" != "Written by the Unraid Secretary Office's backup engine"* ]]; then
        log "  $who: $d isn't the backup engine's - left as it is"; return 0
    fi
    to="$UB_STATE/inside-aside/$TS"
    mkdir -p -m 700 "$to" 2>>"$LOG_FILE" || { log "  $who: $to could not be made - $d stays"; return 0; }
    to="$to/$(printf '%s' "${dir#/}" | tr '/' '_')"
    [[ -e "$to" ]] && to="$to-$$"
    if mv -- "$d" "$to" 2>>"$LOG_FILE"; then
        log "  $who: the package an earlier run wrote into $d moved away to $to"
    else
        log "  $who: the package an earlier run wrote into $d could not be moved away - it stays"
    fi
    return 0
}

# inside_leftover_roots  - (2.57) the roots of the run's shares (the mountpoints of its ZFS datasets that are a whole
# share): a package never goes there (no VM's, no app's), so one an earlier engine wrote there is a leftover too (an app
# that was once the only one binding appdata, a share only one app binds)
inside_leftover_roots() {
    local ds mp b
    for ds in "${PLAN_ZFS[@]}"; do
        mp="${ZDS_MP[$ds]:-}"
        [[ "$mp" == /* ]] || continue
        for b in "${INV_BASES[@]}"; do
            [[ "$mp" == "${INV_BASE_PATH[$b]}/"* && "${mp#"${INV_BASE_PATH[$b]}"/}" != */* ]] || continue
            [[ -z "${ASLEEP_DS[$ds]:-}" ]] && inside_leftover "share '${mp##*/}'" "$mp"
            break
        done
    done
    return 0
}

# app_db_datadir <package folder> <pool path>  -> (2.61) 0 when the folder is a database's data folder: a container of the
# app binds exactly it as MariaDB's/MySQL's or Postgres's data folder (the official images' paths), or it holds their
# marks at its top (ibdata1, aria_log_control, mysql.ibd; PG_VERSION). A .uso-backup there is no app folder's: MariaDB
# lists every folder of its data folder as a database (#mysql50#.uso-backup - its dump fails), and Postgres's PGDATA is
# no place for foreign files either. Printed: why, for the log line
app_db_datadir() {
    local f="$1" dir="${2%/}" c src dst x
    while IFS= read -r c; do
        [[ -n "$c" ]] || continue
        while IFS='|' read -r src dst _; do
            [[ -n "$src" ]] || continue
            case "${dst%/}" in /var/lib/mysql|/var/lib/mariadb|/var/lib/postgresql/data) ;; *) continue ;; esac
            [[ "$(ub_pool_path "$src")" == "$dir" ]] && { printf '%s\n' "container '$c' binds it as ${dst%/}"; return 0; }
        done <<<"${CT_BINDS[$c]:-}"
    done <<<"${PKG_APP_MEMBERS[$f]:-}"
    for x in ibdata1 aria_log_control mysql.ibd PG_VERSION; do
        [[ -f "$dir/$x" && ! -L "$dir/$x" ]] && { printf '%s\n' "it holds $x"; return 0; }
    done
    return 1
}

# pkg_inside_app <folder> <stage dir>  - an app: its folders (app_own_dirs) inside the app configurations' place, never
# a database's data folder (2.61)
pkg_inside_app() {
    local f="$1" src="$2" dir n="${PKG_APP_NAME[$1]}" why
    while IFS= read -r dir; do
        [[ -n "$dir" ]] || continue
        if app_cfg_place "$dir" && why="$(app_db_datadir "$f" "$dir")"; then
            log "  app '$n': $dir is a database's data folder ($why) - $UB_INSIDE_DIR not written there"
            inside_leftover "app '$n'" "$dir"
        elif app_cfg_place "$dir"; then
            pkg_inside app "$n" "$src" "$dir"
        else
            log "  app '$n': $dir is a share's data, not the app's folder - $UB_INSIDE_DIR not written"
            inside_leftover "app '$n'" "$dir"
        fi
    done < <(app_own_dirs "$f")
    return 0
}

# Contents of libvirt.img (mounted at /etc/libvirt): XML, NVRAM, TPM states, snapshot lists,
# networks of all VMs - as a whole in server/, besides the VM packages. Not the image itself: that
# is a mounted btrfs file system, a copy taken while VMs run could be inconsistent.
libvirt_tar() {
    local out="$PKG_STAGE/server/libvirt.tar.gz"
    if ! mountpoint -q /etc/libvirt; then
        log "VM service off - no libvirt archive (the package keeps the last one)"
        return 0
    fi
    log "Backing up the VM configuration (/etc/libvirt) as an archive ..."
    if tar -C /etc -czf "$out" libvirt 2>>"$LOG_FILE" && gzip -t "$out" 2>/dev/null; then
        log "  OK: $(human "$(stat -c %s "$out")")"
    else
        err "libvirt archive failed - the one of the last run stays"; rm -f "$out"; pkg_mark . server errors
    fi
}

flash_tar() {
    local out="$PKG_STAGE/flash/flash.tar.gz" ex=() e
    for e in "${FLASH_TAR_EXCLUDE[@]}"; do ex+=( "--exclude=$e" ); done
    # 2.76: the office's USO-network auth key never goes into an archive, whatever tar_exclude says (it lies on the flash
    # only until its node is in; a join that died could leave it there - a secret that never leaves the server).
    # 2.84: the one list for the archive and the Kopia source (lib/common.sh FLASH_NEVER)
    while IFS= read -r e; do [[ -n "$e" ]] && ex+=( "$e" ); done < <(flash_never_tar)
    mkdir -p "$PKG_STAGE/flash"
    PKG_RESULT[flash]="ok"
    log "Backing up $UB_BOOT as an archive ..."
    if tar -C "$UB_BOOT" "${ex[@]}" -czf "$out" . 2>>"$LOG_FILE" && gzip -t "$out" 2>/dev/null; then
        log "  OK: $(human "$(stat -c %s "$out")")"
    else
        err "Flash archive failed - the one of the last run stays"; rm -f "$out"; pkg_mark . flash errors
    fi
}

# pkg_keep <old dir> <new dir> <relative path>  - a file of the last package comes along: a hard
# link where the file system allows it, a copy otherwise. Prints its size.
pkg_keep() {
    local o="$1/$3" n="$2/$3"
    [[ -f "$o" && ! -L "$o" && ! -e "$n" ]] || return 1
    mkdir -p "$(dirname "$n")" || return 1
    ln "$o" "$n" 2>/dev/null || cp -a "$o" "$n" 2>>"$LOG_FILE" || return 1
    stat -c %s "$n" 2>/dev/null || echo 0
}

pkg_what() { # pkg_what <kind> <path>  - what a file of a package is
    case "$1:$2" in
        app:db/*.stderr) echo error ;;
        app:db/sqlite_*) echo sqlite ;;
        app:db/*)        echo dump ;;
        app:compose/*|app:compose-files/*|app:build/*) echo compose ;;
        app:nextcloud/*) echo nextcloud ;;
        app:my-*.xml)    echo template ;;
        vm:nvram/*)      echo nvram ;;
        vm:tpm/*)        echo tpm ;;
        vm:snapshotdb/*) echo snapshots ;;
        vm:*.xml)        echo xml ;;
        *:libvirt.tar.gz) echo libvirt ;;
        *:flash.tar.gz)  echo flash ;;
        *)               echo other ;;
    esac
}

# pkg_files_json <dir> <package key> <kind>  -> [{path, bytes, run, what, container}] of its files
pkg_files_json() {
    local D="$1" k="$2" kind="$3" p s
    ( cd "$D" && find . -type f ! -name manifest.json ! -name run.json ! -name '.inspect.json' ! -name '.images.json' -printf '%P\t%s\n' ) \
        | LC_ALL=C sort | while IFS=$'\t' read -r p s; do
            printf '%s\x1f%s\x1f%s\x1f%s\x1f%s\n' "$p" "$s" "${PKG_FILE_RUN[$k/$p]:-$TS}" "$(pkg_what "$kind" "$p")" "${DUMP_FILE_CT[$k/$p]:-}"
        done | jq -Rn '[inputs | split("\u001f") | {path: .[0], bytes: (.[1] | tonumber), run: .[2], what: .[3], container: .[4]}]'
}

# A dump that failed or did not run this time (container stopped): the last good one comes along,
# unless this run dumped that container completely (then a missing file is a database that is gone)
pkg_keep_dumps() { # pkg_keep_dumps <folder> <old dir> <new dir>
    local f="$1" old="$2" new="$3" p c r sz last o skip
    if [[ ! -f "$old/manifest.json" ]]; then
        # no package yet (the first run since 2.18): the newest run folder of the engine before
        last="$(old_runs_list "$UB_DUMPS" | LC_ALL=C sort | tail -1)"
        [[ -n "$last" && -d "$last/db" ]] || return 0
        while IFS= read -r c; do
            [[ -n "$c" ]] && cfg_has "dump|$c" && [[ "${DUMP_STATE[$c]:-}" != "ok" ]] || continue
            for p in "$last/db/postgres_$c.sql.gz" "$last/db/mongodb_$c.archive.gz" "$last/db/mariadb_${c}_"*.sql.gz; do
                [[ -f "$p" ]] || continue
                skip=0      # mariadb_<c>_<db>: not the dump of another container whose name begins with <c>_
                while IFS= read -r o; do [[ "$o" != "$c" && "$o" == "${c}_"* && "${p##*/}" == "mariadb_${o}_"* ]] && skip=1; done < <(cfg_names dump)
                (( skip )) && continue
                if sz="$(pkg_keep "$last" "$new" "db/${p##*/}")"; then
                    PKG_FILE_RUN[apps/$f/db/${p##*/}]="${last##*/}"; DUMP_FILE_CT[apps/$f/db/${p##*/}]="$c"
                    PKG_KEPT[apps/$f]=$(( ${PKG_KEPT[apps/$f]:-0} + 1 ))
                    log "  db/${p##*/} of '$c' comes into the package from the run folder ${last##*/}"
                fi
            done
        done <<<"${PKG_APP_MEMBERS[$f]}"
        return 0
    fi
    while IFS=$'\x1f' read -r p c r; do
        [[ "$p" == db/* && "$p" != *.stderr && -n "$c" ]] || continue
        cfg_has "dump|$c" || continue
        [[ "${DUMP_STATE[$c]:-}" == "ok" ]] && continue
        if sz="$(pkg_keep "$old" "$new" "$p")"; then
            PKG_FILE_RUN[apps/$f/$p]="$r"; DUMP_FILE_CT[apps/$f/$p]="$c"
            PKG_KEPT[apps/$f]=$(( ${PKG_KEPT[apps/$f]:-0} + 1 ))
            log "  $p of '$c' stays in the package from run $r"
        fi
    done < <(jq -r '.files[]? | [.path, (.container // ""), (.run // "")] | join("\u001f")' "$old/manifest.json" 2>/dev/null)
}

# pkg_swap <sub> <folder>  - the new package in place of the last one: the last one aside, the new one
# in, the last one gone. A run killed in between leaves .ub-old-<run>-<folder> (pkg_recover)
pkg_swap() {
    local sub="$1" f="$2" new dst aside
    if [[ "$sub" == "." ]]; then new="$PKG_STAGE/$f"; dst="$UB_DUMPS/$f"; aside="$UB_DUMPS/.ub-old-$TS-$f"
    else new="$PKG_STAGE/$sub/$f"; dst="$UB_DUMPS/$sub/$f"; aside="$UB_DUMPS/$sub/.ub-old-$TS-$f"; fi
    [[ -d "$new" ]] || return 1
    chmod -R go-rwx "$new" 2>/dev/null       # root only: templates and .env hold passwords
    if [[ -e "$dst" ]] && ! mv -- "$dst" "$aside" 2>>"$LOG_FILE"; then
        err "Package $sub/$f: the last one could not be put aside - it stays, the new one is dropped"
        rm -rf -- "$new"; return 1
    fi
    if mv -- "$new" "$dst" 2>>"$LOG_FILE"; then rm -rf -- "$aside"; return 0; fi
    err "Package $sub/$f could not be put in place - the last one stays"
    [[ -e "$aside" && ! -e "$dst" ]] && mv -- "$aside" "$dst"
    return 1
}

# after the swap: files and bytes of a package, and what this run wrote into it
pkg_count() { # pkg_count <key> <dir> <kept bytes>
    local b
    PKG_FILES[$1]="$(find "$2" -type f 2>/dev/null | wc -l)"
    b="$(du -sb "$2" 2>/dev/null | cut -f1)"; is_uint "$b" || b=0
    PKG_BYTES[$1]="$b"; PKG_RUN[$1]="$TS"
    PKG_WRITTEN_BYTES=$(( PKG_WRITTEN_BYTES + b - ${3:-0} ))
    (( PKG_WRITTEN_BYTES < 0 )) && PKG_WRITTEN_BYTES=0
    return 0
}

# the app packages (and flash/) swapped in - after the dumps, before the databases stop
pkg_commit_apps() {
    local f new old files kb dumps nc tpls c lg uv pv cl sq own
    mkdir -p "$UB_DUMPS/apps" "$UB_DUMPS/vms" && chmod 700 "$UB_DUMPS/apps" "$UB_DUMPS/vms"
    for f in "${PKG_APPS[@]}"; do
        new="$PKG_STAGE/apps/$f"; old="$UB_DUMPS/apps/$f"
        [[ -d "$new" ]] || continue
        pkg_keep_dumps "$f" "$old" "$new"
        pkg_keep_sqlite "$f" "$old" "$new"
        files="$(pkg_files_json "$new" "apps/$f" app)" || files='[]'
        kb="$(jq -r --arg r "$TS" '[.[] | select(.run != $r) | .bytes] | add // 0' <<<"$files" 2>/dev/null)"; is_uint "$kb" || kb=0
        kb=$(( kb + ${SQ_UNCHANGED_BYTES[$f]:-0} ))       # unchanged database copies: hard links, nothing written
        sq="$(sqlite_json "$f")" || sq='[]'
        own="${PKG_OWN[$f]:-}"; [[ -n "$own" ]] || own='[]'
        dumps="$(while IFS= read -r c; do
                [[ -n "$c" ]] && cfg_has "dump|$c" || continue
                # no dump this time: the credentials the kept one was made with
                [[ -z "${DUMP_AUTH[$c]:-}" && -f "$old/manifest.json" ]] && DUMP_AUTH[$c]="$(jq -r --arg c "$c" \
                    'first(.dumps[]? | select(.container == $c and .login != "") | [.login, .user_var, .password_var, .client] | join("\u001f")) // ""' \
                    "$old/manifest.json" 2>/dev/null)"
                IFS=$'\x1f' read -r lg uv pv cl <<<"${DUMP_AUTH[$c]:-}"
                printf '%s\x1f' "$c" "$(cfg "dump|$c|type")" "${DUMP_STATE[$c]:-not_run}" "$lg" "$uv" "$pv" "$cl"; echo
            done <<<"${PKG_APP_MEMBERS[$f]}" | jq -Rn '[inputs | split("\u001f") | {container: .[0], type: .[1], state: .[2],
                login: .[3], user_var: .[4], password_var: .[5], client: .[6]}]')" || dumps='[]'
        # (if, not &&: under pipefail a loop ending on a false test would fail the pipe and lose the output)
        nc="$(while IFS= read -r c; do
                if [[ -n "$c" && -n "${NC_OCC[$c]:-}" ]]; then printf '%s\x1f%s\x1f%s\x1f%s\n' "$c" "${NC_OCC[$c]}" "${NC_USER[$c]:-www-data}" "${NC_SAME[$c]:-}"; fi
            done <<<"${PKG_APP_MEMBERS[$f]}" | jq -Rn '[inputs | split("\u001f") | {container: .[0], occ: .[1], user: .[2], same_as: .[3]}]')" || nc='[]'
        tpls="$(while IFS= read -r c; do
                if [[ -n "$c" && -n "${PKG_TPL[$c]:-}" ]]; then printf '%s\x1f%s\n' "$c" "${PKG_TPL[$c]##*/}"; fi
            done <<<"${PKG_APP_MEMBERS[$f]}" | jq -Rn '[inputs | split("\u001f") | {key: .[0], value: .[1]}] | from_entries')" || tpls='{}'
        [[ -n "$tpls" ]] || tpls="{}"
        jq -n --arg engine "$UB_VERSION" --arg type "${PKG_APP_TYPE[$f]}" --arg name "${PKG_APP_NAME[$f]}" --arg folder "$f" \
            --arg run "$TS" --argjson time "$STARTED_AT" --arg host "$(hostname -s 2>/dev/null)" --arg result "${PKG_RESULT[apps/$f]:-ok}" \
            --arg cm "${PKG_APP_CM[$f]:-}" --arg wd "${PKG_PROJ_WD[${PKG_APP_NAME[$f]}]:-}" --arg cfgs "${PKG_PROJ_CFG[${PKG_APP_NAME[$f]}]:-}" \
            --slurpfile ins "$new/.inspect.json" --slurpfile img "$new/.images.json" \
            --argjson files "$files" --argjson dumps "${dumps:-[]}" --argjson nc "${nc:-[]}" --argjson tpls "$tpls" \
            --argjson sq "${sq:-[]}" --argjson own "$own" \
            --argjson dirs "$(app_own_dirs "$f" | sed -E 's#^/mnt/[^/]+/##' | jq -R 'select(length > 0)' | jq -sc . 2>/dev/null || echo '[]')" '
            ($img[0] // []) as $images |
            {interface: 1, engine: $engine, kind: "app", type: $type, name: $name, folder: $folder, run: $run, time: $time,
             host: $host, result: $result,
             compose: (if $type == "compose" then {project: $name, manager_dir: (if $cm == "" then null else ($cm | split("/") | last) end),
                       manager_path: (if $cm == "" then null else $cm end), working_dir: $wd,
                       config_files: ($cfgs | split(",") | map(select(length > 0)))} else null end),
             containers: [($ins[0] // [])[] | . as $c | (.Name | ltrimstr("/")) as $n | {name: $n, image: .Config.Image, image_id: .Image,
                 digests: (first($images[] | select(.id == $c.Image) | .digests) // []), running: .State.Running,
                 paused: (.State.Paused // false),
                 service: (.Config.Labels["com.docker.compose.service"] // null),
                 template: ($tpls[$n] // null),
                 inspect: .}],
             dumps: $dumps, nextcloud: $nc, sqlite: $sq, own_backups: $own, dirs: $dirs, files: $files}' >"$new/manifest.json" 2>>"$LOG_FILE" \
            || { warn "App '${PKG_APP_NAME[$f]}': manifest.json not written"; pkg_mark apps "$f" warnings; }
        rm -f "$new/.inspect.json" "$new/.images.json"
        pkg_inside_app "$f" "$new"              # 2.43: its package also inside its own dataset (before the swap - the stage is complete)
        pkg_swap apps "$f" && pkg_count "apps/$f" "$old" "$kb"
    done
    inside_leftover_roots                       # 2.57: what earlier engines left in a share's root goes aside
    if [[ -d "$PKG_STAGE/flash" ]]; then
        if kb="$(pkg_keep "$UB_DUMPS/flash" "$PKG_STAGE/flash" flash.tar.gz)"; then
            PKG_FILE_RUN[flash/flash.tar.gz]="$(jq -r '.files[]? | select(.path == "flash.tar.gz") | .run' "$UB_DUMPS/flash/manifest.json" 2>/dev/null)"
            PKG_KEPT[flash]=1
        else kb=0; fi
        pkg_plain_manifest flash "$PKG_STAGE/flash"
        pkg_swap . flash && pkg_count flash "$UB_DUMPS/flash" "$kb"
    fi
    return 0
}

pkg_plain_manifest() { # pkg_plain_manifest <flash|server> <dir>  - manifest.json (flash) or run.json (server)
    local k="$1" D="$2" files
    files="$(pkg_files_json "$D" "$k" "$k")" || files='[]'
    jq -n --arg engine "$UB_VERSION" --arg kind "$k" --arg run "$TS" --argjson time "$STARTED_AT" \
        --arg host "$(hostname -s 2>/dev/null)" --arg result "${PKG_RESULT[$k]:-ok}" --argjson files "$files" \
        '{interface: 1, engine: $engine, kind: $kind, run: $run, time: $time, host: $host, result: $result, files: $files}' \
        >"$D/manifest.json" 2>>"$LOG_FILE"
}

# the VM packages and server/ swapped in - while the VMs are held, right before the snapshots
pkg_commit_rest() {
    local f new old files kb meta lines=""
    for f in "${PKG_VMS[@]}"; do
        new="$PKG_STAGE/vms/$f"; old="$UB_DUMPS/vms/$f"
        [[ -d "$new" ]] || continue                       # its XML could not be read: the last package stays
        files="$(pkg_files_json "$new" "vms/$f" vm)" || files='[]'
        meta="${PKG_VM_META[$f]:-}"; [[ -n "$meta" ]] || meta='{}'
        jq -n --arg engine "$UB_VERSION" --arg name "${PKG_VM_NAME[$f]}" --arg folder "$f" --arg run "$TS" --argjson time "$STARTED_AT" \
            --arg host "$(hostname -s 2>/dev/null)" --arg result "${PKG_RESULT[vms/$f]:-ok}" --argjson meta "$meta" \
            --argjson files "$files" --arg xml "$f.xml" \
            '{interface: 1, engine: $engine, kind: "vm", name: $name, folder: $folder, run: $run, time: $time, host: $host,
              result: $result, xml: $xml} + $meta + {files: $files}' >"$new/manifest.json" 2>>"$LOG_FILE" \
            || { warn "VM '${PKG_VM_NAME[$f]}': manifest.json not written"; pkg_mark vms "$f" warnings; }
        pkg_inside_vm "$f" "$new"               # 2.43: its package also inside its own dataset (the VM is shut down or held here)
        pkg_swap vms "$f" && pkg_count "vms/$f" "$old" 0
    done
    # server/: the libvirt archive of the last run stays when this one has none (VM service off, failed)
    kb=0
    if [[ "$LIBVIRT_MODE" == "tar" ]] && kb="$(pkg_keep "$UB_DUMPS/server" "$PKG_STAGE/server" libvirt.tar.gz)"; then
        PKG_FILE_RUN[server/libvirt.tar.gz]="$(jq -r '.files[]? | select(.path == "libvirt.tar.gz") | .run' "$UB_DUMPS/server/run.json" 2>/dev/null)"
        PKG_KEPT[server]=1
    else kb=0; fi
    pkg_plain_manifest server "$PKG_STAGE/server"
    # run.json: which run wrote the packages, and how each one went
    for f in "${PKG_APPS[@]}"; do lines+="app"$'\x1f'"${PKG_APP_NAME[$f]}"$'\x1f'"$f"$'\x1f'"$(pkg_result "apps/$f")"$'\n'; done
    for f in "${PKG_VMS[@]}"; do lines+="vm"$'\x1f'"${PKG_VM_NAME[$f]}"$'\x1f'"$f"$'\x1f'"$(pkg_result "vms/$f")"$'\n'; done
    [[ -n "${PKG_RESULT[flash]:-}" ]] && lines+="flash"$'\x1f'"flash"$'\x1f'"flash"$'\x1f'"$(pkg_result flash)"$'\n'
    if jq --argjson p "$(printf '%s' "$lines" | jq -Rn '[inputs | select(length > 0) | split("\u001f") | {kind: .[0], name: .[1], folder: .[2], result: .[3]}]')" \
          '. + {packages: $p}' "$PKG_STAGE/server/manifest.json" >"$PKG_STAGE/server/run.json" 2>>"$LOG_FILE"; then
        rm -f "$PKG_STAGE/server/manifest.json"
    else
        mv -f "$PKG_STAGE/server/manifest.json" "$PKG_STAGE/server/run.json"
    fi
    pkg_swap . server && pkg_count server "$UB_DUMPS/server" "$kb"
    rmdir "$PKG_STAGE/apps" "$PKG_STAGE/vms" "$PKG_STAGE" 2>/dev/null || rm -rf -- "$PKG_STAGE"
    PKG_COMMITTED="yes"
    pkg_status yes
}

pkg_result() { # pkg_result <key>  - after its swap: its result; errors when it was not swapped in
    [[ -n "${PKG_RUN[$1]:-}" ]] || { echo errors; return; }
    echo "${PKG_RESULT[$1]:-ok}"
}

# status.json "packages": what this run packed (or would pack: a dry run), and the packages of apps and
# VMs it left out (stale - never deleted). written: yes = swapped in, no = a dry run
pkg_status() {
    local written="$1" f k sub d nm rn ty fl by lines="" r
    local -A here=()
    for f in "${PKG_APPS[@]}"; do
        k="apps/$f"; here[$k]=1
        if [[ "$written" == "yes" ]]; then r="$(pkg_result "$k")"; else r="planned"; fi
        lines+="app"$'\x1f'"${PKG_APP_NAME[$f]}"$'\x1f'"$f"$'\x1f'"${PKG_APP_TYPE[$f]}"$'\x1f'"$r"$'\x1f'"${PKG_FILES[$k]:-0}"$'\x1f'"${PKG_BYTES[$k]:-0}"$'\x1f'"${PKG_KEPT[$k]:-0}"$'\x1f'"0"$'\x1f'"${PKG_RUN[$k]:-}"$'\n'
    done
    for f in "${PKG_VMS[@]}"; do
        k="vms/$f"; here[$k]=1
        if [[ "$written" == "yes" ]]; then r="$(pkg_result "$k")"; else r="planned"; fi
        lines+="vm"$'\x1f'"${PKG_VM_NAME[$f]}"$'\x1f'"$f"$'\x1f'"vm"$'\x1f'"$r"$'\x1f'"${PKG_FILES[$k]:-0}"$'\x1f'"${PKG_BYTES[$k]:-0}"$'\x1f'"0"$'\x1f'"0"$'\x1f'"${PKG_RUN[$k]:-}"$'\n'
    done
    if [[ "$written" == "yes" && -n "${PKG_RESULT[flash]:-}" ]]; then
        here[flash]=1
        lines+="flash"$'\x1f'"flash"$'\x1f'"flash"$'\x1f'"flash"$'\x1f'"$(pkg_result flash)"$'\x1f'"${PKG_FILES[flash]:-0}"$'\x1f'"${PKG_BYTES[flash]:-0}"$'\x1f'"${PKG_KEPT[flash]:-0}"$'\x1f'"0"$'\x1f'"${PKG_RUN[flash]:-}"$'\n'
    fi
    # what lies there from earlier runs
    if [[ -n "$UB_DUMPS" && -d "$UB_DUMPS" ]]; then
        for sub in apps vms; do
            for d in "$UB_DUMPS/$sub"/*/; do
                d="${d%/}"; [[ -d "$d" && ! -L "$d" ]] || continue
                [[ -n "${here[$sub/${d##*/}]:-}" ]] && continue
                IFS=$'\x1f' read -r nm rn ty < <(jq -r '[(.name // ""), (.run // ""), (.type // .kind // "")] | join("\u001f")' "$d/manifest.json" 2>/dev/null)
                fl="$(find "$d" -type f 2>/dev/null | wc -l)"; by="$(du -sb "$d" 2>/dev/null | cut -f1)"; is_uint "$by" || by=0
                lines+="${sub%s}"$'\x1f'"${nm:-${d##*/}}"$'\x1f'"${d##*/}"$'\x1f'"${ty:-}"$'\x1f'"stale"$'\x1f'"$fl"$'\x1f'"$by"$'\x1f'"0"$'\x1f'"1"$'\x1f'"${rn:-}"$'\n'
            done
        done
    fi
    ST_PACKAGES="$(printf '%s' "$lines" | jq -Rn --arg base "$UB_DUMPS" --arg written "$written" \
        --argjson old "${PKG_OLD_RUNS:-0}" --arg old_action "$PKG_OLD_ACTION" --argjson wb "${PKG_WRITTEN_BYTES:-0}" '
        [inputs | select(length > 0) | split("\u001f") | {kind: .[0], name: .[1], folder: .[2], type: .[3], result: .[4],
            files: (.[5] | tonumber), bytes: (.[6] | tonumber), kept: (.[7] | tonumber), stale: (.[8] == "1"), run: .[9]}] as $l
        | {base: $base, written: ($written == "yes"), written_bytes: $wb,
           apps: ([$l[] | select(.kind == "app" and (.stale | not))] | length),
           vms: ([$l[] | select(.kind == "vm" and (.stale | not))] | length),
           errors: ([$l[] | select(.result == "errors")] | length),
           warnings: ([$l[] | select(.result == "warnings")] | length),
           stale: ([$l[] | select(.stale)] | length),
           kept: ([$l[].kept] | add // 0),
           old_runs: $old, old_runs_action: (if $old_action == "" then null else $old_action end), list: $l}')" || ST_PACKAGES="null"
    status_write
}

# Run folders of engines before 2.18 (<place>/<YYYYMMDD-HHMM>/): in the backup place, the place before
# the last setup (state/dumps-previous) and the old folder in appdata. list = say what would go.
place_path_ok() { [[ -n "$1" && ( "$1" == "$UB_MNT"/user/?*/"$UB_NAME" || "$1" == "$UB_MNT/user/$UB_OFFICE_SHARE/$UB_DESK_DIR" ) ]]; }
pkg_old_runs() { # pkg_old_runs list|count|remove
    local how="$1" dir d prev="" n=0
    [[ -s "$UB_STATE/dumps-previous" ]] && prev="$(head -1 "$UB_STATE/dumps-previous")"
    place_path_ok "$prev" || prev=""
    for dir in "$UB_DUMPS" "$prev" "$UB_DATA/dumps"; do
        [[ -n "$dir" ]] || continue
        [[ "$dir" == "$UB_DATA/dumps" ]] || place_path_ok "$dir" || continue
        while IFS= read -r d; do
            [[ -z "$d" ]] && continue
            n=$((n+1))
            case "$how" in
                remove) if rm -rf -- "$d"; then log "  removed the old run folder $d"; else warn "The old run folder $d could not be removed"; fi ;;
                list)   log "  would remove the old run folder $d (since 2.18 the packages take their place)" ;;
            esac
        done < <(old_runs_list "$dir")
    done
    if [[ "$how" == "remove" ]]; then
        rmdir "$UB_DATA/dumps" 2>/dev/null
        if [[ -n "$prev" && "$prev" != "$UB_DUMPS" ]]; then
            rmdir "$prev/apps" "$prev/vms" 2>/dev/null; rmdir "$prev" 2>/dev/null
            [[ -d "$prev/apps" || -d "$prev/vms" || -n "$(old_runs_list "$prev")" ]] || rm -f "$UB_STATE/dumps-previous"
        fi
    fi
    PKG_OLD_RUNS=$n
    return 0
}

##############################################################################
# Cleaning up
##############################################################################
prune_zfs() { # prune_zfs <dataset> <"d w m">  - only the engine's snapshots (zfs_prune_select, lib/common.sh)
    local ds="$1" s
    local -a doomed
    ub_prune_own "$ds" || return 0                       # a partner's copy, the storeroom (2.48)
    mapfile -t doomed < <(zfs list -H -t snapshot -o name -s creation -d 1 "$ds" 2>/dev/null | zfs_prune_select "$2")
    [[ ${#doomed[@]} -eq 0 ]] && return 0
    mounts_load
    for s in "${doomed[@]}"; do
        snap_is_ours "$s" || continue                    # never anything else (and never a dataset)
        if in_list "$s" "${MT_SOURCE[@]}"; then log "  kept (mounted): $s"; continue; fi
        zfs destroy "$s" 2>>"$LOG_FILE" && { log "  removed: $s"; PRUNED_ZFS+=( "$s" ); }
    done
}

# The btrfs emergency brake's memory (2.63): state/btrfs-brake.txt, a line "<base> <snapshot>" per disk or pool - the
# newest snapshot the brake released there early. While that one is younger than keep_days, the retention doesn't hold
# on that base (a snapshot it would keep is missing); status.json "btrfs" says so per base. Only lines in exactly this
# shape are read; on rewrite the lines of bases not looked at this run (asleep) and any other line are kept
declare -gA BTRFS_BRAKE_LAST=()
btrfs_brake_load() {
    local l BTRFS_BRAKE_FILE="$UB_STATE/btrfs-brake.txt"
    BTRFS_BRAKE_LAST=()
    [[ -f "$BTRFS_BRAKE_FILE" && ! -L "$BTRFS_BRAKE_FILE" ]] || return 0
    while IFS= read -r l; do
        [[ "$l" =~ ^([A-Za-z0-9_-]{1,40})\ ([0-9]{8}-[0-9]{4})$ ]] && BTRFS_BRAKE_LAST[${BASH_REMATCH[1]}]="${BASH_REMATCH[2]}"
    done <"$BTRFS_BRAKE_FILE"
    return 0
}
btrfs_brake_save() {
    local BTRFS_BRAKE_FILE="$UB_STATE/btrfs-brake.txt" tmp="$UB_STATE/.btrfs-brake.txt.$$" l b
    (( ${#BTRFS_BRAKE_LAST[@]} )) || [[ -f "$BTRFS_BRAKE_FILE" ]] || return 0
    {
        if [[ -f "$BTRFS_BRAKE_FILE" && ! -L "$BTRFS_BRAKE_FILE" ]]; then
            while IFS= read -r l; do
                [[ "$l" =~ ^([A-Za-z0-9_-]{1,40})\ [0-9]{8}-[0-9]{4}$ && -n "${BTRFS_BRAKE_LAST[${BASH_REMATCH[1]}]+x}" ]] && continue
                printf '%s\n' "$l"
            done <"$BTRFS_BRAKE_FILE"
        fi
        for b in "${!BTRFS_BRAKE_LAST[@]}"; do printf '%s %s\n' "$b" "${BTRFS_BRAKE_LAST[$b]}"; done
    } >"$tmp" 2>/dev/null && mv -f "$tmp" "$BTRFS_BRAKE_FILE" 2>/dev/null
    rm -f "$tmp"
    return 0
}

prune_btrfs() {
    local b base sdir s name cutoff free_gb oldest floor released none last held kept first
    local -a ours
    cutoff="$(date -d "-${BTRFS_KEEP_DAYS} days" +%Y%m%d)"
    btrfs_brake_load
    ST_BTRFS=(); ST_BTRFS_LOOKED=1
    for b in "${INV_BASES[@]}"; do
        [[ "${INV_BASE_FS[$b]}" == "btrfs" ]] || continue
        [[ -n "${NOT_LOOKED[$b]:-}" ]] && { log "  btrfs $b: asleep - its snapshots wait for a night it is awake"; ST_BTRFS+=( "$b|asleep" ); continue; }   # 2.28
        base="${INV_BASE_PATH[$b]}"; sdir="$base/$BTRFS_SNAP_DIR"
        [[ -d "$sdir" ]] || continue
        for s in "$sdir"/*/; do
            [[ -d "$s" ]] || continue
            name="$(basename "$s")"
            [[ "$name" =~ ^[0-9]{8}-[0-9]{4}$ && "$name" != "$TS" ]] || continue
            if [[ "${name%%-*}" < "$cutoff" ]]; then
                btrfs subvolume delete "${s%/}" >/dev/null 2>>"$LOG_FILE" && { log "  removed: ${s%/}"; PRUNED_BTRFS+=( "${s%/}" ); }
            fi
        done
        # Emergency brake: when space runs short, release the oldest snapshot each time. The floor scales
        # with the disk (brake_floor_gb, 2.23). Since 2.63 each early delete is a coded warning (btrfs_brake: base,
        # free_gb, floor_gb, snap); the brake reached with none of ours left to release stays a log line (2.23: no
        # warning, no notification every run - space can only be made outside the backup) and a status fact (none_left)
        floor="$(brake_floor_gb $(( $(df -Pm "$base" | awk 'NR==2{print $2}') / 1024 )))"
        released=0; none=0
        while (( floor > 0 )); do
            free_gb=$(( $(df -Pm "$base" | awk 'NR==2{print $4}') / 1024 ))
            (( free_gb >= floor )) && break
            oldest="$(find "$sdir" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null \
                      | grep -E '^[0-9]{8}-[0-9]{4}$' | sort | head -1)"
            if [[ -z "$oldest" || "$oldest" == "$TS" ]]; then
                log "  $b: ${free_gb} GB free (brake at ${floor} GB) - no earlier snapshot of ours left to release"
                none=1
                break
            fi
            btrfs subvolume delete "$sdir/$oldest" >/dev/null 2>>"$LOG_FILE" || break
            PRUNED_BTRFS+=( "$sdir/$oldest" )
            released=$(( released + 1 )); BTRFS_BRAKE_LAST[$b]="$oldest"      # oldest first: the last one is the newest released
            warn_code btrfs_brake "$b: only ${free_gb} GB free (brake at ${floor} GB) - snapshot $oldest deleted early" \
                base="$b" free_gb="$free_gb" floor_gb="$floor" snap="$oldest"
        done
        # what holds on this base now (status.json "btrfs", 2.63): ours left, the oldest of them, whether the brake
        # released one the retention would still keep
        free_gb=$(( $(df -Pm "$base" | awk 'NR==2{print $4}') / 1024 ))
        mapfile -t ours < <(find "$sdir" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null | grep -E '^[0-9]{8}-[0-9]{4}$' | sort)
        kept=${#ours[@]}; first="${ours[0]:-}"
        last="${BTRFS_BRAKE_LAST[$b]:-}"; held=1
        [[ -n "$last" && ! "${last%%-*}" < "$cutoff" ]] && held=0
        ST_BTRFS+=( "$b|ok|$BTRFS_KEEP_DAYS|$floor|$free_gb|$kept|$first|$released|$none|$last|$held" )
    done
    btrfs_brake_save
}

refresh_view() { # browsing view: symlinks instead of bind mounts (they hold no disk)
    local b base l
    mkdir -p "$VIEW_ROOT" 2>/dev/null || return 0
    for l in "$VIEW_ROOT"/*; do [[ -L "$l" && ! -e "$l" ]] && rm -f "$l"; done
    for b in "${INV_BASES[@]}"; do
        [[ "${INV_BASE_FS[$b]}" == "btrfs" ]] || continue
        [[ -n "${NOT_LOOKED[$b]:-}" ]] && continue          # asleep (2.28): not looked at - its link stays as it was
        base="${INV_BASE_PATH[$b]}"
        [[ -d "$base/$BTRFS_SNAP_DIR" ]] || continue
        if [[ -e "$VIEW_ROOT/$b" && ! -L "$VIEW_ROOT/$b" ]]; then
            rmdir "$VIEW_ROOT/$b" 2>/dev/null || continue
        fi
        ln -sfn "$base/$BTRFS_SNAP_DIR" "$VIEW_ROOT/$b"
    done
}

prune_files() {
    local d f bad=0
    # the run folders of engines before 2.18 go once this run's packages are all in place - while a
    # package failed, the last good dump may still lie only there (never with an empty or odd path)
    if place_path_ok "$UB_DUMPS" && [[ "$PKG_COMMITTED" == "yes" ]]; then
        for f in "${!PKG_RESULT[@]}"; do [[ "$(pkg_result "$f")" == "errors" ]] && bad=1; done
        if (( bad )); then
            pkg_old_runs count; PKG_OLD_ACTION="kept"
            (( PKG_OLD_RUNS > 0 )) && log "  ${PKG_OLD_RUNS} old run folder(s) kept: a package of this run had errors"
        else
            pkg_old_runs remove; PKG_OLD_ACTION="removed"
        fi
        (( PKG_OLD_RUNS > 0 )) || PKG_OLD_ACTION=""
        pkg_status yes
    fi
    # the newest keep_logs - this run's own log always one of them (2.48: keep_logs = 0 removed it while it was written)
    ls -1 "$UB_LOGS"/run-*.log "$UB_LOGS"/check-*.log "$UB_LOGS"/dryrun-*.log 2>/dev/null | grep -vxF -- "$LOG_FILE" | LC_ALL=C sort -t- -k2 \
        | awk -v k="$(( KEEP_LOGS > 1 ? KEEP_LOGS - 1 : 0 ))" '{ l[NR] = $0 } END { for (i = 1; i <= NR - k; i++) print l[i] }' \
        | while read -r d; do rm -f "$d"; done
    for d in unmount recover; do
        if [[ -f "$UB_LOGS/$d.log" && $(stat -c %s "$UB_LOGS/$d.log") -gt 1048576 ]]; then
            mv "$UB_LOGS/$d.log" "$UB_LOGS/$d.log.1"
        fi
    done
}

##############################################################################
# Reporting drift
##############################################################################
# drift_report  - the notification's long text: the errors, then the warnings, one per line (the infos stay in drift.txt)
drift_report() {
    local lvl l t n=0
    for lvl in error warn; do
        t=""
        for l in "${DRIFT[@]}"; do
            [[ "${l%%|*}" == "$lvl" ]] || continue
            if [[ -z "$t" ]]; then
                t=1; (( n++ )) && echo
                if [[ "$lvl" == "error" ]]; then echo "ERRORS"; else echo "WARNINGS"; fi
            fi
            echo "  ${l#*|}"
        done
    done
    echo; echo "Please run setup.sh (Mr. Backupsy's setup) to bring settings.ini up to date. All of it: $UB_STATE/drift.txt"
}
report_drift() {
    local fp last_fp="" last_t=0 now nw ne lvl
    drift_text >"$UB_STATE/drift.txt"
    drift_json_write
    status_write
    if [[ ${#DRIFT[@]} -eq 0 ]]; then
        log "No drift from settings.ini."
        rm -f "$UB_STATE/drift.fp" "$UB_STATE/drift.ts"
        return 0
    fi
    log "Drift from settings.ini:"
    while IFS= read -r l; do log "  $l"; done <"$UB_STATE/drift.txt"
    nw=$(drift_count warn); ne=$(drift_count error)
    (( nw + ne == 0 )) && return 0
    fp="$(drift_fingerprint)"
    [[ -f "$UB_STATE/drift.fp" ]] && last_fp="$(cat "$UB_STATE/drift.fp")"
    [[ -f "$UB_STATE/drift.ts" ]] && last_t="$(cat "$UB_STATE/drift.ts")"
    now="$(date +%s)"
    if [[ "$fp" != "$last_fp" || "$UB_MODE" == "check" ]] || (( now - last_t >= DRIFT_REMIND_DAYS * 86400 )); then
        lvl="warning"; (( ne > 0 )) && lvl="alert"
        ub_tell drift "$lvl" "$(drift_report)" "Backup setup out of date — the server changed" \
            "Something on the server changed since the backup was set up (${ne} errors, ${nw} warnings). Open Mr. Backupsy's setup to see what, and Apply to bring the backup up to date. Details: $UB_STATE/drift.txt" \
            "counts=$(ub_counts "$ne" "$nw")"
        echo "$fp" >"$UB_STATE/drift.fp"; echo "$now" >"$UB_STATE/drift.ts"
    fi
}

##############################################################################
# The array is being stopped (2.24)
##############################################################################
# Unraid says so in var.ini (array_stopping, lib/common.sh) the moment an array stop begins: minutes
# before it shuts down the VMs and Docker, long before it unmounts the pools - which this run's lock,
# its log and its mounts would hold up. Up to 2.23 a run noticed nothing until Docker was gone, then
# failed every Kopia source left, pruned snapshots during the stop and sent an alert. Now it looks at
# its safe points - every phase (next_phase), before writing packages, before every dump, Kopia source
# and pruned dataset, every few seconds while Kopia uploads (kopia_one), while it waits for VMs or
# containers - and ends at once (array_stop_abort: exit 3, the EXIT trap does the rest):
#   - the Kopia snapshot going on is interrupted inside the container (kopia_stop: SIGINT to the kopia
#     process, not just its docker exec client - Kopia saves what it uploaded and a checkpoint, the next
#     run continues); the planned sources not done are "skipped" (status.json kopia.skipped), not failed
#   - no retention (nothing is pruned during a stop), no new snapshot, dump or package
#   - nothing is started into the stopping array: containers the run stopped stay stopped (Docker
#     stops the rest now), a VM it shut down stays off - noted in state/stopped and state/vms like a
#     killed run's: right after the array start backup.sh --recover (2.25, the plugin's event/started) - else
#     the first run after it - starts what Unraid's autostart didn't
#   - a frozen VM is thawed and a paused one resumed (array_stop_release): a held guest can't shut down
#     cleanly when libvirt asks - a frozen one would be forced off after Unraid's VM timeout
#   - a Nextcloud whose container still runs leaves maintenance mode; one whose container the run
#     stopped stays in it, noted (state/maintenance) for after the array start - occ needs its container
#   - its mounts go (keep_mounts too), the lock and its note are released - nothing keeps a pool busy
#   - status.json "result": "aborted", "message": "array_stopping", a history line; one notification
#     (normal) - never "errors" or an alert
# Between stopping the apps and the snapshots that means: no snapshot this night (dumps made so far go
# with the stage, the last good packages stay) - rather than snapshotting while Unraid is about to stop
# Docker and unmount the pools; the next run takes the night's backup.
ARRAY_STOP_PHASE=""         # where the run was when it saw the array being stopped
PRUNED_DONE=""              # the retention is done: how many snapshots it removed (in pruned.json; "" = not done)

next_phase() { array_stop_check; status_phase "$1"; }

array_stop_check() { # array_stop_check [what was going on]  - ends the run when the array is being stopped
    [[ "$CLEANUP_ARMED" == "yes" && "$CLEANUP_DONE" != "yes" ]] || return 0     # never from the cleanup itself
    [[ "$ARRAY_STOP" == "yes" ]] || array_stopping || return 0
    array_stop_abort "${1:-}"
}
array_stop_abort() {
    ARRAY_STOP="yes"; ARRAY_STOP_PHASE="${ARRAY_STOP_PHASE:-$ST_PHASE}"
    log "The array is being stopped (Unraid: fsState $(array_fsstate)) - the run ends now${1:+ ($1)}; nothing is started again"
    exit 3
}

# The planned Kopia sources not done: skipped, not failed - the next run does them
array_stop_kopia() {
    local d n
    local -A gone=()
    for d in "${ST_KOPIA_DONE[@]}"; do gone[${d%%|*}]=1; done
    ST_KOPIA_SKIPPED=()
    for n in "${!ST_KOPIA_SKIPPED_WHY[@]}"; do ST_KOPIA_SKIPPED+=( "$n" ); done      # asleep (2.28): skipped from the start
    for n in "${ST_KOPIA_PLAN[@]}"; do [[ -n "${gone[$n]:-}" ]] || ST_KOPIA_SKIPPED+=( "$n" ); done
}

# What the run holds when the array is being stopped (from the cleanup): nothing is started
array_stop_release() {
    local n how secs c out
    if (( ${#VM_HELD[@]} )); then
        log "Releasing the VMs (none is started into the stopping array) ..."
        for n in "${!VM_HELD[@]}"; do
            how="${VM_HELD[$n]}"; secs=$(( $(date +%s) - ${VM_HELD_AT[$n]:-$(date +%s)} ))
            case "$how" in
                frozen)   if timeout 60 virsh domfsthaw "$n" >/dev/null 2>>"$LOG_FILE"; then log "  VM '$n': thawed"
                          else warn "VM '$n': thawing its file systems failed - check the VM"; fi ;;
                paused)   if timeout 30 virsh resume "$n" >/dev/null 2>>"$LOG_FILE"; then log "  VM '$n': resumed (Unraid shuts it down now)"
                          else warn "VM '$n' could not be resumed (Unraid resumes paused VMs before it shuts them down)"; fi ;;
                shutdown) log "  VM '$n': stays off - noted (state/vms): started right after the array start, unless Unraid's autostart did"
                          vm_note "$n" "$(vm_prepare "$n")" "$how" "$secs"; continue ;;
            esac
            vm_note "$n" "$(vm_prepare "$n")" "$how" "$secs"
            unset "VM_HELD[$n]" "VM_HELD_AT[$n]"
        done
    fi
    (( ${#STOPPED[@]} )) && log "  Stay stopped (Docker is being stopped): ${STOPPED[*]} - noted (state/stopped): started right after the array start, those Unraid's autostart didn't"
    for c in "${!NC_ON[@]}"; do
        out=""
        if [[ -n "${NC_OCC[$c]:-}" && "$(timeout 20 docker inspect -f '{{.State.Running}}' "$c" 2>/dev/null)" == "true" ]] \
           && out="$(timeout 60 docker exec -u "${NC_USER[$c]}" "$c" php "${NC_OCC[$c]}" maintenance:mode --off 2>&1)"; then
            log "  Nextcloud '$c': maintenance mode off"; unset "NC_ON[$c]"
        else
            [[ -n "$out" ]] && nc_log_output "$out"
            log "  Nextcloud '$c': stays in maintenance mode (its container is stopped) - noted (state/maintenance): switched off right after the array start"
        fi
    done
    # only notes of its own: an earlier run's (kept by recover_interrupted_run while the array stops) stay as they are
    [[ "$RUN_NOTED" == "yes" ]] && save_restore_state
}

array_stop_report() { # the notification's long text: a headline, then a section per part (like run_report)
    local l n p items=() ns
    echo "The run of $(date -d "@$STARTED_AT" '+%Y-%m-%d %H:%M' 2>/dev/null) ended at once when the array stop began (phase ${ARRAY_STOP_PHASE:-?}), after $(dur_n $(( $(date +%s) - STARTED_AT )))."
    ns=$(( ${#ST_KOPIA_SKIPPED[@]} - ${#ST_KOPIA_SKIPPED_WHY[@]} ))
    if (( ${#ST_KOPIA_PLAN[@]} )); then
        echo; echo "KOPIA   $(( ${#ST_KOPIA_PLAN[@]} - ns )) of ${#ST_KOPIA_PLAN[@]} sources done"
        [[ -n "$ST_KOPIA_INTERRUPTED" ]] && echo "  $ST_KOPIA_INTERRUPTED interrupted (Kopia keeps what it uploaded)"
        echo "  skipped until the next run: $ns"
    fi
    if (( ${#ST_PARTNER_PLAN[@]} )); then
        echo; echo "PARTNERS   ${#ST_PARTNER_DONE[@]} of ${#ST_PARTNER_PLAN[@]} sent"
        for p in "${ST_PARTNER_INTERRUPTED_ALL[@]}"; do echo "  ${p#*|} interrupted (the partner keeps what came; the next run continues it)"; done
    fi
    for l in "${!VM_HELD[@]}"; do items+=( "VM $l" ); done
    for n in "${STOPPED[@]}"; do items+=( "$n" ); done
    for n in "${!NC_ON[@]}"; do items+=( "maintenance mode of $n" ); done
    if (( ${#items[@]} )); then
        echo; echo "LEFT AS THE STOP FOUND THEM - BROUGHT BACK RIGHT AFTER THE ARRAY START"
        wrap_words "  " ", " "${items[@]}"
    fi
    echo; echo "RETENTION"
    if [[ -n "$PRUNED_DONE" ]]; then
        if (( PRUNED_DONE > 0 )); then echo "  done when the stop began: $PRUNED_DONE snapshot(s) removed (state/pruned.json)"
        else echo "  done when the stop began - there was nothing to prune"; fi
    elif (( ${#PRUNED_ZFS[@]} + ${#PRUNED_BTRFS[@]} )); then echo "  $(( ${#PRUNED_ZFS[@]} + ${#PRUNED_BTRFS[@]} )) snapshot(s) removed when the stop began; the rest waits for the next run"
    else echo "  no snapshots were pruned"; fi
    echo; echo "Log: $LOG_FILE"
}
array_stop_notify() {
    [[ "$ST_MODE" == "backup" ]] || return 0           # a check or dry run is started by hand: its answer is seen there
    local short code=array_stop
    if [[ -n "$ST_KOPIA_INTERRUPTED" ]] || (( ${#ST_KOPIA_SKIPPED[@]} > ${#ST_KOPIA_SKIPPED_WHY[@]} && ${#ST_KOPIA_DONE[@]} )); then short="Backup stopped because the array is being stopped - nothing is lost; the next run continues the Kopia upload."; code=array_stop_kopia
    else short="Backup stopped because the array is being stopped - nothing is lost; the next run backs up as usual."; fi
    ub_tell "$code" normal "$(array_stop_report)" "Backup stopped for the array stop" "$short"
}

##############################################################################
# Cleaning up at every end
##############################################################################
# Aborting in the middle of Kopia: end the snapshot inside the container (SIGINT -
# Kopia then saves what it uploaded and a checkpoint, and ends). Ending only the docker exec client is
# not enough, the process in the container would keep running and hold the mounts. docker top names
# the container's processes by their host PIDs; when Docker doesn't answer (it is being stopped), the
# same process is found on the host - its command line begins with kopia (the client's with docker).
kopia_stop() {
    [[ -n "$KOPIA_PID" ]] || return 0
    local pid args t sent=0
    log "Ending the running Kopia snapshot ..."
    while read -r pid args; do
        if [[ "$pid" =~ ^[0-9]+$ && "$args" == *"snapshot create"* && "$args" == *"$KOPIA_CP"* ]]; then
            kill -INT "$pid" 2>/dev/null && sent=1
        fi
    done < <(timeout 20 docker top "$KOPIA_CONTAINER" -eo pid,args 2>/dev/null | tail -n +2)
    if (( ! sent )); then
        local re='^(/[^ ]*/)?kopia '
        for pid in $(pgrep -f 'snapshot create' 2>/dev/null); do
            args="$(tr '\0' ' ' <"/proc/$pid/cmdline" 2>/dev/null)"
            [[ "$args" =~ $re && "$args" == *"snapshot create"* && "$args" == *"$KOPIA_CP"* ]] && kill -INT "$pid" 2>/dev/null && sent=1
        done
    fi
    (( sent )) || log "  (no Kopia process found in the container - its client is ended)"
    for t in $(seq 1 60); do
        kill -0 "$KOPIA_PID" 2>/dev/null || break
        sleep 1
    done
    if kill -0 "$KOPIA_PID" 2>/dev/null; then
        pkill -TERM -P "$KOPIA_PID" 2>/dev/null; kill -TERM "$KOPIA_PID" 2>/dev/null
        warn "Kopia did not end after 60 s"
        sleep 2; kill -0 "$KOPIA_PID" 2>/dev/null && kill -KILL "$KOPIA_PID" 2>/dev/null     # it holds the log (and the lock) open
    fi
    wait "$KOPIA_PID" 2>/dev/null
    KOPIA_PID=""
}

# Ending the transfers to a partner (2.27; 2.73 every one going on): SIGTERM to each whole pipe - zfs send, mbuffer,
# pv and the ssh client; the partner's door sees the stream end, and its zfs recv -s keeps what came as a resume token
# (the next run continues with zfs send -t). Every subshell and every process below them, children first found, all
# signalled at once.
partner_tree() { local c; for c in $(pgrep -P "$1" 2>/dev/null); do partner_tree "$c"; done; printf '%s\n' "$1"; }
partner_stop() {
    (( ${#SL_PID[@]} )) || return 0
    local pids k t left
    log "Ending the transfer$( (( ${#SL_PID[@]} > 1 )) && echo "s") to the partner ..."
    pids="$(for k in "${!SL_PID[@]}"; do partner_tree "${SL_PID[$k]}"; done | paste -sd' ')"
    # shellcheck disable=SC2086  # a list of pids
    kill -TERM $pids 2>/dev/null
    for t in $(seq 1 10); do
        left=0; for k in "${!SL_PID[@]}"; do kill -0 "${SL_PID[$k]}" 2>/dev/null && left=1; done
        (( left )) || break; sleep 1
    done
    pids="$(for k in "${!SL_PID[@]}"; do kill -0 "${SL_PID[$k]}" 2>/dev/null && partner_tree "${SL_PID[$k]}"; done | paste -sd' ')"
    # shellcheck disable=SC2086
    [[ -n "$pids" ]] && kill -KILL $pids 2>/dev/null
    for k in "${!SL_PID[@]}"; do wait "${SL_PID[$k]}" 2>/dev/null; slot_clear "$k"; done
    ST_PARTNER_CUR=""; ST_PARTNER_CUR_BYTES=0; ST_PARTNER_CUR_EST=0; ST_PARTNER_STREAMS=()
}
# partner_interrupted_all: the transfers going on when the run ends early (the array stop, a stop by hand), oldest first
partner_interrupted_all() {
    local k
    for k in $(for k in "${!SL_PID[@]}"; do printf '%s %s\n' "${SL_T0[$k]}" "$k"; done | sort -n -k1,1 -k2,2 | cut -d' ' -f2); do
        partner_interrupted "${SL_ID[$k]}|${SL_U[$k]}"
    done
}
# The planned transfers not done when the run ends early: skipped (the array stop, a stop by hand), never failed
partner_rest_skipped() { # partner_rest_skipped <why>
    local p
    for p in "${ST_PARTNER_PLAN[@]}"; do partner_done_has "$p" || ST_PARTNER_SKIPPED+=( "$p|$1" ); done
}

cleanup() {
    local rc=$? ar_pruned
    [[ "$CLEANUP_DONE" == "yes" ]] && exit "$rc"
    CLEANUP_DONE="yes"
    # ending for another reason (a signal, a failure) while the array is being stopped: then that is the stop
    if [[ "$ARRAY_STOP" != "yes" && "$CLEANUP_ARMED" == "yes" && "$ST_RESULT" == "running" ]] && array_stopping; then
        ARRAY_STOP="yes"; ARRAY_STOP_PHASE="${ARRAY_STOP_PHASE:-$ST_PHASE}"
        log "The array is being stopped (Unraid: fsState $(array_fsstate)) - nothing is started again"
    fi
    if [[ "$ARRAY_STOP" == "yes" ]]; then
        [[ -n "$KOPIA_PID" ]] && ST_KOPIA_INTERRUPTED="$ST_KOPIA_CUR"
        partner_interrupted_all
        status_phase "aborting"
    elif [[ "$ST_ABORTED" == "yes" ]]; then
        warn "Run aborted (signal)"
        partner_interrupted_all
        status_phase "aborting"
    fi
    kopia_stop
    partner_stop
    tmp_files_stop                     # 2.85: the future-files find and the run's files in /tmp
    [[ -n "$PARTNER_TMP" ]] && rm -rf "$PARTNER_TMP"
    # a package half swapped in goes back, the stage goes (the packages of the last run stay)
    [[ -n "$PKG_STAGE" && "$PKG_COMMITTED" != "yes" ]] && pkg_recover "$UB_DUMPS" >/dev/null
    if [[ "$ARRAY_STOP" == "yes" ]]; then
        array_stop_release
        [[ -n "${STOP_AT:-}" && "${DOWNTIME:-0}" == 0 ]] && DOWNTIME=$(( $(date +%s) - STOP_AT ))
    else
        if [[ ${#VM_HELD[@]} -gt 0 ]]; then
            log "Releasing the VMs ..."
            vm_shutdown_wait abort       # one still going down after the run's request: started again once it is off
            vm_release btrfs
        fi
        if [[ ${#STOPPED[@]} -gt 0 || ${#NC_ON[@]} -gt 0 ]]; then
            log "Restoring normal operation ..."
            restore_service
            # a stop mid-run: the interruption lasted until now (otherwise the status says 0 s)
            [[ -n "${STOP_AT:-}" && "${DOWNTIME:-0}" == 0 ]] && DOWNTIME=$(( $(date +%s) - STOP_AT ))
        fi
    fi
    # an array stop (also one this run ends normally in): everything under the engine's mount roots, whatever this
    # run mounted - keep_mounts, a killed run's leftovers (the plugin's array-stop hook leaves them to a live run,
    # 2.25); a mount of ours would keep the pool from unmounting - busy ones are detached (umount -l)
    if [[ "$ARRAY_STOP" == "yes" ]] || array_stopping; then
        unmount_all lazy
    elif [[ "$MOUNTED" == "yes" && "$KEEP_MOUNTS" != "yes" ]]; then
        unmount_all
    fi
    if [[ "$ARRAY_STOP" == "yes" && "$ST_RESULT" == "running" ]]; then
        # stopped in the middle of its retention: what it destroyed until then still goes into pruned.json
        (( ${#PRUNED_ZFS[@]} + ${#PRUNED_BTRFS[@]} )) && pruned_write
        array_stop_kopia
        partner_rest_skipped array_stopping
        status_finish aborted "array_stopping"
        if [[ -n "$PRUNED_DONE" ]]; then ar_pruned="retention done ($PRUNED_DONE removed)"
        elif (( ${#PRUNED_ZFS[@]} + ${#PRUNED_BTRFS[@]} )); then ar_pruned="retention stopped midway"
        else ar_pruned="nothing pruned"; fi
        log "Backup stopped because the array is being stopped - $ar_pruned, nothing started; the next run continues."
        array_stop_notify
        rc=3
    elif [[ "$ST_ABORTED" == "yes" ]]; then partner_rest_skipped signal; status_finish aborted "signal"
    elif (( rc != 0 )); then status_finish failed "exit $rc"
    fi
    ub_holder_clear
    exit "$rc"
}
# 2.48: a second stop while the run is already on its way out (a second click, a second SIGTERM) never cuts the cleanup
# short - the containers, the maintenance mode and the VMs it is bringing back would otherwise wait for the next run
on_signal() {
    if [[ "$CLEANUP_DONE" == "yes" ]]; then
        log "  Stop asked again - the run is already ending: what it stopped comes back first"
        return 0
    fi
    ST_ABORTED="yes"; exit 143
}

# 2.82 (B42): a scheduled start (--scheduled: the plugin's cron, job.sh) finds no settings.ini - gone (appdata lost, or
# restored without it) or never made: no run starts. Not the lock, no log, no status.json (it keeps the last run), no
# notification (the office's Team Lead lists «set up» as a must while it lasts) - one history.jsonl line, "result":
# "skipped", reason settings_missing, which counts on (count, first) while it is the newest instead of a line a night
# (history_count). By hand (a terminal) the run still fails with settings_missing, seen at once. Exit 0.
skip_unset() {
    local now
    now="$(date +%s)"
    history_count "$(jq -nc --argjson interface "$UB_INTERFACE" --arg name "$UB_NAME" --arg version "$UB_VERSION" \
        --arg run "$TS" --argjson pid "$$" --argjson time "$now" \
        '{interface: $interface, name: $name, version: $version, mode: "backup", run: $run, pid: $pid, time: $time,
          started: $time, finished: $time, first: $time, count: 1, result: "skipped", reason: "settings_missing",
          message: "settings_missing", errors: 0, warnings: 0, downtime_s: 0}' 2>/dev/null)" \
        'select(type == "object" and .result == "skipped" and .reason == "settings_missing")'
    echo "The scheduled backup run of $(date '+%Y-%m-%d %H:%M') was not started: settings.ini is missing ($UB_SETTINGS) - nothing was backed up."
    exit 0
}

# The lock is busy: this run does not happen, and nobody may miss that. It touches nothing the run
# going on uses - not status.json (that describes the run going on), not latest.log, no settings, no
# mounts - and says so: state/skipped.json (every mode), a line in history.jsonl and a notification
# (warning) for a real backup run - a check or dry run is only ever started by hand (the office, a
# terminal), where this answer is seen right away. Exit code 75 (EX_TEMPFAIL: try again later), so a
# caller can tell "busy" from "failed".
skip_busy() {
    local mode="backup" st phase="" current="" since="" why what when tw
    [[ "$UB_MODE" == "check" ]] && mode="check"
    [[ "$DRY" == "1" && "$UB_MODE" == "backup" ]] && mode="dryrun"
    ub_holder_read
    # a run of this engine holds it: where it is (status.json describes that run - read, never written)
    if [[ "$HOLDER_KIND" =~ ^(backup|check|dryrun)$ && -n "$HOLDER_RUN" ]]; then
        st="$(jq -r --arg r "$HOLDER_RUN" 'select(.run == $r and .result == "running")
            | [(.phase // "" | tostring), (.kopia.current // "" | tostring)] | map(gsub("[\u0000-\u001f]"; " ")) | join("\u001f")' \
            "$UB_STATE/status.json" 2>/dev/null)"
        IFS=$'\x1f' read -r phase current <<<"$st"
    fi
    status_skipped "$mode" "skipped_busy_$HOLDER_KIND" "$phase" "$current"
    (( HOLDER_STARTED > 0 )) && since="$(date -d "@$HOLDER_STARTED" '+%Y-%m-%d %H:%M' 2>/dev/null)"
    case "$HOLDER_KIND" in
        backup|check|dryrun)
            case "$HOLDER_KIND" in backup) what="the backup run" ;; check) what="a check" ;; *) what="a dry run" ;; esac
            why="$what started ${since:-earlier} is still going"
            if [[ "$phase" == "kopia" ]]; then why+=" (uploading to Kopia${current:+: $current})"
            elif [[ -n "$phase" ]]; then why+=" (phase $phase)"; fi ;;
        setup)   why="setup.sh${HOLDER_MODE:+ ($HOLDER_MODE)} is working right now" ;;
        restore) why="a restore${HOLDER_WHAT:+ of $HOLDER_WHAT} is going on${since:+ (since $since)}" ;;
        *)       why="another program holds the engine's lock ($UB_STATE/lock"
                 (( HOLDER_PID > 0 )) && why+=", PID $HOLDER_PID"
                 why+=")" ;;
    esac
    case "$mode" in
        backup) echo "The backup run of $(date '+%Y-%m-%d %H:%M') was skipped: $why. The next run backs up as usual." ;;
        check)  echo "The check was not started: $why." ;;
        dryrun) echo "The dry run was not started: $why." ;;
    esac
    if [[ "$mode" == "backup" ]]; then
        when="$(date '+%Y-%m-%d %H:%M')"
        tw="$(ub_say "message.skipped_busy_$HOLDER_KIND")" || tw="$(ub_say message.skipped_busy_other)" || tw="$why"
        ub_tell skipped warning "" "Backup skipped" "The run of $when was skipped: $why. The next run backs up as usual." "when=$when" "why=$tw"
    fi
    exit 75
}

# --recover (2.25): Docker - and libvirt, when a VM is noted - answers? Asked every UB_RECOVER_LOOK s, at most
# UB_RECOVER_WAIT s (right after the array start they may still be coming up); 1 when the array is being
# stopped meanwhile. A service still silent then: recover_interrupted_run keeps its notes for the next run.
# libvirt never answers while Unraid's VM service is switched off: not waited for (the VMs' note goes then).
recover_wait() {
    local until=$(( $(date +%s) + UB_RECOVER_WAIT )) docker=0 virt=0 told=0
    [[ -s "$UB_STATE/stopped" || -s "$UB_STATE/maintenance" ]] && ! ub_docker_service_off && docker=1   # off: never answers (2.54)
    [[ -s "$UB_STATE/vms" ]] && command -v virsh >/dev/null 2>&1 && ! ub_vm_service_off && virt=1
    while :; do
        array_stopping && return 1
        (( docker )) && ub_docker_answers && docker=0
        (( virt )) && ub_libvirt_answers && virt=0
        (( docker + virt == 0 )) && return 0
        if (( $(date +%s) >= until )); then
            log "$( (( docker )) && echo Docker)$( (( docker && virt )) && echo ' and ')$( (( virt )) && echo libvirt) did not answer within ${UB_RECOVER_WAIT} s"
            return 0
        fi
        (( told++ )) || log "Waiting for $( (( docker )) && echo Docker)$( (( docker && virt )) && echo ' and ')$( (( virt )) && echo libvirt) to answer (at most ${UB_RECOVER_WAIT} s) ..."
        sleep "$UB_RECOVER_LOOK"
    done
}
# Does a --recover hold the lock? (its note: holder backup, mode recover - and its pid runs backup.sh)
recover_holds() { ub_holder_read; [[ "$HOLDER_KIND" == "backup" && "$HOLDER_MODE" == "recover" ]]; }

##############################################################################
# Sleeping pools (2.28)
##############################################################################
# [general] asleep_pools = skip (lib/common.sh section 13): decided once, when the run makes its plan - right
# after the inventory and the drift, before anything is shut down, paused or stopped, so a VM whose disks sleep
# isn't held for nothing and an app whose backed-up data all sleeps keeps running (if it ran and wrote there, the
# pool wouldn't sleep). Never touches a disk to find out: disks.ini only (ub_asleep_load). Packages and dumps
# are unaffected - they go to the backup place, whose pool is woken as always.
declare -A ASLEEP_BASE=() ASLEEP_DS=() ASLEEP_SHARE=() NOT_LOOKED=()
asleep_src() { # asleep_src <Kopia source name>  - skipped this run, why asleep (only said so when Kopia runs at all)
    ST_ASLEEP_SRC+=( "$1" )
    [[ "$KOPIA_OK" == "yes" && "$SKIPK" != "1" ]] || return 0
    ST_KOPIA_SKIPPED+=( "$1" ); ST_KOPIA_SKIPPED_WHY[$1]="asleep"
    log "  Kopia: $1 - skipped, its data sleeps (asleep_pools = skip)"
}
asleep_base_of() { local b; for b in "${INV_BASES[@]}"; do [[ "${INV_BASE_PATH[$b]}" == "$1" ]] && { printf '%s' "$b"; return 0; }; done; return 1; }
asleep_plan() {
    [[ "$ASLEEP_POOLS" == "skip" ]] || return 0
    ST_ASLEEP_ON="yes"
    ub_asleep_load
    local b ds p s t n f sh why line
    local -a keep_zfs=() keep_btrfs=() kk=() ki=() km=()
    local -A keep=() cand=()
    log "Sleeping pools (asleep_pools = skip):"
    # never left out: the backup place's pool - its packages are the point - and the pool of the engine's data folder
    while IFS= read -r b; do [[ -n "$b" ]] && keep[$b]="the backup place ($DUMPS_SHARE)"; done < <(share_bases "$DUMPS_SHARE")
    while IFS= read -r b; do [[ -n "$b" && -z "${keep[$b]:-}" ]] && keep[$b]="the engine's data folder"; done < <(ub_path_bases "$UB_DATA")
    for ds in "${PLAN_ZFS[@]}"; do cand[${ds%%/*}]="ZFS"; done
    for p in "${PLAN_BTRFS[@]}"; do b="$(asleep_base_of "$p")" && cand[$b]="btrfs"; done
    for b in $(printf '%s\n' "${!cand[@]}" | LC_ALL=C sort); do
        ub_base_sleeps "$b" || continue
        if [[ -n "${keep[$b]:-}" ]]; then
            log "  ${cand[$b]} $b: asleep, but ${keep[$b]} lies there - woken as always (the packages are the point)"
            ST_ASLEEP_WOKEN+=( "$b" ); continue
        fi
        ASLEEP_BASE[$b]=1; ST_ASLEEP_POOLS+=( "$b" )
        log "  ${cand[$b]} $b: asleep - left out this run (asleep_pools = skip)"
    done
    # every pool and disk asleep now that the run doesn't wake: the retention looks neither at its ZFS snapshots nor
    # at its btrfs snapshot folder
    for b in $( { printf '%s\n' "${INV_BASES[@]}"; for ds in "${!ZDS_MP[@]}"; do printf '%s\n' "${ds%%/*}"; done; } | LC_ALL=C sort -u); do
        [[ -z "${keep[$b]:-}" ]] && ub_base_sleeps "$b" && NOT_LOOKED[$b]=1
    done
    for ds in "${PLAN_ZFS[@]}"; do if [[ -n "${ASLEEP_BASE[${ds%%/*}]:-}" ]]; then ASLEEP_DS[$ds]=1; else keep_zfs+=( "$ds" ); fi; done
    PLAN_ZFS=( "${keep_zfs[@]}" )
    for p in "${PLAN_BTRFS[@]}"; do b="$(asleep_base_of "$p")"; [[ -n "$b" && -n "${ASLEEP_BASE[$b]:-}" ]] || keep_btrfs+=( "$p" ); done
    PLAN_BTRFS=( "${keep_btrfs[@]}" )
    # the shares with a part there - and a share Kopia would read live from a disk that sleeps: not woken either
    while IFS= read -r s; do
        [[ -n "$s" ]] && inv_has_share "$s" && [[ "$(share_mode "$s")" != "off" ]] || continue
        why=""
        while IFS= read -r b; do [[ -n "$b" && -n "${ASLEEP_BASE[$b]:-}" ]] && { why="$b"; break; }; done <<<"$(share_bases "$s")"
        if [[ -z "$why" && "$(share_method "$s")" == "live" ]] && in_list "$s" "${PLAN_MOUNT[@]}"; then
            while IFS= read -r b; do [[ -n "$b" && -z "${keep[$b]:-}" ]] && ub_base_sleeps "$b" && { why="$b"; break; }; done <<<"$(share_bases "$s")"
            [[ -n "$why" ]] && log "  share $s (read live): $why asleep - left out of Kopia this run"
        fi
        [[ -n "$why" ]] || continue
        ASLEEP_SHARE[$s]=1; ST_ASLEEP_SHARES+=( "$s" )
    done < <(cfg_names share | LC_ALL=C sort)
    (( ${#ST_ASLEEP_SHARES[@]} )) && log "  Shares left out (a part asleep): ${ST_ASLEEP_SHARES[*]}"
    # their Kopia sources, and the apps' and VMs' own sources with a part in them: skipped, why asleep (never half
    # a source - Kopia would take what is missing for deleted)
    for s in "${PLAN_KOPIA[@]}"; do if [[ -n "${ASLEEP_SHARE[$s]:-}" ]]; then asleep_src "$s"; else kk+=( "$s" ); fi; done
    PLAN_KOPIA=( "${kk[@]}" )
    for line in "${PLAN_KITEMS[@]}"; do
        IFS='|' read -r t n f <<<"$line"; why=""
        while IFS='|' read -r sh _; do [[ -n "$sh" && -n "${ASLEEP_SHARE[$sh]:-}" ]] && { why="$sh"; break; }; done <<<"$(kopia_item_parts "$t" "$n" "-")"
        if [[ -n "$why" ]]; then asleep_src "$t:$n"; else ki+=( "$line" ); fi
    done
    PLAN_KITEMS=( "${ki[@]}" )
    for s in "${PLAN_MOUNT[@]}"; do [[ -n "${ASLEEP_SHARE[$s]:-}" ]] || km+=( "$s" ); done
    PLAN_MOUNT=( "${km[@]}" )
    (( ${#ST_ASLEEP_POOLS[@]} + ${#ST_ASLEEP_SHARES[@]} + ${#ST_ASLEEP_WOKEN[@]} )) || log "  none asleep - everything as planned"
    return 0
}
# asleep_vm <vm>  -> 0 when one of its disks lies on a pool or disk left out this run
asleep_vm() {
    local t b
    (( ${#ASLEEP_BASE[@]} )) || return 1
    while IFS='|' read -r t _ b _; do [[ -n "$t" && -n "$b" && -n "${ASLEEP_BASE[$b]:-}" ]] && return 0; done <<<"${VM_DISKS[$1]:-}"
    return 1
}
# ct_rests <container>  -> 0 when every bind of it into backed-up data lies only on pools and disks left out this run:
# stopping it would change no snapshot (it keeps running, listed in status.json asleep.containers)
ct_rests() {
    local src s b any=0 n
    (( ${#ASLEEP_BASE[@]} )) || return 1
    while IFS='|' read -r src _ _; do
        [[ -n "$src" ]] || continue
        s="$(src_share "$src")"
        [[ -n "$s" && "$(share_mode "$s")" != "off" ]] || continue
        n=0
        while IFS= read -r b; do
            [[ -n "$b" ]] || continue
            n=$((n+1)); [[ -n "${ASLEEP_BASE[$b]:-}" ]] || return 1
        done <<<"$(ub_path_bases "$src")"
        (( n > 0 )) || return 1
        any=1
    done <<<"${CT_BINDS[$1]:-}"
    (( any ))
}

# 2.69: the pools with almost no room that hold the data of apps this run stops - said before they stop (pool_low_space,
# one warning per pool). They are still stopped as set up: a ZFS snapshot takes next to no room, and a consistent
# snapshot of an app is worth most when its pool is about to fill; what the room puts at risk is the app's start
# afterwards and the blocks the new snapshot keeps - the user's to free, so it is said, not decided here
pool_room_check() {
    local n b
    local -A apps=()
    for n in "${T_APP[@]}" "${T_DB[@]}" "${T_NET[@]}"; do
        [[ -n "$n" ]] || continue
        while IFS= read -r b; do [[ -n "$b" ]] && apps[$b]+="${apps[$b]:+, }$n"; done <<<"$(ct_low_pools "$n")"
    done
    for b in $(printf '%s\n' "${!apps[@]}" | LC_ALL=C sort); do
        pool_low "$b" || continue
        warn_code pool_low_space "Pool '$b' has only $(human "$PL_FREE") free of $(human "$PL_SIZE") (below $(human "$PL_FLOOR")) - ${apps[$b]} keep their data there and are stopped and started for the snapshot as set up, but may not start cleanly with so little room, and the new snapshot keeps what they overwrite afterwards: make room on '$b'" \
            pool="$b" free="$PL_FREE" size="$PL_SIZE" floor="$PL_FLOOR" free_h="$(human_sp "$PL_FREE")" size_h="$(human_sp "$PL_SIZE")" \
            floor_h="$(human_sp "$PL_FLOOR")" apps="${apps[$b]}" n="$(tr ',' '\n' <<<"${apps[$b]}" | grep -c .)"
    done
    return 0
}

##############################################################################
# Partners (2.27)
##############################################################################
# After the snapshots and the apps' restart, before Kopia (a LAN or tunnel transfer ends in minutes to hours, Kopia's
# first upload can take days): per partner ([partner "<id>"], lib/common.sh section 12) its units - the backup place
# first, then the shares and VMs by size, the smallest first (ZFS's referenced: what zfs send moves - a sparse vdisk's
# holes are no blocks) - each as this run's snapshot of its dataset through the partner's door:
#   resume <unit>  a transfer an array stop or a lost link interrupted continues first (zfs send -t <token>)
#   list <unit>    what the partner holds -> the newest uso-backup-* both have = the base of an incremental send; gone
#                  here (the retention) but bookmarked (<dataset>#uso-partner-<id>, the last one sent) -> -i <bookmark>;
#                  none -> a full send (said in the status: "from": null)
#   recv <unit> <snap> [<from>]  zfs send -L -c [-i <base>] <ds>@<snap> | mbuffer | pv | ssh - the door answers one JSON
#                  line on stderr before it reads (admitted or refused: refused_window, refused_quota, refused_asleep,
#                  need_full -> a full send, ...) and one after (bytes, seconds, or recv_failed)
# then the bookmark moves to the snapshot sent. A partner that doesn't answer: every unit skipped (unreachable) and,
# like everything that did not arrive, a warning in this very run (2.46, partner_missed - no nights of grace). The array stop
# ends the phase like Kopia's (partner_stop: SIGTERM to the pipe; the receiver's zfs recv -s keeps a resume token):
# partner.interrupted, the rest skipped - never failed. (zfs send never gets -s: that is --skip-missing, only with -R.)
PARTNER_ORDER=()            # "id|unit|dataset|bytes" - the phase's transfers in their order (partner_plan)
PARTNER_CANT=()             # "id|unit|why" - ticked, but it can't travel (skipped at once, said in the plan)
# 2.74: a child dataset of a unit that goes but is no unit of its own stays here - ST_PARTNER_LEFT "id|unit|dataset|folder|owner"
# (status.json partner.left_out), said in the plan and the log per child; the unit itself goes (up to 2.73: children).
# 2.76: owner (vm:<n> | app:<n>) - a VM's or app's own dataset whose unit doesn't take it to this partner stays here too
declare -A PARTNER_DOWN=()  # id -> why every unit of that partner is skipped (unreachable, no_key, ...)

partner_plan() {
    local id u ds b lc lf lo
    local -a units=() rows=()
    local -A seen=()
    PARTNER_ORDER=(); PARTNER_CANT=(); ST_PARTNER_IDS=(); ST_PARTNER_PLAN=(); ST_PARTNER_LEFT=()
    while IFS= read -r id; do
        [[ -n "$id" ]] || continue
        mapfile -t units < <(partner_units "$id")
        (( ${#units[@]} )) || continue
        ST_PARTNER_IDS+=( "$id|$(partner_name "$id")" )
        rows=(); seen=()
        for u in "${units[@]}"; do
            if ! partner_unit_dataset "$u" "$id"; then PARTNER_CANT+=( "$id|$u|$PU_WHY" ); continue; fi
            ds="$PU_DS"
            # the backup place's share ticked as a share too: one transfer (place)
            [[ -n "${seen[$ds]:-}" ]] && { log "  Partner $(partner_name "$id"): $u is the same dataset as ${seen[$ds]} - sent once"; continue; }
            seen[$ds]="$u"
            # its pool left out this run (2.28, asleep_pools = skip): no snapshot to send - skipped, why asleep
            if [[ -n "${ASLEEP_DS[$ds]:-}" ]]; then PARTNER_CANT+=( "$id|$u|asleep" ); continue; fi
            if ! in_list "$ds" "${PLAN_ZFS[@]}"; then PARTNER_CANT+=( "$id|$u|not_snapshotted" ); continue; fi
            while IFS='|' read -r lc lf lo; do if [[ -n "$lc" ]]; then ST_PARTNER_LEFT+=( "$id|$u|$lc|$lf|$lo" ); fi; done <<<"$PU_LEFT"
            b="${ZDS_REF[$ds]:-}"
            if [[ "$u" == place ]]; then rows+=( "0|$u|$ds|${b:-0}" )
            elif is_uint "$b"; then rows+=( "1|$u|$ds|$b" )
            else rows+=( "2|$u|$ds|0" ); fi
        done
        while IFS='|' read -r _ u ds b; do
            [[ -n "$u" ]] || continue
            PARTNER_ORDER+=( "$id|$u|$ds|$b" ); ST_PARTNER_PLAN+=( "$id|$u" )
        done < <(printf '%s\n' "${rows[@]}" | sed '/^$/d' | sort -t'|' -s -k1,1n -k4,4n)
        for u in "${PARTNER_CANT[@]}"; do [[ "$u" == "$id|"* ]] && ST_PARTNER_PLAN+=( "$(cut -d'|' -f1,2 <<<"$u")" ); done
    done < <(partner_ids)
    # a partner named for a unit without a [partner] section: the partnership ended, the setup not applied since
    local k pk
    for k in "${!CFG[@]}"; do
        [[ "$k" =~ ^(share\|.+\|partner|vm\|.+\|partner|app\|.+\|partner|general\|partner_place)$ ]] || continue
        while IFS= read -r pk; do
            [[ -n "$pk" ]] && ! cfg_has "partner|$pk" && log "  Partner $pk is named in $(sec_display "${k%|*}") but has no [partner \"$pk\"] section - left out (Set up... > Apply)"
        done < <(cfg_list "$k")
    done
    return 0
}

partner_done_has() { # partner_done_has <id|unit>  -> 0 when that unit has a result in this run
    local l
    for l in "${ST_PARTNER_DONE[@]}" "${ST_PARTNER_SKIPPED[@]}" "${ST_PARTNER_FAILED[@]}"; do [[ "$l" == "$1|"* ]] && return 0; done
    for l in "${ST_PARTNER_INTERRUPTED_ALL[@]}"; do [[ "$l" == "$1" ]] && return 0; done
    [[ "$ST_PARTNER_INTERRUPTED" == "$1" ]]
}
partner_skip() { # partner_skip <id> <unit> <why>
    ST_PARTNER_SKIPPED+=( "$1|$2|$3" ); status_write
}
partner_fail() { # partner_fail <id> <unit> <why> <text>
    ST_PARTNER_FAILED+=( "$1|$2|$3" )
    warn "Partner $(partner_name "$1"): $2 not sent - $4"
    status_write
}
partner_mbit() { awk -v b="${1:-0}" -v s="${2:-0}" 'BEGIN { if (s < 1) s = 1; printf "%.1f", b * 8 / s / 1000000 }'; }

# partner_bookmark <id> <dataset> <snap>: the bookmark of the last snapshot sent to that partner moves to <snap>
partner_bookmark() {
    local bm="$2#$UB_PARTNER_BOOKMARK$1"
    zfs destroy "$bm" >/dev/null 2>&1
    zfs bookmark "$2@$3" "$bm" 2>>"$LOG_FILE" \
        || warn "Partner $(partner_name "$1"): bookmark $bm could not be set - the next transfer of $2 needs a snapshot both still have"
    partner_state_set partner-sent.json '.[$v.id][$v.unit] = {snap: $v.snap, dataset: $v.ds, time: $v.time}' \
        "$(jq -nc --arg id "$1" --arg unit "$PS_UNIT" --arg snap "$3" --arg ds "$2" --argjson time "$(date +%s)" \
            '{id: $id, unit: $unit, snap: $snap, ds: $ds, time: $time}')"
}

# The transfers going on (2.73): one slot each - up to UB_PARTNER_STREAMS datasets of one partner at once, each its own
# ordered stream (zfs send | mbuffer | pv | ssh into the door's zfs recv -s), its own resume token, its own chain; never
# one dataset twice. The pipes run in the background, everything else - the asks, the results, the bookmarks, the
# warnings, status.json - in the run itself, one after another.
declare -A SL_PID=() SL_ID=() SL_U=() SL_DS=() SL_SNAP=() SL_FROM=() SL_TOKEN=() SL_T0=() SL_EST=() SL_BYTES=() SL_STAGE=() SL_ADM=()

# slot_free: the lowest slot number not in use
slot_free() { local k=0; while [[ -n "${SL_PID[$k]:-}${SL_U[$k]:-}" ]]; do k=$(( k + 1 )); done; printf '%s' "$k"; }
slots_n() { printf '%s' "${#SL_PID[@]}"; }

# partner_cur_set: status.json's current (the transfer going on longest - what an office before 1.72 shows) and streams
partner_cur_set() {
    local k first=""
    ST_PARTNER_STREAMS=()
    for k in $(for k in "${!SL_PID[@]}"; do printf '%s %s\n' "${SL_T0[$k]}" "$k"; done | sort -n -k1,1 -k2,2 | cut -d' ' -f2); do
        [[ -n "$first" ]] || first="$k"
        ST_PARTNER_STREAMS+=( "${SL_ID[$k]}|${SL_U[$k]}|${SL_T0[$k]}|${SL_BYTES[$k]:-0}|${SL_EST[$k]:-0}" )
    done
    if [[ -n "$first" ]]; then
        ST_PARTNER_CUR="${SL_ID[$first]}|${SL_U[$first]}"; ST_PARTNER_CUR_T="${SL_T0[$first]}"
        ST_PARTNER_CUR_BYTES="${SL_BYTES[$first]:-0}"; ST_PARTNER_CUR_EST="${SL_EST[$first]:-0}"
    else
        ST_PARTNER_CUR=""; ST_PARTNER_CUR_BYTES=0; ST_PARTNER_CUR_EST=0
    fi
}

# pv_count <file>: what pv counted so far - its last line's last field (a pv told something with -R may add the time)
pv_count() { tail -n 1 "$1" 2>/dev/null | awk '{ print $NF }' | tr -dc '0-9'; }

# partner_rate_share <id>: the user's rate (rate_mbit) is the total of every transfer to that partner - each running
# pv gets its share again (pv -R) whenever their number changed; prints what a new transfer gets (bytes/s, 0 = none)
partner_rate_each() { # partner_rate_each <id> <transfers>
    local rate
    rate="$(cfg "partner|$1|rate_mbit" 0)"; is_uint "$rate" || rate=0
    (( rate > 0 )) || { printf 0; return 0; }
    printf '%s' $(( rate * 125000 / ( $2 > 0 ? $2 : 1 ) ))
}
PT_SHARE_N=0                # how many transfers the running pvs' shares are for
partner_rate_share() { # partner_rate_share <id>
    local k p each n
    n="$(slots_n)"
    (( n > 0 && n != PT_SHARE_N )) || return 0
    PT_SHARE_N="$n"
    each="$(partner_rate_each "$1" "$n")"
    (( each > 0 )) && command -v pv >/dev/null 2>&1 || return 0
    for k in "${!SL_PID[@]}"; do
        p="$(head -c 16 "$PARTNER_TMP/s$k/pvpid" 2>/dev/null | tr -dc '0-9')"
        # 2.76: still pv (its pid not taken over by another process while the slot waits for ssh and the door)
        [[ -n "$p" ]] && kill -0 "$p" 2>/dev/null && [[ "$(head -c 16 "/proc/$p/comm" 2>/dev/null)" == pv* ]] && pv -R "$p" -n -b -i "$UB_PARTNER_PV_EVERY" -L "$each" </dev/null >/dev/null 2>&1
    done
    return 0
}

# ps_start <slot> <id> <unit> <dataset> <snap> <from> <base> [<resume token>]  - a transfer through the door, started
#   <from>: the base snapshot's name for the door ("" = full), <base>: what zfs send -i gets (<ds>@<from> or the bookmark)
ps_start() {
    local k="$1" id="$2" u="$3" ds="$4" snap="$5" from="$6" base="$7" token="${8:-}" name words each t0 b d
    local -a send=()
    name="$(partner_name "$id")"
    d="$PARTNER_TMP/s$k"; mkdir -p "$d"
    : >"$d/door"; : >"$d/pv"; rm -f "$d/rc" "$d/pvpid"
    if [[ -n "$token" ]]; then
        send=( zfs send -t "$token" ); words="recv $u $snap -t"
    else
        send=( zfs send -L -c ); [[ -n "$base" ]] && send+=( -i "$base" ); send+=( "$ds@$snap" )
        words="recv $u $snap${from:+ $from}"
    fi
    each="$(partner_rate_each "$id" $(( $(slots_n) + 1 )))"
    (( $(slots_n) == 0 )) && PT_SHARE_N=1            # alone: its share is the whole rate (the others: partner_rate_share)
    if (( each > 0 )) && ! command -v pv >/dev/null 2>&1; then log "    (pv is missing - the rate of $(cfg "partner|$id|rate_mbit" 0) Mbit/s can't be kept)"; fi
    if [[ -n "$token" ]]; then log "  $u -> $name: continuing the interrupted transfer of $snap"
    elif [[ "$base" == *"#"* ]]; then log "  $u -> $name: $snap, incremental from $from (by the bookmark ${base#*#} - $from is gone here)"
    elif [[ -n "$from" ]]; then log "  $u -> $name: $snap, incremental from $from"
    else log "  $u -> $name: $snap, whole (the partner has no snapshot in common with it)"; fi
    # 2.42: what the stream will be (zfs send -nP's size line), for the office's «1.2 of ~4.3 GB» - not for a resumed one
    SL_EST[$k]=0
    if [[ -z "$token" ]]; then
        b="$(zfs send -nP -L -c ${base:+-i "$base"} "$ds@$snap" 2>/dev/null | awk '$1 == "size" { print $2; exit }')"
        if is_uint "$b" && (( b > 0 )); then SL_EST[$k]="$b"; log "    about $(human "$b") to send"; fi
    fi
    t0="$(date +%s)"
    SL_ID[$k]="$id"; SL_U[$k]="$u"; SL_DS[$k]="$ds"; SL_SNAP[$k]="$snap"; SL_FROM[$k]="$from"; SL_TOKEN[$k]="$token"
    SL_T0[$k]="$t0"; SL_BYTES[$k]=0; SL_ADM[$k]=""
    # four stages, always: zfs send | mbuffer (or cat) | pv (or cat) | ssh - their exit codes into rc; the subshell in
    # the background (the run looks at the array and the other transfers meanwhile) - partner_stop ends its whole tree
    (
        "${send[@]}" 2>>"$LOG_FILE" \
          | { if command -v mbuffer >/dev/null 2>&1; then exec mbuffer -q -s 128k -m 256M 2>>"$LOG_FILE"; else exec cat; fi; } \
          | { if command -v pv >/dev/null 2>&1; then
                printf '%s\n' "$BASHPID" >"$d/pvpid"
                if (( each > 0 )); then exec pv -n -b -i "$UB_PARTNER_PV_EVERY" -L "$each" 2>"$d/pv"; else exec pv -n -b -i "$UB_PARTNER_PV_EVERY" 2>"$d/pv"; fi
              else exec cat; fi; } \
          | { partner_ssh_cmd "$id"; exec "${PSSH[@]}" "$words" >/dev/null 2>"$d/door"; }
        printf '%s\n' "${PIPESTATUS[@]}" >"$d/rc"
    ) 9>&- </dev/null &
    SL_PID[$k]=$!
    partner_cur_set; status_write
}

# ps_poll: every transfer's count so far (pv's), status.json every 10 s (2.42: the office's rate). A door that refused
# (its first line, before it reads a byte: busy, a quota, need_full ...) - that pipe ends at once (2.73), never left
# to run until zfs send notices
PS_LAST_WRITE=0
ps_poll() {
    local k b ok
    for k in "${!SL_PID[@]}"; do
        b="$(pv_count "$PARTNER_TMP/s$k/pv")"
        is_uint "$b" && SL_BYTES[$k]="$b"
        [[ -z "${SL_ADM[$k]:-}" && -s "$PARTNER_TMP/s$k/door" ]] || continue
        ok="$(jq -R -r 'fromjson? | select(type == "object" and has("ok")) | .ok' "$PARTNER_TMP/s$k/door" 2>/dev/null | head -n 1)"
        if [[ "$ok" == "true" ]]; then SL_ADM[$k]=1
        elif [[ "$ok" == "false" ]]; then
            SL_ADM[$k]=0
            # shellcheck disable=SC2046  # a list of pids
            kill -0 "${SL_PID[$k]}" 2>/dev/null && kill -TERM $(partner_tree "${SL_PID[$k]}") 2>/dev/null
        fi
    done
    if (( $(date +%s) - PS_LAST_WRITE >= 10 )); then PS_LAST_WRITE="$(date +%s)"; partner_cur_set; status_write; fi
}

# ps_end <slot>: the transfer in it ended (its pipe reaped) - what came of it
# returns 0 sent (PS_BYTES, PS_SECS), 1 failed (said), 2 need_full, 3 refused (skipped, PS_WHY), 4 unreachable,
# 5 busy (2.73: the door takes no more at once - the caller sends it again later)
PS_BYTES=0; PS_SECS=0; PS_WHY=""; PS_UNIT=""
ps_end() {
    local k="$1" id u name d d1 d2 ok1 ok2 why detail send_rc ssh_rc b
    local -a rcs=() door=()
    id="${SL_ID[$k]}"; u="${SL_U[$k]}"; d="$PARTNER_TMP/s$k"
    name="$(partner_name "$id")"
    PS_BYTES=0; PS_WHY=""; PS_UNIT="$u"
    PS_SECS=$(( $(date +%s) - SL_T0[$k] ))
    mapfile -t rcs <"$d/rc" 2>/dev/null
    send_rc="${rcs[0]:-1}"; ssh_rc="${rcs[3]:-255}"
    # the door's two lines (stderr; ssh's own words may stand between them)
    mapfile -t door < <(jq -R -c 'fromjson? | select(type == "object" and has("ok"))' "$d/door" 2>/dev/null)
    d1="${door[0]:-}"; d2="${door[1]:-}"
    ok1="$(jq -r '.ok' <<<"$d1" 2>/dev/null)"; ok2="$(jq -r '.ok' <<<"$d2" 2>/dev/null)"
    b="$(pv_count "$d/pv")"
    if is_uint "$b"; then PS_BYTES="$b"
    else b="$(jq -r '.bytes // empty' <<<"$d2" 2>/dev/null)"; is_uint "$b" && PS_BYTES="$b"; fi
    SL_BYTES[$k]="$PS_BYTES"
    # it ended badly just as the array stop began: interrupted by the stop, not failed
    if [[ "$ok2" != "true" ]] && array_stopping; then partner_interrupted "$id|$u"; array_stop_check "the transfer of $u ended"; fi
    if [[ -z "$d1" ]]; then
        if (( ssh_rc == 255 )); then
            [[ -s "$d/door" ]] && log "    ($name: $(head -c 300 "$d/door" | tr '\n' ' ' | tr -d '\r'))"
            return 4
        fi
        PS_WHY="no_answer"; partner_fail "$id" "$u" no_answer "the door gave no answer (ssh exit code $ssh_rc)"; return 1
    fi
    if [[ "$ok1" != "true" ]]; then
        why="$(partner_why_ok "$(jq -r '.why // ""' <<<"$d1" 2>/dev/null)")"; PS_WHY="$why"
        case "$why" in
            need_full) log "    $name has no base for it (need_full) - sending it whole"; return 2 ;;
            # 2.78: a full quota - what of it the door's earlier copies hold (a door since 2026-10-28 says aside_bytes)
            refused_quota) b="$(jq -r '.aside_bytes // empty' <<<"$d1" 2>/dev/null)"; is_uint "$b" && PARTNER_ASIDE[$id]="$b"
                log "    $name: not now ($why)"; return 3 ;;
            refused_window|refused_asleep) log "    $name: not now ($why)"; return 3 ;;
            # a door up to 1.59 keeps only what its Team Lead agreed to (2.42: skipped, not failed - the mutual watch
            # asks it to keep this unit too; an update there takes any unit)
            unit_not_agreed) PS_WHY="not_agreed"; log "    $name runs an older office and hasn't agreed to keep $u - its Team Lead decides (update it: then every unit goes)"; return 3 ;;
            # 2.45: an office before 1.60.1 knows no app units - skipped, said once a run (partner_refused)
            bad_unit) if [[ "$u" == app:* ]]; then PS_WHY="old_partner"; log "    $name runs an older office that takes no app on its own ($u) - update it there"; return 3; fi ;;
            # 2.73: the door takes no more at once while another transfer of this run goes on - this one again after it
            busy) if (( $(slots_n) > 1 )); then log "    $name takes no more at once (busy) - $u goes when another transfer is through"; return 5; fi ;;
        esac
        partner_fail "$id" "$u" "$why" "$name refused it ($why)"; return 1
    fi
    if [[ "$ok2" == "true" && "$send_rc" == 0 ]]; then
        if (( PT_MANY )); then log "    $u sent: $(human "$PS_BYTES") in $(dur_h "$PS_SECS") - $(partner_mbit "$PS_BYTES" "$PS_SECS") Mbit/s"
        else log "    sent: $(human "$PS_BYTES") in $(dur_h "$PS_SECS") - $(partner_mbit "$PS_BYTES" "$PS_SECS") Mbit/s"; fi
        return 0
    fi
    if [[ "$ok2" == "false" ]]; then
        why="$(partner_why_ok "$(jq -r '.why // "recv_failed"' <<<"$d2" 2>/dev/null)")"
        detail="$(jq -r '.detail // ""' <<<"$d2" 2>/dev/null | tr -d '\000-\037' | head -c 300)"
        PS_WHY="$why"; partner_fail "$id" "$u" "$why" "$name could not receive it ($why${detail:+: $detail})"; return 1
    fi
    if [[ "$send_rc" != 0 ]]; then PS_WHY="send_failed"; partner_fail "$id" "$u" send_failed "zfs send ended with exit code $send_rc"; return 1; fi
    PS_WHY="link_lost"
    partner_fail "$id" "$u" link_lost "the link to $name was lost during the transfer (ssh exit code $ssh_rc) - the next run continues it"
    return 1
}

# slot_clear <slot>: the slot free again
slot_clear() {
    local k="$1"
    unset "SL_PID[$k]" "SL_ID[$k]" "SL_U[$k]" "SL_DS[$k]" "SL_SNAP[$k]" "SL_FROM[$k]" "SL_TOKEN[$k]" "SL_T0[$k]" "SL_EST[$k]" "SL_BYTES[$k]" "SL_STAGE[$k]" "SL_ADM[$k]"
    partner_cur_set
}

# pu_begin <slot> <id> <unit> <dataset>: resume what was interrupted, else find the base and send this run's snapshot -
# started in the slot (SL_PID set), or settled at once (done, skipped, the partner unreachable: the slot stays free)
pu_begin() {
    local k="$1" id="$2" u="$3" ds="$4" name token toname snap r
    name="$(partner_name "$id")"
    PS_UNIT="$u"
    # 1. an interrupted transfer first: its receiver kept a token (zfs recv -s)
    partner_ask "$id" resume "$u"; r=$?
    (( r == 255 )) && { PARTNER_DOWN[$id]="unreachable"; return 0; }
    token="$(jq -r '.token // empty' <<<"$PA_JSON" 2>/dev/null)"
    if [[ -n "$token" ]]; then
        if [[ ! "$token" =~ ^[0-9a-zA-Z-]{1,8192}$ ]]; then log "    $name: a resume token not in the shape of one - not used"
        else
            toname="$(zfs send -nv -t "$token" 2>&1 | sed -n 's/^[[:space:]]*toname = //p' | head -n 1)"
            snap="${toname#*@}"
            if [[ "${toname%%@*}" == "$ds" && "$snap" =~ $UB_PARTNER_SNAP_RE ]]; then
                SL_STAGE[$k]="resume"
                ps_start "$k" "$id" "$u" "$ds" "$snap" "" "" "$token"
                return 0
            fi
            log "    $name holds an interrupted transfer of ${toname:-a snapshot gone here} - it can't be continued; sending anew"
        fi
    fi
    pu_send "$k" "$id" "$u" "$ds"
}

# pu_send <slot> <id> <unit> <dataset>: what the partner holds of it - the base of an incremental send - and this run's
# snapshot on its way (whole, should the partner have no base for it)
pu_send() {
    local k="$1" id="$2" u="$3" ds="$4" name common="" from="" base="" r recorded s
    local -a ours=()
    local -A have=()
    name="$(partner_name "$id")"
    PS_UNIT="$u"
    partner_ask "$id" list "$u"; r=$?
    (( r == 255 )) && { PARTNER_DOWN[$id]="unreachable"; return 0; }
    # a list it won't give (a unit it never received, whatever its door says then): nothing in common - the recv below
    # gets the door's real answer (a refusal comes before a byte is read)
    if [[ "$(jq -r '.ok' <<<"$PA_JSON" 2>/dev/null)" == "false" ]]; then
        log "    $name: no list of $u ($(partner_why_ok "$(jq -r '.why // ""' <<<"$PA_JSON" 2>/dev/null)")) - nothing in common"
        PA_JSON=""
    fi
    while IFS= read -r s; do [[ "$s" =~ $UB_PARTNER_SNAP_RE ]] && have[$s]=1; done < <(jq -r '(.snaps // .snapshots // [])[]
        | (if type == "object" then (.name // "") else . end) | tostring | sub("^.*@"; "")' <<<"$PA_JSON" 2>/dev/null)
    if [[ -n "${have[$SNAP_NAME]:-}" ]]; then
        log "  $u -> $name: $SNAP_NAME is there already"
        ST_PARTNER_DONE+=( "$id|$u|$SNAP_NAME|$SNAP_NAME|0|0|0" ); partner_bookmark "$id" "$ds" "$SNAP_NAME"; status_write; return 0
    fi
    mapfile -t ours < <(zfs list -H -o name -t snapshot -s createtxg -d 1 "$ds" 2>/dev/null | sed -n "s|^$(printf '%s' "$ds" | sed 's/[]\/$*.^[]/\\&/g')@||p")
    for s in "${ours[@]}"; do
        [[ "$s" =~ $UB_PARTNER_SNAP_RE && "$s" != "$SNAP_NAME" && -n "${have[$s]:-}" ]] && common="$s"
    done
    if [[ -n "$common" ]]; then from="$common"; base="$ds@$common"
    else
        # gone here (the retention), but the partner still has the last one sent and its bookmark is here
        recorded="$(partner_state_get partner-sent.json ".[\"$id\"][\"$u\"].snap")"
        if [[ -n "$recorded" && -n "${have[$recorded]:-}" ]] && zfs list -H -o name -t bookmark "$ds#$UB_PARTNER_BOOKMARK$id" >/dev/null 2>&1; then
            from="$recorded"; base="$ds#$UB_PARTNER_BOOKMARK$id"
        fi
    fi
    SL_STAGE[$k]="send"
    ps_start "$k" "$id" "$u" "$ds" "$SNAP_NAME" "$from" "$base"
}

# pu_after <slot> <rc>: a transfer in the slot ended with <rc> (ps_end) - the unit's next step: the resumed one done, now
# this run's snapshot; need_full -> the same whole; else its result. Returns 5 when the unit goes back into the queue
pu_after() {
    local k="$1" r="$2" id u ds snap from stage name
    id="${SL_ID[$k]}"; u="${SL_U[$k]}"; ds="${SL_DS[$k]}"; snap="${SL_SNAP[$k]}"; from="${SL_FROM[$k]}"; stage="${SL_STAGE[$k]}"
    name="$(partner_name "$id")"
    unset "SL_PID[$k]"
    PS_UNIT="$u"
    if [[ "$stage" == "resume" ]]; then
        case "$r" in
            0) ST_PARTNER_DONE+=( "$id|$u|$snap||$PS_BYTES|$PS_SECS|1" ); partner_bookmark "$id" "$ds" "$snap"; status_write ;;
            3) partner_skip "$id" "$u" "$PS_WHY"; partner_refused "$id" "$PS_WHY"; slot_clear "$k"; return 0 ;;
            4) PARTNER_DOWN[$id]="unreachable"; slot_clear "$k"; return 0 ;;
            5) slot_clear "$k"; return 5 ;;
            *) slot_clear "$k"; return 0 ;;
        esac
        if [[ "$snap" == "$SNAP_NAME" ]]; then slot_clear "$k"; return 0; fi
        pu_send "$k" "$id" "$u" "$ds"
        [[ -n "${SL_PID[$k]:-}" ]] || slot_clear "$k"
        return 0
    fi
    if (( r == 2 )) && [[ "$stage" == "send" && -n "$from" ]]; then
        SL_STAGE[$k]="whole"
        ps_start "$k" "$id" "$u" "$ds" "$SNAP_NAME" "" ""
        return 0
    fi
    case "$r" in
        0) ST_PARTNER_DONE+=( "$id|$u|$SNAP_NAME|$from|$PS_BYTES|$PS_SECS|0" ); partner_bookmark "$id" "$ds" "$SNAP_NAME"; status_write ;;
        2) partner_fail "$id" "$u" need_full "$name asks for a full transfer, and a full one was refused too" ;;
        3) partner_skip "$id" "$u" "$PS_WHY"; partner_refused "$id" "$PS_WHY" ;;
        4) PARTNER_DOWN[$id]="unreachable" ;;
        5) slot_clear "$k"; return 5 ;;
    esac
    slot_clear "$k"
    return 0
}

# partner_interrupted <id|unit>: a transfer an array stop (or a stop by hand) ended - the next run continues it
partner_interrupted() {
    local x
    for x in "${ST_PARTNER_INTERRUPTED_ALL[@]}"; do [[ "$x" == "$1" ]] && return 0; done
    ST_PARTNER_INTERRUPTED_ALL+=( "$1" )
    [[ -n "$ST_PARTNER_INTERRUPTED" ]] || ST_PARTNER_INTERRUPTED="$1"
}

# --- How many at once (2.73) ----------------------------------------------------
# One stream over a long or lossy way (a tunnel across the internet: one TCP connection, ssh's own window) moves far
# less than the link can; datasets sent side by side fill it. But a slow link is full with one: a second only shares
# it. So the run starts with one, measures the total rate (every byte of every transfer to that partner - a unit that
# ends and the next that starts change nothing - over UB_PARTNER_WINDOW s, after UB_PARTNER_WARM s of settling once
# their number changed, as many going as it aims at the whole time) and adds one more only while that total clearly
# rises (more than UB_PARTNER_GAIN %) - up to UB_PARTNER_STREAMS and what the partner's door takes at once (its ping's
# `streams`; a door before 1.72 says nothing: one). A rise that doesn't come: back to one fewer, and no more tries
# this run for that partner. So a link that one stream fills stays at one - as before 2.73.
# 2.77: the settling and the window in milliseconds - up to 2.76 whole seconds read at any fraction of one: the rate's
# divisor up to a second off the time really measured (a 30 s window +-3 %, a short one far more)
PT_TARGET=1; PT_MAX=1; PT_SETTLED=0; PT_PROBE=0; PT_BEST=0; PT_SENT=0; PT_W_AT=0; PT_W_T0=0; PT_W_B=-1; PT_MANY=0
declare -A PARTNER_STREAMS=()   # id -> transfers its door takes at once (its ping)
# partner_ms: the clock in milliseconds (a date without %N: its whole seconds)
partner_ms() { local ns; ns="$(date +%s%N)"; if [[ "$ns" =~ ^[0-9]{13,}$ ]]; then printf '%s' "${ns:0:${#ns}-6}"; else printf '%s' "$(( $(date +%s) * 1000 ))"; fi; }
partner_window_reset() { PT_W_AT=$(( $(partner_ms) + UB_PARTNER_WARM * 1000 )); PT_W_B=-1; }
# partner_total: every byte of this partner's transfers so far - the finished ones and those going on
partner_total() { local k t="$PT_SENT"; for k in "${!SL_PID[@]}"; do t=$(( t + ${SL_BYTES[$k]:-0} )); done; printf '%s' "$t"; }
# partner_adapt <id> <units waiting>: right after the transfers were filled up - 0 when the aim stayed, 1 when it rose
partner_adapt() {
    local id="$1" waiting="$2" now n rate name
    (( PT_SETTLED )) && return 0
    n="$(slots_n)"
    # fewer going than aimed at (the last units, or one settled at once): nothing to compare - anew once they are
    if (( n < PT_TARGET )); then partner_window_reset; return 0; fi
    now="$(partner_ms)"
    (( now >= PT_W_AT )) || return 0
    if (( PT_W_B < 0 )); then PT_W_B="$(partner_total)"; PT_W_T0="$now"; return 0; fi
    (( now - PT_W_T0 >= UB_PARTNER_WINDOW * 1000 )) || return 0
    rate=$(( ( $(partner_total) - PT_W_B ) * 1000 / ( now - PT_W_T0 ) ))
    name="$(partner_name "$id")"
    if (( PT_PROBE )); then
        PT_PROBE=0
        if (( rate * 100 > PT_BEST * (100 + UB_PARTNER_GAIN) )); then
            log "  $name: $n transfers at once move $(partner_mbit "$rate" 1) Mbit/s - $(( (rate - PT_BEST) * 100 / (PT_BEST > 0 ? PT_BEST : 1) )) % more than $(( n - 1 )) - kept"
            PT_BEST="$rate"
        else
            PT_TARGET=$(( n - 1 )); PT_SETTLED=1
            log "  $name: $n transfers at once move $(partner_mbit "$rate" 1) Mbit/s, not clearly more than $(( n - 1 )) ($(partner_mbit "$PT_BEST" 1) Mbit/s) - $PT_TARGET at a time from now on"
            return 0
        fi
    else
        PT_BEST="$rate"
    fi
    if (( waiting > 0 && PT_TARGET < PT_MAX )); then
        PT_TARGET=$(( PT_TARGET + 1 )); PT_PROBE=1; PT_MANY=1
        log "  $name: $(partner_mbit "$rate" 1) Mbit/s with $n at a time - trying $PT_TARGET at once"
        partner_window_reset
        return 1
    fi
    (( PT_TARGET >= PT_MAX )) && PT_SETTLED=1
    partner_window_reset
    return 0
}

# partner_units_run <id>: this partner's transfers in the phase's order - one at a time, or several datasets at once
# while that moves clearly more (above)
partner_units_run() {
    local id="$1" name row b u ds k r w got q
    local -a queue=()
    name="$(partner_name "$id")"
    for row in "${PARTNER_ORDER[@]}"; do
        IFS='|' read -r b u ds _ <<<"$row"
        [[ "$b" == "$id" ]] && queue+=( "$u|$ds" )
    done
    PT_MAX="${PARTNER_STREAMS[$id]:-1}"
    is_uint "$PT_MAX" && (( PT_MAX >= 1 )) || PT_MAX=1
    (( PT_MAX > UB_PARTNER_STREAMS )) && PT_MAX="$UB_PARTNER_STREAMS"
    PT_TARGET=1; PT_PROBE=0; PT_BEST=0; PT_SENT=0; PT_MANY=0
    PT_SETTLED=$(( PT_MAX > 1 ? 0 : 1 ))
    (( PT_MAX > 1 )) && log "  $name takes up to $PT_MAX transfers at once - one first, more while that moves clearly more"
    partner_window_reset
    while :; do
        # transfers started up to the target, in the phase's order
        while (( ${#queue[@]} )) && (( $(slots_n) < PT_TARGET )) && [[ -z "${PARTNER_DOWN[$id]:-}" ]]; do
            IFS='|' read -r u ds <<<"${queue[0]}"; queue=( "${queue[@]:1}" )
            array_stop_check "before sending $u to $name"
            k="$(slot_free)"
            pu_begin "$k" "$id" "$u" "$ds"
            partner_rate_share "$id"
            if [[ -z "${SL_PID[$k]:-}" ]]; then
                slot_clear "$k"
                [[ "${PARTNER_DOWN[$id]:-}" == "unreachable" ]] && ! partner_done_has "$id|$u" && partner_skip "$id" "$u" unreachable
            fi
        done
        if [[ -n "${PARTNER_DOWN[$id]:-}" ]]; then
            for q in "${queue[@]}"; do partner_skip "$id" "${q%%|*}" "${PARTNER_DOWN[$id]}"; done
            queue=()
        fi
        (( $(slots_n) )) || break
        # one more at once? (the start above at once, then)
        partner_adapt "$id" "${#queue[@]}" || continue
        # until one ends, or the time to look at the array again
        array_stop_check "sending to $name"
        sleep "$UB_ARRAY_LOOK" 9>&- &
        w=$!; got=""
        wait -n -p got "${SL_PID[@]}" "$w" 2>/dev/null
        if [[ -z "$got" ]]; then wait "$w" 2>/dev/null                 # neither (a bash without wait -p): the sleep's time
        elif [[ "$got" != "$w" ]]; then kill "$w" 2>/dev/null; wait "$w" 2>/dev/null; fi
        ps_poll
        for k in "${!SL_PID[@]}"; do
            kill -0 "${SL_PID[$k]}" 2>/dev/null && continue
            wait "${SL_PID[$k]}" 2>/dev/null
            ps_end "$k"; r=$?
            (( r == 0 )) && PT_SENT=$(( PT_SENT + PS_BYTES ))
            u="${SL_U[$k]}"; ds="${SL_DS[$k]}"
            pu_after "$k" "$r"; r=$?
            if (( r == 5 )); then
                # the door takes no more at once: this unit first again, no more at a time than go now
                queue=( "$u|$ds" "${queue[@]}" ); PT_TARGET="$(slots_n)"; (( PT_TARGET >= 1 )) || PT_TARGET=1; PT_SETTLED=1; PT_PROBE=0
            elif [[ "${PARTNER_DOWN[$id]:-}" == "unreachable" ]] && ! partner_done_has "$id|$u" && [[ -z "${SL_PID[$k]:-}" ]]; then
                partner_skip "$id" "$u" unreachable
            fi
            partner_rate_share "$id"
        done
        partner_cur_set
    done
    partner_cur_set
}

# partner_why_text <why>: a skip's or failure's reason in words, for the mail (the office's partner.why_short, English)
partner_why_text() {
    case "$1" in
        unreachable) echo "not reachable" ;; array_stopped) echo "its array stopped" ;; ended) echo "partnership ended - apply Mr. Backupsy's setup" ;;
        no_key) echo "key missing - pair anew at the Team Lead" ;; no_ssh) echo "ssh missing" ;; snap_prefix) echo "other snapshot names" ;;
        refused_quota) echo "its quota is full" ;; refused_window) echo "outside its window" ;; refused_asleep) echo "its pool asleep" ;;
        not_agreed) echo "not agreed by an older partner" ;; asleep) echo "pool asleep here" ;; not_dataset) echo "not a dataset of its own" ;;
        split) echo "not one dataset" ;; no_dataset) echo "not on ZFS" ;; children) echo "datasets inside" ;; name) echo "name can't travel" ;;
        not_snapshotted) echo "no snapshot" ;; array_stopping) echo "array stop" ;; signal) echo "run stopped" ;;
        recv_failed) echo "receive failed" ;; send_failed) echo "send failed" ;; link_lost) echo "link lost" ;;
        no_answer) echo "no answer" ;; need_full) echo "no common snapshot" ;; refused) echo "refused" ;;
        *) echo "$1" ;;
    esac
}

# A refusal the partner's user can change: a full quota or an older office that takes no app warns at once, with what
# to do (a coded warning of its own); any other refusal is said by partner_missed at the partner's end (2.46)
partner_refused() { # partner_refused <id> <why>
    case "$2" in refused_quota|old_partner) ;; *) return 0 ;; esac
    [[ " ${PARTNER_TOLD[$1]:-} " == *" $2 "* ]] && return 0
    PARTNER_TOLD[$1]+=" $2"; PARTNER_WARNED[$1]=1
    # 2.45: an older partner office that takes no app unit: a warning every run, with what to do
    if [[ "$2" == old_partner ]]; then
        warn_code partner_old "Partner $(partner_name "$1") runs an older office that can't keep an app on its own - update the office there" partner="$(partner_name "$1")"
        return 0
    fi
    # 2.45: a full quota warns every run (once per partner) - nothing goes there until someone acts
    # 2.78: with how much of it the door's earlier copies hold (<unit>.old-<time>, kept aside after a full send - only
    # the partner's Team Lead lets them go): a code of its own, the size in whole GB (at least 1)
    local aside="${PARTNER_ASIDE[$1]:-0}" gb
    if is_uint "$aside" && (( aside > 0 )); then
        gb=$(( (aside + 536870912) / 1073741824 )); (( gb < 1 )) && gb=1
        warn_code partner_quota_aside "Partner $(partner_name "$1") refused: its quota for this server is full - it keeps the copies it has, $gb GB of it earlier copies kept aside there; let them go at the partner (its Team Lead, this server's card), raise the quota or free space there" partner="$(partner_name "$1")" aside_gb="$gb"
        return 0
    fi
    warn_code partner_quota "Partner $(partner_name "$1") refused: its quota for this server is full - it keeps the copies it has; raise the quota at the partner or free space there" partner="$(partner_name "$1")"
}
declare -A PARTNER_TOLD=()
declare -A PARTNER_ASIDE=()    # 2.78: id -> bytes the partner's door says its earlier copies hold (its refused_quota)
declare -A PARTNER_WARNED=()   # 2.46: id -> 1 once a coded warning of this run names that partner (partner_missed adds none)

# partner_missed_hint <why>: what to do about it, after the reason in words (the mail's and the log's warning)
partner_missed_hint() {
    case "$1" in
        unreachable)    echo " - is its server on, its office running, the way there open?" ;;
        array_stopped)  echo " - its array is stopped; start it there" ;;
        refused_asleep) echo " - its office is older and refuses while its pool sleeps; update the office there" ;;
        refused_window) echo " - outside its receiving window (an older office there; update it)" ;;
        not_agreed)     echo " - an older office there keeps only what its Team Lead agreed to; update it there" ;;
        link_lost)      echo " - the next run continues it" ;;
        *)              echo "" ;;
    esac
}

# partner_missed <id>: 2.46 - a partner that got nothing or not all of this run is said IN this run, no nights of grace
# (Benj 2026-10-12: a failed part is reported at once, in the mail and in Mr. Backupsy's bubble). One coded warning per
# partner (partner_missed, partner + why: the office names the partner and the reason in its own words) unless another
# coded one already named it (ended, no_key, snap_prefix, no_ssh, quota, old_partner). The units this server can't send
# at all (PARTNER_CANT: not a dataset of its own, its pool asleep here with asleep_pools = skip ...) are the setup's
# business, said there - not counted here. A failed transfer warned already (partner_fail): its item, no second count.
partner_missed() {
    local id="$1" l c why="" failed=0 cant name
    [[ -n "${PARTNER_WARNED[$id]:-}" ]] && return 0
    for l in "${ST_PARTNER_FAILED[@]}"; do
        [[ "$l" == "$id|"* ]] || continue
        failed=1; [[ -n "$why" ]] || why="${l##*|}"
    done
    if [[ -z "$why" ]]; then
        for l in "${ST_PARTNER_SKIPPED[@]}"; do
            [[ "$l" == "$id|"* ]] || continue
            cant=0
            for c in "${PARTNER_CANT[@]}"; do [[ "$c" == "$l" ]] && { cant=1; break; }; done
            (( cant )) && continue
            why="${l##*|}"; break
        done
    fi
    [[ -n "$why" ]] || return 0                         # every unit it should get arrived (or none was due)
    [[ -n "${PARTNER_DOWN[$id]:-}" ]] && why="${PARTNER_DOWN[$id]}"     # the cause, when the whole partner was down
    PARTNER_WARNED[$id]=1
    name="$(partner_name "$id")"
    if (( failed )); then
        WARN_ITEMS+=( "partner_missed"$'\x1f'"partner=$name"$'\x1f'"why=$why" )
        log "  Partner $name: this run's copy did not arrive whole - $(partner_why_text "$why")$(partner_missed_hint "$why")"
    else
        warn_code partner_missed "Partner $name: this run's copy did not arrive - $(partner_why_text "$why")$(partner_missed_hint "$why")" partner="$name" why="$why"
    fi
}

partner_phase() {
    local line row id u ds b name why key
    next_phase "partner"
    PARTNER_TMP="$(mktemp -d "${TMPDIR:-/tmp}/uso-partner.XXXXXX")" || { err "Partners: no temporary folder - nothing sent"; return 0; }
    for line in "${ST_PARTNER_IDS[@]}"; do
        id="${line%%|*}"; name="$(partner_name "$id")"
        partner_addr "$id"
        log "Partner $name ($id, $PADDR:$PPORT) ..."
        why=""
        key="$UB_PARTNER_DIR/$id.key"
        if partner_ended "$id"; then
            why="ended"; log "  the partnership $name ($id) was ended at the Team Lead - Set up... > Apply gives its units to the partnership made anew (or drops them)"
        elif [[ "$SNAP_PREFIX" != "$UB_SNAP_PREFIX" ]]; then why="snap_prefix"; log "  the snapshots are called $SNAP_PREFIX... - a partner's door takes only $UB_SNAP_PREFIX..."
        elif [[ ! -f "$key" || -L "$key" || ! -f "$UB_PARTNER_DIR/$id.known" || -L "$UB_PARTNER_DIR/$id.known" ]]; then
            why="no_key"; log "  its key or known_hosts is missing ($UB_PARTNER_DIR/$id.key, .known) - pair anew at the Team Lead"
        elif ! command -v ssh >/dev/null 2>&1; then why="no_ssh"; log "  ssh is missing"
        else
            partner_ask "$id" ping
            if (( $? == 255 )); then why="unreachable"
            elif [[ "$(jq -r '.array // ""' <<<"$PA_JSON" 2>/dev/null)" == "stopped" ]]; then why="array_stopped"; log "  its array is stopped - nothing can be received now"
            fi
            # 2.73: how many transfers its door takes at once (a door before 1.72 says nothing: one)
            PARTNER_STREAMS[$id]="$(jq -r '.streams // 1 | if type == "number" and . >= 1 and . <= 16 then floor else 1 end' <<<"$PA_JSON" 2>/dev/null)"
        fi
        [[ -n "$why" ]] && PARTNER_DOWN[$id]="$why"
        # 2.45: a skip that means the setup or the pairing is broken warns every run (once per partner), with what to do -
        # the run ends «with warnings», never «successful» with units left out; unreachable / array_stopped: partner_missed
        case "$why" in ended|no_key|snap_prefix|no_ssh) PARTNER_WARNED[$id]=1 ;; esac
        case "$why" in
            ended)       warn_code partner_ended "Partner $name: the partnership was ended at the Team Lead - nothing sent; apply Mr. Backupsy's setup (Set up... > Apply) so its units go to the partnership made anew" partner="$name" ;;
            no_key)      warn_code partner_no_key "Partner $name: this office's key for it is missing - nothing sent; pair anew at the Team Lead" partner="$name" ;;
            snap_prefix) warn_code partner_snap_prefix "Partner $name: the snapshots are called $SNAP_PREFIX... and a partner takes only $UB_SNAP_PREFIX... - nothing sent; set the snapshot prefix back to $UB_SNAP_PREFIX in Mr. Backupsy's setup" partner="$name" prefix="$UB_SNAP_PREFIX" ;;
            no_ssh)      warn_code partner_no_ssh "Partner $name: ssh is missing on this server - nothing sent" partner="$name" ;;
        esac
        for u in "${PARTNER_CANT[@]}"; do
            [[ "$u" == "$id|"* ]] || continue
            IFS='|' read -r _ u why <<<"$u"
            if [[ "$why" == "asleep" ]]; then log "  $u: its pool sleeps - left out this run (asleep_pools = skip)"
            else log "  $u: not covered ($why)"; fi
            partner_skip "$id" "$u" "$why"
        done
        for u in "${ST_PARTNER_LEFT[@]}"; do
            [[ "$u" == "$id|"* ]] || continue
            IFS='|' read -r _ u ds b why <<<"$u"
            if [[ -n "$why" ]]; then log "  $u: its dataset $ds ($b) is $why's own, which doesn't take it to this partner - it stays here, $u goes without it"
            else log "  $u: its dataset $ds ($b) is no unit of its own (no VM's, no app's) - it stays here, $u goes without it"; fi
        done
        local tw
        tw="$(date +%s)"
        partner_units_run "$id"
        is_uint "${ST_PARTNER_WALL[$id]:-x}" || ST_PARTNER_WALL[$id]=$(( $(date +%s) - tw ))
        # 2.46: whatever did not arrive is said in this run - unreachable, array stopped, refused, failed (no nights of grace)
        partner_missed "$id"
    done
    rm -rf "$PARTNER_TMP"; PARTNER_TMP=""
    local nd=0 nb=0
    for line in "${ST_PARTNER_DONE[@]}"; do IFS='|' read -r _ _ _ _ b _ <<<"$line"; nd=$((nd+1)); nb=$(( nb + b )); done
    log "Partners: $nd sent ($(human "$nb")), ${#ST_PARTNER_SKIPPED[@]} skipped, ${#ST_PARTNER_FAILED[@]} failed"
    status_write
}

# run_name_taken: a real backup's name (the minute: run id, log, uso-backup-<minute> snapshots) is never used twice (2.66,
# B19) - prints what has it already and returns 0; 1 when it is free. Its log (2.34); a history row of a run with this id (its
# log pruned or set aside); a snapshot of the name on a pool or disk awake that the data folder knows nothing of (the
# autumn hour 02:00-03:00 comes twice, a data folder set back or new): ZFS <dataset>@<prefix><minute>, btrfs
# <base>/<btrfs_snap_dir>/<minute>. Settings are read in a subshell (the run reads them itself later); a sleeping disk is
# never looked at. Readers that stop early take a $( ) (never grep -q on a pipe, 2.35).
run_name_taken() {
    local h cfgv prefix sdir i t name fs src snaps
    [[ -e "$LOG_FILE" ]] && { printf 'its log %s is there' "$(basename "$LOG_FILE")"; return 0; }
    h="$(jq -r --arg r "$TS" 'select(type == "object" and .run == $r and .result != "skipped") | .run' "$UB_STATE/history.jsonl" 2>/dev/null)"
    [[ -n "$h" ]] && { printf 'the history has a run %s' "$TS"; return 0; }
    cfgv="$(load_settings >/dev/null 2>&1; printf '%s\x1f%s' "${SNAP_PREFIX:-$UB_SNAP_PREFIX}" "${BTRFS_SNAP_DIR:-.btrfs-snap}")"
    IFS=$'\x1f' read -r prefix sdir <<<"$cfgv"
    [[ -n "$prefix" && -n "$sdir" && "$sdir" != */* ]] || return 1
    mounts_load
    for i in "${!MT_TARGET[@]}"; do
        t="${MT_TARGET[$i]}"; fs="${MT_FSTYPE[$i]}"; src="${MT_SOURCE[$i]}"
        [[ "$t" == "$UB_MNT/"* ]] || continue
        name="${t#"$UB_MNT"/}"
        [[ -n "$name" && "$name" != */* ]] || continue
        case "$name" in user|user0|disks|remotes|addons|rootshare) continue ;; esac
        ub_base_sleeps "$name" && continue
        case "$fs" in
            btrfs) [[ -e "$t/$sdir/$TS" ]] && { printf 'a btrfs snapshot %s is there' "$t/$sdir/$TS"; return 0; } ;;
            zfs)   [[ "$src" == */* ]] && continue      # a pool's top dataset lists the whole pool
                   snaps="$(timeout 60 zfs list -H -t snapshot -o name -r "$src" 2>/dev/null | awk -v n="@$prefix$TS" 'index($0, n) && substr($0, length($0) - length(n) + 1) == n { print; exit }')"
                   [[ -n "$snaps" ]] && { printf 'a ZFS snapshot %s is there' "$snaps"; return 0; } ;;
        esac
    done
    return 1
}

##############################################################################
# Start
##############################################################################
# The lock (flock on state/lock) is held by one run at a time - see lib/common.sh, "Who holds the
# lock". Opened without truncating it: a run turned away must not touch it (the office reads the
# start of the run holding it from its time); the one that gets it touches it.
[[ "$UB_MODE" == "backup" && "$DRY" != "1" && "$UB_SCHEDULED" == "1" && ! -f "$UB_SETTINGS" ]] && skip_unset
exec 9>>"$UB_STATE/lock"
if [[ "$UB_MODE" == "unmount" ]]; then
    # by hand (the office's «Unmount», a terminal) its log is the newest; from the plugin's array-stop hook
    # (UB_KEEP_LATEST=1, 2.25) latest.log stays the last run's - for the office and Ms. Protocolli
    [[ "${UB_KEEP_LATEST:-0}" == "1" ]] || ln -sfn "$(basename "$LOG_FILE")" "$UB_LOGS/latest.log" 2>/dev/null
    if [[ "${UB_ARRAY_STOP:-0}" == "1" ]]; then
        # the plugin's array-stop hook (2.25) calls it only when no live run of this engine holds the lock (that one
        # releases everything itself, run_exit/cleanup): whoever holds it then - the setup, a restore, a --recover, an
        # orphan of a killed run that inherited the lock - mounts nothing there. Never waited for: the stop goes on
        echo "$(_ts)  The array is being stopped - releasing the engine's mounts without waiting for the lock" >>"$LOG_FILE"
    else
        flock -w 10 9 || echo "$(_ts)  A run holds the lock - unmounting anyway" >>"$LOG_FILE"
    fi
elif [[ "$UB_MODE" == "recover" ]]; then
    # busy: whoever holds it is a run (or the setup) that brings the notes back at its own start - quietly,
    # no skipped.json, no notification, status.json untouched (it describes that run)
    flock -n 9 || exit 75
    touch "$UB_STATE/lock"
    ub_holder_write backup recover "$TS" "$STARTED_AT"
    trap ub_holder_clear EXIT
    # latest.log stays the last run's: a recover is no run
else
    # a --recover holds the lock for seconds to minutes after an array start: wait for it rather than skip the night
    flock -n 9 || { recover_holds && flock -w "$UB_RECOVER_LOCK_WAIT" 9; } || skip_busy
    # one run a minute (2.34): the run id, its log and the snapshots' name carry the minute - a second run in the same
    # minute would share them (ZFS refuses the existing snapshot name: "failed", one id twice in history.jsonl). Its log
    # is there already: nothing of that run is touched (lock, note, status.json, latest.log, snapshots), exit 1. 2.45: only a
    # real backup (its snapshots carry the minute); a tour or a dry run names its log by the second (the same second: refused)
    # 2.66 (B19): not only its log - its history row, a snapshot of its name (run_name_taken)
    if [[ "$UB_MODE" == "backup" && "$DRY" != "1" ]] && taken="$(run_name_taken)"; then
        echo "ERROR: a run of this minute was made already ($taken) - the name $TS is never used twice, nothing touched - one run a minute: try again in $(( 60 - 10#$(date +%S) )) s" >&2
        exit 1
    elif [[ -e "$LOG_FILE" ]]; then
        echo "ERROR: a run of this minute was made already ($(basename "$LOG_FILE")) - one run a minute: try again in $(( 60 - 10#$(date +%S) )) s" >&2
        exit 1
    fi
    touch "$UB_STATE/lock"
    if [[ "$UB_MODE" == "check" ]]; then ST_MODE="check"
    elif [[ "$DRY" == "1" ]]; then ST_MODE="dryrun"
    else ST_MODE="backup"; fi
    ub_holder_write backup "$ST_MODE" "$TS" "$STARTED_AT"
    trap run_exit EXIT          # an array stop meanwhile: nothing of the engine stays mounted
    ln -sfn "$(basename "$LOG_FILE")" "$UB_LOGS/latest.log" 2>/dev/null
    status_init "$ST_MODE"
fi

# --- --recover (2.25) ---------------------------------------------------------
# Right after the array start (the plugin's event/started hands it to atd when a note exists): what an
# interrupted run left stopped, in maintenance mode or held - the array stop leaves it so (2.24) - comes
# back now instead of with the next run, often the next night. Needs no settings.ini. Writes no status.json,
# last-run.json or history line - it is no backup run, and the office keeps showing the run that left the
# notes; recover_interrupted_run sends its notification ("Aborted run repaired", or "... not fully repaired" - a
# warning naming what didn't come back), recover.log keeps the rest.
# Exit 0 done (nothing left noted), 1 something stays noted (a service didn't answer, a container or VM
# didn't start, a Nextcloud's container didn't run - the next run tries again), 3 the array is being stopped,
# 75 the lock is busy.
if [[ "$UB_MODE" == "recover" ]]; then
    log "===================== $UB_NAME $UB_VERSION - recover $TS ====================="
    rn=()
    [[ -s "$UB_STATE/stopped" ]]     && rn+=( "containers $(paste -sd' ' "$UB_STATE/stopped")" )
    [[ -s "$UB_STATE/maintenance" ]] && rn+=( "maintenance mode $(paste -sd' ' "$UB_STATE/maintenance")" )
    [[ -s "$UB_STATE/vms" ]]         && rn+=( "VMs $(cut -d'|' -f1 "$UB_STATE/vms" | paste -sd' ')" )
    log "Notes of an interrupted run: $(printf '%s; ' "${rn[@]}" | sed 's/; $//')"
    recover_wait || { log "The array is being stopped - nothing is started now; the notes stay for after the array start."; exit 3; }
    recover_interrupted_run
    if recover_notes || (( ERRORS > 0 )); then
        log "Not all of it came back$( (( ERRORS > 0 )) && echo " ($ERRORS error(s))") - what is still noted is tried again by the next run."
        exit 1
    fi
    log "Done - nothing is noted any more$( (( WARNINGS > 0 )) && echo " ($WARNINGS warning(s) - see above)")."
    exit 0
fi

SETTINGS_OK="yes"
load_settings || { SETTINGS_OK="no"; apply_settings; }

if [[ "$UB_MODE" == "unmount" ]]; then
    # Must also work with a missing or broken settings.ini. UB_ARRAY_STOP=1 (the plugin's array-stop hook, 2.25):
    # bounded - busy mounts are detached at once (umount -l), no second try
    log "Unmount requested$([[ "${UB_ARRAY_STOP:-0}" == "1" ]] && echo " (the array is being stopped)")."
    if [[ "${UB_ARRAY_STOP:-0}" == "1" ]]; then unmount_all now; else unmount_all; fi \
        && log "Everything unmounted." || log "Not everything could be unmounted."
    exit 0
fi

# 2.81 (B42): a code - the office says it in its own words (the settings gone, set up again), never the script's name
[[ "$SETTINGS_OK" == "yes" ]] || die_code settings_missing "settings.ini is missing ($UB_SETTINGS) - run setup.sh first"
if [[ ${#CFG_ERRORS[@]} -gt 0 ]]; then
    for e in "${CFG_ERRORS[@]}"; do err "settings.ini: $e"; done
    die "settings.ini has errors (${#CFG_ERRORS[@]}) - run aborted"
fi

trap cleanup EXIT
trap on_signal INT TERM
tmp_files_sweep                        # 2.85: what a run killed outright left in /tmp
CLEANUP_ARMED="yes"

log "===================== $UB_NAME $UB_VERSION - $UB_MODE $TS ====================="
array_stop_check "at its start"      # before anything - also before an earlier run's containers are started again
recover_interrupted_run
[[ "$DRY" == "1" ]] && log "DRY RUN - nothing is changed."
# 2.70: the scheduled run waited for the office's mover run before it started (status.json "order")
order_load
if [[ "$ST_ORDER_CODE" == "waited_night" ]]; then
    span="$(( (ST_ORDER_SECONDS + 30) / 60 )) min"; (( ST_ORDER_SECONDS < 60 )) && span="$ST_ORDER_SECONDS s"
    log "Waited $span for the office's mover run (Ms. Moverelli) until it was done"
    status_write
fi

for t in jq flock timeout gzip numfmt mountpoint awk; do
    command -v "$t" >/dev/null 2>&1 || die "Tool '$t' is missing"
done
# 2.54: Docker switched off - a server of shares and VMs, no apps this run; switched on but silent stays an error
if ub_docker_service_off; then log "Docker is switched off (Settings > Docker) - no apps to back up"
# 2.66 (B15): asked with a time limit (ub_docker_answers, 20 s) - a hung Docker ends the run here, before anything is stopped
# (its apps could be neither stopped nor started again), with a code the office says in its words
elif ! ub_docker_answers; then die_code docker_silent "Docker is switched on but does not answer ($UB_DOCKER_ANSWER s) - nothing was stopped, nothing backed up; restart Docker (Settings > Docker) and start the run again"; fi
mountpoint -q "$UB_MNT/user" || die "$UB_MNT/user is not mounted - array/pools not started?"

SNAP_NAME="${SNAP_PREFIX}${TS}"
# settings.ini still names the old default prefix: new snapshots get the new one, the old ones age out
if [[ "$SNAP_PREFIX_SET" == "$UB_SNAP_PREFIX_LEGACY" ]]; then
    log "Snapshot names: settings.ini still says the old default prefix $UB_SNAP_PREFIX_LEGACY - new snapshots are called ${SNAP_PREFIX}YYYYMMDD-HHMM, the ${UB_SNAP_PREFIX_LEGACY}... ones stay the engine's and age out by the retention (Set up... > Apply writes the new prefix)"
fi

# --- Inventory, plan, drift ------------------------------------------------
# 2.45: the partners this run sends to are in status.json from its first line (the office's run card shows their step at
# once, not only from the partner phase on); partner_plan fills in their units after the inventory
if [[ "$UB_MODE" != "check" ]]; then
    while IFS= read -r pl_id; do [[ -n "$(partner_units "$pl_id")" ]] && ST_PARTNER_IDS+=( "$pl_id|$(partner_name "$pl_id")" ); done < <(partner_ids)
fi
next_phase "inventory"
inv_scan
docker_load
vm_load
plan_build
[[ ${#PLAN_ZFS[@]}   -gt 0 ]] && ! command -v zfs   >/dev/null && die "zfs is missing"
[[ ${#PLAN_BTRFS[@]} -gt 0 ]] && ! command -v btrfs >/dev/null && die "btrfs is missing"

drift_check_shares
drift_check_containers
drift_check_vms
drift_check_items
drift_check_new_local                # what the last run left out as new and is still undecided (section 11)
if [[ "$SKIPK" != "1" ]]; then drift_check_kopia; else KOPIA_OK="skip"; fi
# 2.75: Docker switched off by the user - nothing goes offsite (Kopia runs in a container); a warning, never an error:
# the user chose it in Unraid's settings (2.54: a server of shares and VMs), the local part and the partners go as usual
if [[ "$KOPIA_OK" == "docker_off" ]]; then
    warn_code docker_off_offsite "Docker is switched off (Settings > Docker) - Kopia runs in a container, so nothing goes offsite in this run; local snapshots, packages and partners as usual"
fi
[[ "$KOPIA_OK" == "no" ]] && kopia_gone_check      # 2.53: Kopia can't open the repository - its format blob deleted with all the rest

# The backup place: a share of its own, backed up - otherwise no run (nothing is paused up to here)
DUMPS_PROBLEM="$(dumps_share_problem "$DUMPS_SHARE")"
if [[ -n "$DUMPS_PROBLEM" ]]; then
    if [[ "$UB_MODE" == "check" ]]; then
        drift_add error "$(dumps_share_text "$DUMPS_PROBLEM" "$DUMPS_SHARE")" "dumps_$DUMPS_PROBLEM" "$DUMPS_SHARE" share="$DUMPS_SHARE"
    else
        die_code "dumps_$DUMPS_PROBLEM" "$(dumps_share_text "$DUMPS_PROBLEM" "$DUMPS_SHARE") - choose the place in Mr. Backupsy's setup"
    fi
elif is_yes "$KOPIA_ENABLED" && [[ "$(share_mode "$DUMPS_SHARE")" != "kopia" ]]; then
    drift_add warn "The backup place '$DUMPS_SHARE' does not go to Kopia (mode=$(share_mode "$DUMPS_SHARE")) - the packages stay local only" \
        place_not_kopia "$DUMPS_SHARE" share="$DUMPS_SHARE" mode="$(share_mode "$DUMPS_SHARE")"
fi
# the packages keep their history in the snapshots of the backup place's share
if [[ -z "$DUMPS_PROBLEM" && "$(share_method "$DUMPS_SHARE")" == "live" ]]; then
    drift_add warn "The backup place '$DUMPS_SHARE' cannot take snapshots ($(printf '%s' "${INV_NOTE[$DUMPS_SHARE]:-}" | head -1)) - its packages keep no history, only the newest state; put the share on a ZFS or btrfs pool" \
        place_no_history "$DUMPS_SHARE" share="$DUMPS_SHARE"
fi
if [[ -z "$DUMPS_PROBLEM" && "$DRY" != "1" && "$UB_MODE" != "check" ]]; then
    # root only: the packages hold database contents, templates and .env files with passwords
    mkdir -p "$UB_DUMPS" && chmod 700 "$UB_DUMPS" || die "Backup place $UB_DUMPS cannot be created"
    # what a killed run left half swapped in or half built
    n="$(pkg_recover "$UB_DUMPS")"; (( n > 0 )) && log "  Backup place: $n package folder(s) of an interrupted run tidied up"
    # the packages of the place before the last setup come along once (setup.sh notes it in
    # state/dumps-previous) - those of apps and VMs this place doesn't know; the run folders of
    # engines before 2.18 there go at the end of the run, like the ones here
    prev=""; [[ -s "$UB_STATE/dumps-previous" ]] && prev="$(head -1 "$UB_STATE/dumps-previous")"
    if place_path_ok "$prev" && [[ "$prev" != "$UB_DUMPS" && -d "$prev" ]]; then
        for sub in apps vms; do
            for d in "$prev/$sub"/*/; do
                d="${d%/}"; [[ -d "$d" && ! -L "$d" && ! -e "$UB_DUMPS/$sub/${d##*/}" ]] || continue
                mkdir -p "$UB_DUMPS/$sub" && chmod 700 "$UB_DUMPS/$sub" \
                    && mv "$d" "$UB_DUMPS/$sub/" 2>>"$LOG_FILE" && log "  Package $sub/${d##*/} moved to $UB_DUMPS"
            done
        done
    fi
    [[ -n "$prev" && ! -d "$prev" ]] && rm -f "$UB_STATE/dumps-previous"
fi

report_drift

if [[ "$UB_MODE" == "check" ]]; then
    log "Check done: $(drift_count error) errors, $(drift_count warn) warnings, $(drift_count info) notes."
    if (( $(drift_count error) > 0 || ERRORS > 0 )); then status_finish errors
    elif (( $(drift_count warn) > 0 || WARNINGS > 0 )); then status_finish warnings
    else status_finish ok; fi
    trap run_exit EXIT
    exit 0
fi

case "$KOPIA_OK" in
    no)  err "Kopia is skipped in this run (see the drift above)" ;;
    off) log "Kopia is switched off ([kopia] enabled = no) - local snapshots and dumps only." ;;
esac

# --- Sleeping pools (2.28): left out before anything is planned around them -
asleep_plan

# --- Showing the plan -----------------------------------------------------
build_stop_tiers
pool_room_check                      # 2.69: before anything stops - the pools with almost no room holding their data
vm_plan
pkg_plan
sqlite_plan
log "Plan:"
log "  ZFS snapshots:    ${PLAN_ZFS[*]:-none}"
if (( ${#SNAP_PREFIXES[@]} > 1 )); then log "  Snapshot name:    $SNAP_NAME (the retention also clears away the older ${SNAP_PREFIXES[*]:1}...)"
else log "  Snapshot name:    $SNAP_NAME"; fi
log "  btrfs snapshots:  ${PLAN_BTRFS[*]:-none}"
log "  Flash:            $PLAN_FLASH${FLASH_DATASET:+ ($FLASH_DATASET)}"
log "  VM configuration: $LIBVIRT_MODE$(mountpoint -q /etc/libvirt || echo ' (VM service off)')"
log "  Dumps:            $(cfg_names dump | paste -sd' ' -)"
log "  Nextcloud:        $(cfg_names nextcloud | paste -sd' ' -)"
log "  Pause:            ${T_APP[*]:-} | DB: ${T_DB[*]:-} | network: ${T_NET[*]:-}"
log "  Keep running:     ${KOPIA_CONTAINER:-} ${DOCKER_NO_STOP[*]:-}"
(( ${#T_NEW[@]} )) && log "  New, keep running: ${T_NEW[*]} (not stopped until you decide in the setup)"
(( ${#PLAN_DELETED[@]} )) && log "  Left out, deleted: ${PLAN_DELETED[*]} (deleted in Unraid - only their snapshots keep the datasets; see the drift above)"
(( ${#T_PAUSED[@]} )) && log "  Paused by you:    ${T_PAUSED[*]} (left as they are - not stopped, not started; paused again afterwards)"
(( ${#T_REST[@]} )) && log "  Asleep, keep running: ${T_REST[*]} (all their backed-up data on pools left out this run)"
(( ${#ST_ASLEEP_POOLS[@]} )) && log "  Left out, asleep:  ${ST_ASLEEP_POOLS[*]} - shares ${ST_ASLEEP_SHARES[*]:-none}${ST_ASLEEP_VMS[*]:+, VMs ${ST_ASLEEP_VMS[*]}}"
if (( ${#NEW_LIST[@]} )); then
    nl=""; for l in "${NEW_LIST[@]}"; do IFS=$'\x1f' read -r nl_s nl_n _ <<<"$l"; nl+="$nl_s/$nl_n "; done
    log "  New, only local:  ${nl}(Kopia leaves them out until you decide; the run looks again before Kopia)"
fi
log "  Packages:         ${#PKG_APPS[@]} apps, ${#PKG_VMS[@]} VMs -> $UB_DUMPS"
if (( ${#SQ_PLAN[@]} )); then
    sqline=""
    for l in "${SQ_PLAN[@]}"; do IFS='|' read -r sq_c _ sq_p sq_a <<<"$l"; sqline+="$sq_c:${sq_p##*/}${sq_a:+ (asleep)} "; done
    log "  SQLite copies:    $sqline(media servers that keep running)"
fi
if [[ "$VM_SERVICE" == "yes" ]]; then
    # per VM what happens: its prepare method, or why nothing (off, kept_running, not_running)
    vmline=""
    for vl in "${ST_VMS[@]}"; do
        IFS='|' read -r vn vp vd _ <<<"$vl"
        if [[ "$vd" == "planned" ]]; then vmline+="$vn ($vp) "; else vmline+="$vn ($vd) "; fi
    done
    log "  VMs:              ${vmline:-none}"
fi
is_yes "$KOPIA_ENABLED" || log "  Kopia:            off"
for s in "${PLAN_KOPIA[@]}"; do
    hp="$(share_kopia_hostpath "$s")"
    cp="$(k_path "$hp" 2>/dev/null)" || cp="?"
    log "  Kopia:            $s  [$(share_method "$s")/${INV_LAYOUT[$s]}]  $hp -> $cp${SKIP_KOPIA[$s]:+  (SKIPPED: ${SKIP_KOPIA[$s]})}"
done
for it in "${PLAN_KITEMS[@]}"; do
    IFS='|' read -r it_t it_n it_f <<<"$it"
    hp="$(item_hostpath "$it_t" "$it_f")"
    cp="$(k_path "$hp" 2>/dev/null)" || cp="?"
    log "  Kopia:            $it_t '$it_n' (a source of its own)  $hp -> $cp${SKIP_KOPIA[$it_t:$it_n]:+  (SKIPPED: ${SKIP_KOPIA[$it_t:$it_n]})}"
    while IFS='|' read -r it_s it_r; do log "                      $it_s/$it_r"; done < <(kopia_item_parts "$it_t" "$it_n" "$(item_pkg "$it_t" "$it_n")")
done

KOPIA_ORDER=()              # the Kopia phase's sources in their order (2.25, kopia_order): "name|kind|item|folder|bytes|from"
if [[ "$KOPIA_OK" == "yes" && "$SKIPK" != "1" ]]; then
    # small and important first: the flash, the apps' own sources, then shares and VMs by expected size
    kopia_sizes_load
    kopia_emptied_check              # 2.53: far fewer snapshots than at the last run's end, not by the office - said at once
    [[ "$KOPIA_HELD" == "yes" ]] && KOPIA_OK="held"      # 2.56: ... and the Kopia step held - no upload over what is left
fi
if [[ "$KOPIA_OK" == "yes" && "$SKIPK" != "1" ]]; then
    mapfile -t KOPIA_ORDER < <(kopia_order)
    ST_KOPIA_PLAN=(); ko_line=""
    for ko in "${KOPIA_ORDER[@]}"; do
        IFS='|' read -r ko_name ko_kind _ _ ko_b ko_from <<<"$ko"
        ST_KOPIA_PLAN+=( "$ko_name" )
        case "$ko_kind" in
            share|vm) if [[ -n "$ko_from" ]]; then ko_line+="${ko_line:+, }$ko_name $(human "$ko_b")$([[ "$ko_from" == "inventory" ]] && echo '*')"
                      else ko_line+="${ko_line:+, }$ko_name ?"; fi ;;
            *)        ko_line+="${ko_line:+, }$ko_name" ;;
        esac
    done
    log "  Kopia order:      ${ko_line:-none}"
    log "                    (shares and VMs the smallest first - the larger of Kopia's size (its newest complete snapshot, or a newer checkpoint when larger) and the server's, * = the server's (ZFS; a VM's disk files whole - a sparse vdisk's holes are read too); ? unknown, last)"
fi
# partners (2.27): per partner its units in the phase's order - the backup place, then by size (ZFS's referenced)
partner_plan
for pl in "${ST_PARTNER_IDS[@]}"; do
    pl_id="${pl%%|*}"; pl_line=""
    for po in "${PARTNER_ORDER[@]}"; do
        IFS='|' read -r po_id po_u po_ds po_b <<<"$po"
        [[ "$po_id" == "$pl_id" ]] && pl_line+="${pl_line:+, }$po_u ($po_ds$( (( po_b > 0 )) && echo ", $(human "$po_b")"))"
    done
    for po in "${PARTNER_CANT[@]}"; do
        IFS='|' read -r po_id po_u po_why <<<"$po"
        [[ "$po_id" == "$pl_id" ]] && pl_line+="${pl_line:+, }$po_u ($(case "$po_why" in asleep) echo "asleep, left out" ;; not_agreed) echo "not agreed yet" ;; *) echo "not covered: $po_why" ;; esac))"
    done
    log "  Partner:          $(partner_name "$pl_id") <- ${pl_line:-nothing}${SNAP_NAME:+ ($SNAP_NAME)}"
    for po in "${ST_PARTNER_LEFT[@]}"; do
        IFS='|' read -r po_id po_u po_ds po_f po_o <<<"$po"
        if [[ "$po_id" == "$pl_id" ]]; then log "                    $po_u goes without its dataset $po_ds ($po_f): $(if [[ -n "$po_o" ]]; then echo "$po_o's own, not sent to this partner"; else echo "no VM's or app's own"; fi) - it stays here"; fi
    done
done
status_write

if [[ "$DRY" == "1" ]]; then
    for f in "${PKG_APPS[@]}"; do log "  Package apps/$f: ${PKG_APP_NAME[$f]} (${PKG_APP_TYPE[$f]}: $(sed '/^$/d' <<<"${PKG_APP_MEMBERS[$f]}" | paste -sd' ' -))"; done
    for f in "${PKG_VMS[@]}"; do log "  Package vms/$f: ${PKG_VM_NAME[$f]}"; done
    pkg_old_runs list
    PKG_OLD_ACTION=""; (( PKG_OLD_RUNS > 0 )) && PKG_OLD_ACTION="would_remove"
    pkg_status no
    log "Dry run done."
    if (( ERRORS > 0 )); then status_finish errors
    elif (( WARNINGS > 0 )); then status_finish warnings
    else status_finish ok; fi
    trap run_exit EXIT
    exit 0
fi

array_stop_check "before the packages are begun"
FREE_MB=$(df -Pm "$UB_DUMPS" 2>/dev/null | awk 'NR==2{print $4}')
# 2.65: coded with what it found (up to 2.64 an English sentence on every page) - need/free in bytes for the page, the
# notification's words with them readable
if (( ${FREE_MB:-0} < MIN_FREE_GB * 1024 )); then
    die_code no_space_packages "Not enough space for the packages in $UB_DUMPS (${FREE_MB:-0} MB free, min_free_gb = $MIN_FREE_GB)" \
        place="$UB_DUMPS" free="$(( ${FREE_MB:-0} * 1048576 ))" need="$(( MIN_FREE_GB * 1073741824 ))" \
        free_h="$(human $(( ${FREE_MB:-0} * 1048576 )))" need_h="$(human $(( MIN_FREE_GB * 1073741824 )))"
fi
pkg_begin || die "Cannot create $PKG_STAGE"

# Release what an earlier run left behind
unmount_all || die "Old mounts under $MOUNT_ROOT cannot be released"
legacy_dirs_remove

# --- VMs that shut down: before anything stops ------------------------------
# A guest takes minutes or ignores the request: shut down and waited for while everything still
# runs, so neither Nextcloud's maintenance mode nor the apps' downtime includes it (2.22)
vm_shutdowns

# --- Maintenance mode, manifest ---------------------------------------------
next_phase "maintenance"
log "Nextcloud ..."
nextcloud_maintenance_on
next_phase "manifest"
log "Packages: templates, compose files, the server's lists ..."
pkg_server                           # still with all containers running
pkg_apps_static
if (( ${#SQ_PLAN[@]} )); then
    log "Consistent copies of the media servers' databases (they keep running) ..."
    run_sqlite_copies
fi

# --- Pausing, dumps, snapshots, starting ------------------------------------
# Pause the apps first, then dump: apps without a maintenance mode (Immich & co.)
# would otherwise keep writing between dump and snapshot - the dump would then
# no longer quite match the files. The databases still run for the dump.
STOP_AT="$(date +%s)"
next_phase "stopping_apps"
log "Pausing apps: ${#T_APP[@]} (before the dumps, so that dumps and files match)"
stop_tier "${T_APP[@]}"
next_phase "dumps"
log "Database dumps ..."
run_dumps
[[ "$PLAN_FLASH" == "tar" ]] && flash_tar
array_stop_check "before the app packages go in place"
pkg_commit_apps                      # the app packages in place, before the databases stop
next_phase "stopping"
log "Stopping: ${#T_DB[@]} databases, ${#T_NET[@]} network"
stop_tier "${T_DB[@]}"
stop_tier "${T_NET[@]}"
if [[ ${#VM_TODO[@]} -gt 0 ]]; then
    next_phase "vms"
    log "VMs: ${#VM_TODO[@]} prepared for the snapshot"
    vm_hold                          # freeze, pause (the shutdowns are done - vm_shutdowns)
fi
# after the VMs: their TPM state and NVRAM then match the disks in the snapshot
array_stop_check "before the VM packages are written"
[[ "$LIBVIRT_MODE" == "tar" ]] && libvirt_tar
pkg_vms
pkg_commit_rest                      # VMs and server/ in place: this run's snapshot holds all packages

next_phase "snapshots"
log "Creating snapshots $SNAP_NAME ..."
declare -A BY_POOL=()
for ds in "${PLAN_ZFS[@]}"; do BY_POOL[${ds%%/*}]+="$ds@$SNAP_NAME"$'\n'; done
for pool in "${!BY_POOL[@]}"; do
    mapfile -t args < <(printf '%s' "${BY_POOL[$pool]}" | sed '/^$/d')
    if zfs snapshot "${args[@]}" 2>>"$LOG_FILE"; then
        log "  ZFS $pool: ${#args[@]} datasets"
    else
        err "ZFS snapshot on pool '$pool' failed"; ZFS_FAILED[$pool]=1
    fi
done
SNAPS_TAKEN="yes"                                  # 2.80: ZFS's are taken (btrfs: BTRFS_TRIED, one base after the other)
if [[ "$PLAN_FLASH" == "snapshot" ]]; then
    zfs snapshot "$FLASH_DATASET@$SNAP_NAME" 2>>"$LOG_FILE" \
        || { err "Flash snapshot failed"; PLAN_FLASH="failed"; }
fi
array_stop_check "after the ZFS snapshots"          # a VM shut down is never started into a stopping array
[[ ${#VM_HELD[@]} -gt 0 ]] && vm_release          # the VMs on ZFS: their snapshot is taken
# btrfs in two parts: first the disks that hold data of what is held (stopped containers,
# held VMs, the backup place with the dumps) - consistency before speed; then everything
# starts again, and only then the disks nobody held anything on (media, a busy rsync)
btrfs_needed
for base in "${PLAN_BTRFS[@]}"; do [[ -n "${BTRFS_NEED[$base]:-}" ]] && btrfs_snap "$base"; done
[[ ${#VM_HELD[@]} -gt 0 ]] && vm_release btrfs

next_phase "starting"
log "Starting containers ..."
restore_service
array_stop_check "while the containers started"     # restore_service stops starting them then
DOWNTIME=$(( $(date +%s) - STOP_AT ))
status_write
log "Normal operation restored - downtime ${DOWNTIME} s"
array_stop_check "after the restart"
for base in "${PLAN_BTRFS[@]}"; do [[ -z "${BTRFS_NEED[$base]:-}" ]] && btrfs_snap "$base" "after the restart"; done
# the nights in a row each share was left out asleep (2.28): the UB_ASLEEP_NIGHTS-th warns once; a night it was
# snapshotted - every night with asleep_pools = wake - takes it out of state/asleep.json
asleep_nights "${ST_ASLEEP_SHARES[@]}"

# --- Partners (2.27): this run's snapshots to the partner offices, before Kopia's long upload ---
(( ${#ST_PARTNER_PLAN[@]} )) && partner_phase

# --- Mounting (only when Kopia really runs this time) -----------------------
if [[ "$KOPIA_OK" == "yes" && "$SKIPK" != "1" ]]; then
    next_phase "mounting"
    log "Mounting snapshots under $MOUNT_ROOT ..."
    mkdir -p "$MOUNT_ROOT"
    stage_ready || die "Private staging area $UB_STAGE cannot be created"
    MOUNTED="yes"
    # PLAN_MOUNT: the shares that go to Kopia and those holding parts of an app's or VM's own source
    for s in "${PLAN_MOUNT[@]}"; do
        if mount_share "$s"; then log "  $s (${SHARE_MOUNTED[$s]})"
        elif in_list "$s" "${PLAN_KOPIA[@]}"; then err "Share '$s' could not be mounted - it is not backed up"
        else warn "Share '$s' could not be mounted - the apps' and VMs' parts in it are left out"; fi
    done
    for it in "${PLAN_KITEMS[@]}"; do
        IFS='|' read -r it_t it_n it_f <<<"$it"
        if mount_item "$it_t" "$it_n" "$it_f"; then log "  .${it_t}s/$it_f ($it_t '$it_n': ${ITEM_PARTS[$it_t:$it_n]} part(s))"
        else err "The Kopia source of $it_t '$it_n' could not be put together - it is not backed up"; fi
    done
    if [[ "$PLAN_FLASH" == "snapshot" ]]; then
        if mkdir -p "$MOUNT_ROOT/$FLASH_SOURCE_NAME" && \
           mount -t zfs -o ro "$FLASH_DATASET@$SNAP_NAME" "$MOUNT_ROOT/$FLASH_SOURCE_NAME" 2>>"$LOG_FILE"; then :
        else err "Flash snapshot could not be mounted"; PLAN_FLASH="failed"; fi
    fi
fi
refresh_view

# --- Kopia ----------------------------------------------------------------
KOPIA_DONE=0; KOPIA_FAILED=0
KOPIA_STALLED=""            # 2.68: the source whose upload took nothing for UB_KOPIA_STALL seconds (ended) - Kopia not asked again
KOPIA_STALL_AT=0            #   the last moment it moved
KOPIA_LAST_OK=0             # 2.79: the last kopia_one's upload ended well (future_look then looks over what Kopia read)
kopia_one() { # kopia_one <name> <container path> [<snapshot create's tags: --tags k:v ...>]
    local name="$1" cp="$2" t0 rc secs stalled=0
    local -a tags=( "${@:3}" )
    KOPIA_LAST_OK=0
    t0="$(date +%s)"
    if [[ -n "$KOPIA_STALLED" ]]; then           # 2.68: the storage takes nothing - each upload would only wait as long
        log "Kopia: $name skipped - the offsite storage took nothing since $(date -d "@$KOPIA_STALL_AT" '+%H:%M')"
        kopia_skip "$name"; return 0
    fi
    log "Kopia: $name  ($cp)"
    ST_KOPIA_CUR="$name"; ST_KOPIA_CUR_T="$t0"; status_write
    # In the background + wait: a signal (abort) interrupts wait at once,
    # while bash waits for a foreground command until it ends -
    # with Kopia that can be hours. kopia_stop then ends it.
    # 2.24: waited for in steps of UB_ARRAY_LOOK seconds (a sleep beside it, wait -n for whichever ends
    # first), looking at var.ini each time - an array stop interrupts the upload at once (kopia_stop)
    KOPIA_CP="$cp"
    (( ${#tags[@]} )) && log_file "  tags: ${tags[*]}"
    UB_DOCKER_LONG=1 kopia_x snapshot create "$cp" --description "$UB_KOPIA_DESC $TS" "${tags[@]}" >>"$LOG_FILE" 2>&1 &     # hours: no docker limit (2.66)
    KOPIA_PID=$!
    # 2.68: no time limit, but a stall watchdog - the kopia process neither reading nor writing for UB_KOPIA_STALL seconds
    # (a storage that takes no writes: Kopia waits for ever) ends it (lib/common.sh kopia_stall_look/_end)
    kopia_stall_begin
    local w got
    while :; do
        array_stop_check "Kopia was uploading $name"
        sleep "$UB_ARRAY_LOOK" 9>&- &
        w=$!; got=""
        wait -n -p got "$KOPIA_PID" "$w"; rc=$?
        [[ "$got" == "$KOPIA_PID" ]] && break
        if [[ "$got" != "$w" ]]; then                    # neither (a bash without wait -p): the sleep's time
            wait "$w" 2>/dev/null
            kill -0 "$KOPIA_PID" 2>/dev/null || { wait "$KOPIA_PID"; rc=$?; break; }
        fi
        if kopia_stall_look "$cp"; then
            stalled=1
            log "  Kopia neither read nor wrote anything for $(dur_h $(( $(date +%s) - KS_AT ))) - the offsite storage takes nothing; the upload is ended"
            kopia_stall_end "$KOPIA_PID"
            wait "$KOPIA_PID" 2>/dev/null; rc=125
            break
        fi
    done
    kill "$w" 2>/dev/null; wait "$w" 2>/dev/null
    KOPIA_PID=""; KOPIA_CP=""
    # it ended badly just as the array stop began (Docker going): interrupted by the stop, not failed
    if (( rc != 0 )) && array_stopping; then ST_KOPIA_INTERRUPTED="$name"; array_stop_check "Kopia ended with exit code $rc"; fi
    secs=$(( $(date +%s) - t0 ))
    if (( stalled )); then
        # 2.68: not an error of this server - a warning in the office's words; the later uploads and Kopia steps don't wait
        KOPIA_STALLED="$name"; KOPIA_STALL_AT="$KS_AT"; KOPIA_FAILED=$((KOPIA_FAILED+1))
        kopia_silent_mark "$secs" snapshot
        kopia_watch_run stuck "$KS_AT"
        warn_code kopia_stalled "Kopia took nothing for $(( UB_KOPIA_STALL / 60 )) min - the upload of '$name' was ended; nothing more goes offsite in this run (the offsite storage takes no writes since $(date -d "@$KS_AT" '+%Y-%m-%d %H:%M'))" \
            name="$name" minutes="$(( UB_KOPIA_STALL / 60 ))" since="$(date -d "@$KS_AT" '+%Y-%m-%d %H:%M')"
    elif [[ $rc -eq 0 ]]; then KOPIA_DONE=$((KOPIA_DONE+1)); KOPIA_LAST_OK=1; log "  ok ($secs s)"; kopia_watch_run ok
    else KOPIA_FAILED=$((KOPIA_FAILED+1)); err "Kopia snapshot of '$name' failed (exit code $rc)"; fi
    ST_KOPIA_DONE+=( "$name|$([[ $rc -eq 0 ]] && echo 1 || echo 0)|$secs|$(date +%s)" )
    ST_KOPIA_CUR=""; status_write
}

kopia_skip() { # source not backed up, without Kopia having run
    KOPIA_FAILED=$((KOPIA_FAILED+1))
    ST_KOPIA_DONE+=( "$1|0|0|$(date +%s)" ); status_write
}

# kopia_item_one <kind> <name> <folder>  - an app's or a VM's own source
kopia_item_one() {
    local t="$1" n="$2" f="$3" key="$1:$2" cp mp c_mp missing sh split=""
    array_stop_check "before Kopia: $key"
    if [[ -n "${SKIP_KOPIA[$key]:-}" ]]; then err "Kopia: $t '$n' skipped - ${SKIP_KOPIA[$key]}"; kopia_skip "$key"; return 0; fi
    cp="$(k_path "$(item_hostpath "$t" "$f")")" || { err "Kopia: $t '$n' is not mapped into the container"; kopia_skip "$key"; return 0; }
    [[ -n "${ITEM_MPS[$key]:-}" ]] || { kopia_skip "$key"; return 0; }
    # never an empty folder: every part must be visible inside the container
    missing=""
    while IFS= read -r mp; do
        [[ -z "$mp" ]] && continue
        c_mp="$(k_path "$mp")"
        [[ -n "${KMI[$c_mp]+x}" ]] || missing+="$c_mp "
    done <<<"${ITEM_MPS[$key]}"
    if [[ -n "$missing" ]]; then
        err "Kopia does not see the mount: $missing- check that the mapping is 'Read Only - Slave'"
        kopia_skip "$key"; return 0
    fi
    # 2.80: the shares its parts lie in that are mounted split (<share>/<base>/<path> in its source) - said as a tag
    while IFS='|' read -r sh _; do [[ -n "$sh" && "${SHARE_MOUNTED[$sh]:-}" == split ]] && split+="$sh"$'\n'; done \
        < <(kopia_item_parts "$t" "$n" "$(item_pkg "$t" "$n")")
    kopia_item_tags "$split"
    kopia_one "$key" "$cp" "${KT_ARGS[@]}"
    if (( KOPIA_LAST_OK )); then future_look "$n" "$(item_hostpath "$t" "$f")"; fi     # 2.79: files dated in the future
}

# kopia_share_one <share>
kopia_share_one() {
    local s="$1" hp cp mp c_mp missing
    array_stop_check "before Kopia: $s"
    if [[ -n "${SKIP_KOPIA[$s]:-}" ]]; then err "Kopia: '$s' skipped - ${SKIP_KOPIA[$s]}"; kopia_skip "$s"; return 0; fi
    hp="$(share_kopia_hostpath "$s")"
    cp="$(k_path "$hp")" || { err "Kopia: '$s' is not mapped into the container"; kopia_skip "$s"; return 0; }
    if [[ -z "${SHARE_MOUNTED[$s]:-}" ]]; then kopia_skip "$s"; return 0; fi
    # Never back up an empty folder: the mount must be there inside the container
    missing=""
    while IFS= read -r mp; do
        [[ -z "$mp" ]] && continue
        c_mp="$(k_path "$mp")"
        [[ -n "${KMI[$c_mp]+x}" ]] || missing+="$c_mp "
    done < <(share_mount_points "$s")
    if [[ -n "$missing" ]]; then
        err "Kopia does not see the mount: $missing- check that the mapping is 'Read Only - Slave'"
        kopia_skip "$s"; return 0
    fi
    [[ "${SHARE_MOUNTED[$s]}" == "live" ]] && log "  (live: '$s' is read without a snapshot)"
    kopia_share_tags "$s" "${SHARE_MOUNTED[$s]}"           # 2.80: how it was put together (Mr. Restori needn't guess)
    kopia_one "$s" "$cp" "${KT_ARGS[@]}"
    if (( KOPIA_LAST_OK )); then future_look "$s" "$hp"; fi                               # 2.79: files dated in the future
}

# kopia_flash_one  - the flash's ZFS snapshot (only when /boot is on ZFS); its snapshot or mount failed: not backed up
kopia_flash_one() {
    local cp
    array_stop_check "before Kopia: flash"
    [[ "$PLAN_FLASH" == "snapshot" ]] || { kopia_skip "flash"; return 0; }
    cp="$(k_path "$MOUNT_ROOT/$FLASH_SOURCE_NAME")"
    [[ -n "${KMI[$cp]+x}" ]] || { err "Kopia does not see the flash snapshot ($cp)"; kopia_skip "flash"; return 0; }
    # 2.84: what never leaves the flash (lib/common.sh FLASH_NEVER) onto the source's policy first - a rule Kopia won't take
    # while such a file lies in the snapshot: no upload tonight (the archive leaves it out the same way, flash_tar)
    if [[ "$FLASH_NEVER_OK" != "yes" ]] && ! flash_policy_align "$cp"; then
        if flash_never_present "$MOUNT_ROOT/$FLASH_SOURCE_NAME"; then
            err_code flash_never_policy "Kopia: flash not uploaded - its policy would not take the rule that keeps ${FLASH_NEVER[0]} out, and the file lies in the snapshot (see $LOG_FILE)" \
                file="${FLASH_NEVER[0]}"
            kopia_skip "flash"; return 0
        fi
        warn "Kopia: the flash's policy would not take the rules for what never leaves the flash (nothing of it in the snapshot tonight - uploaded; 'setup.sh --kopia' sets them)"
    fi
    kopia_one "flash" "$cp"
}

if [[ "$SKIPK" == "1" ]]; then
    log "Kopia skipped (UB_SKIP_KOPIA=1)."
elif [[ "$KOPIA_OK" == "off" ]]; then
    log "Kopia switched off - local snapshots and dumps are done."
elif [[ "$KOPIA_OK" == "docker_off" ]]; then
    log "Kopia skipped - Docker is switched off (see the warning above): nothing went offsite; local snapshots, packages and partners are done."
elif [[ "$KOPIA_OK" == "held" ]]; then
    log "Kopia held - the repository is suddenly empty (see above): nothing went offsite; local snapshots, packages and partners are done."
elif [[ "$KOPIA_OK" == "yes" ]]; then
    next_phase "kopia"
    kopia_mountinfo_load
    # new folders of the shares going to Kopia stay local until the user decided (section 11)
    new_local_run
    # in the plan's order (kopia_order, 2.25): the flash, the apps, then the shares and VMs, the smallest first
    for ko in "${KOPIA_ORDER[@]}"; do
        IFS='|' read -r _ ko_kind ko_item ko_folder _ <<<"$ko"
        case "$ko_kind" in
            flash) kopia_flash_one ;;
            app|vm) kopia_item_one "$ko_kind" "$ko_item" "$ko_folder" ;;
            share) kopia_share_one "$ko_item" ;;
        esac
    done
    if [[ -z "$KOPIA_STALLED" ]]; then   # 2.68: a storage that takes nothing - Kopia not asked again in this run
        kopia_count_save run             # 2.53: what the next run (and the Night Watchman) compares with
        kopia_lock_save                  # 2.56: Object Lock and Kopia's maintenance (Mr. Backupsy's checks judge it)
    fi
    [[ $KOPIA_FAILED -gt 0 ]] && ub_tell kopia_incomplete warning "Log: $LOG_FILE" "Not everything went offsite" \
        "$KOPIA_FAILED source(s) not backed up offsite, $KOPIA_DONE done. Mr. Backupsy's page shows the details." "n=$KOPIA_FAILED" "done=$KOPIA_DONE"
fi

# --- Unmounting and cleaning up --------------------------------------------
if [[ "$KEEP_MOUNTS" != "yes" ]]; then
    next_phase "unmounting"
    unmount_all || warn "Not all snapshot mounts could be released"
fi

next_phase "cleanup"                 # never prunes while the array is being stopped
log "Cleaning up ..."
if command -v zfs >/dev/null 2>&1; then
    if (( ${#NOT_LOOKED[@]} )); then
        # asleep_pools = skip (2.28): the snapshots of the pools asleep (left out, or asleep and not in the plan) are not
        # looked at - their retention waits for a night they are awake
        OWNERS=()
        for pool in $(for ds in "${!ZDS_MP[@]}"; do printf '%s\n' "${ds%%/*}"; done | LC_ALL=C sort -u); do
            if [[ -n "${NOT_LOOKED[$pool]:-}" ]]; then log "  ZFS $pool: asleep - its snapshots wait for a night it is awake"; continue; fi
            mapfile -t -O "${#OWNERS[@]}" OWNERS < <(zfs list -H -t snapshot -o name -r "$pool" 2>/dev/null | snap_filter | sed 's/@.*//' | sort -u)
        done
    else
        mapfile -t OWNERS < <(zfs list -H -t snapshot -o name 2>/dev/null | snap_filter | sed 's/@.*//' | sort -u)
    fi
    for ds in "${OWNERS[@]}"; do
        # 2.48: a partner's copy here (the door's retention keeps it) and the storeroom are never thinned by this one
        ub_prune_own "$ds" || continue
        array_stop_check "while pruning"
        prune_zfs "$ds" "${PLAN_ZFS_RET[$ds]:-$ZFS_RETENTION}"
    done
fi
array_stop_check "while pruning"
command -v btrfs >/dev/null 2>&1 && prune_btrfs
pruned_write                         # what the retention removed, for whoever watches the server (state/pruned.json)
PRUNED_DONE=$(( ${#PRUNED_ZFS[@]} + ${#PRUNED_BTRFS[@]} ))   # an array stop from here on says what pruned.json says
PRUNED_ZFS=(); PRUNED_BTRFS=()       # written (an array stop from here on adds nothing twice)
array_stop_check "while cleaning up"
prune_files

# --- Finishing --------------------------------------------------------------
TOTAL=$(( $(date +%s) - STARTED_AT ))
ST_DUMP_BYTES="$PKG_WRITTEN_BYTES"; is_uint "$ST_DUMP_BYTES" || ST_DUMP_BYTES=0     # what this run wrote into the packages
RUN_SIZE="$(human "$ST_DUMP_BYTES")"
{
    echo "ts=$TS"
    echo "duration_s=$TOTAL"
    echo "downtime_s=$DOWNTIME"
    echo "errors=$ERRORS"
    echo "warnings=$WARNINGS"
    echo "kopia_ok=$KOPIA_DONE"
    echo "kopia_failed=$KOPIA_FAILED"
    echo "partner_ok=${#ST_PARTNER_DONE[@]}"
    echo "partner_failed=${#ST_PARTNER_FAILED[@]}"
    echo "partner_skipped=${#ST_PARTNER_SKIPPED[@]}"
    echo "asleep=${#ST_ASLEEP_SHARES[@]}"
    echo "drift=$(drift_count warn)/$(drift_count error)"
    echo "log=$LOG_FILE"
} >"$UB_STATE/last-run"

if is_yes "$KOPIA_ENABLED"; then KSUM="Kopia ${KOPIA_DONE} ok/${KOPIA_FAILED} failed"; else KSUM="Kopia off"; fi
# The notification's long text (mail-layout, 2026-10-09): a headline - the result and the times that matter -, errors
# and warnings under it, then a section per part of the run (an UPPERCASE title, one item per line, indented); Kopia's
# list trimmed to every failed source and the 4 longest (the full list stays in the log); word lists wrapped
# run_report <headline>
run_report() {
    local l n p d secs f i sz lines=() items=()
    echo "$1 in $(dur_n "$TOTAL"), apps stopped $(dur_n "$DOWNTIME")"
    echo "Errors $ERRORS, warnings $WARNINGS"
    [[ -n "${UB_ITEMS_LINES:-}" ]] && printf '%s\n' "$UB_ITEMS_LINES"     # 2.83: each in the page's words (ub_run_items)
    # SNAPSHOTS
    items=()
    (( ${#PLAN_ZFS[@]} )) && items+=( "${#PLAN_ZFS[@]} ZFS dataset$( (( ${#PLAN_ZFS[@]} == 1 )) || echo s)" )
    (( ${#BTRFS_OK[@]} )) && items+=( "${#BTRFS_OK[@]} btrfs disk$( (( ${#BTRFS_OK[@]} == 1 )) || echo s)" )
    [[ "$PLAN_FLASH" == "snapshot" ]] && items+=( "flash" )
    echo; echo "SNAPSHOTS"
    if (( ${#items[@]} )); then wrap_words "  " ", " "${items[@]}"; else echo "  none"; fi
    # DATABASES: the dumps (<container> (<engine>), the size), the media servers' SQLite copies per container and state
    lines=()
    local ct eng db w1=0 w2=0 lbl
    local -A per=()
    for l in "${DUMPS_DONE[@]}"; do IFS='|' read -r f _ ct <<<"$l"; [[ -n "$ct" ]] && per[$ct]=$(( ${per[$ct]:-0} + 1 )); done
    local -a dl=() ds=()
    for l in "${DUMPS_DONE[@]}"; do
        IFS='|' read -r f sz ct <<<"$l"
        case "$f" in
            mariadb_*)  eng="MariaDB"; db="${f#mariadb_"$ct"_}"; db="${db%.sql.gz}" ;;
            postgres_*) eng="Postgres"; db="" ;;
            mongodb_*)  eng="MongoDB"; db="" ;;
            *)          eng=""; db="" ;;
        esac
        if [[ -n "$ct" && -n "$eng" ]]; then
            lbl="$ct ($eng)"; [[ -n "$db" && "${per[$ct]:-0}" -gt 1 ]] && lbl="$ct ($eng: $db)"
        else lbl="$f"; fi
        dl+=( "$lbl" ); ds+=( "$(human_sp "$sz")" )
        (( ${#lbl} > w1 )) && w1=${#lbl}; (( ${#ds[-1]} > w2 )) && w2=${#ds[-1]}
    done
    for i in "${!dl[@]}"; do lines+=( "$(printf '  %-*s   %*s' "$w1" "${dl[$i]}" "$w2" "${ds[$i]}")" ); done
    local -A sq=()
    local k
    # a database file's name may hold spaces or * ? [ ] (2.48: read line by line, never split or globbed)
    while IFS= read -r k; do
        [[ -n "$k" ]] || continue
        ct="${k%%|*}"; f="${k#*|}"; f="${f#sqlite_"$ct"_}"; f="${f%.db}"
        sq[$ct|${SQ_STATE[$k]}]+="${sq[$ct|${SQ_STATE[$k]}]:+, }$f"
    done < <(printf '%s\n' "${!SQ_STATE[@]}" | LC_ALL=C sort)
    while IFS= read -r k; do
        [[ -n "$k" ]] && lines+=( "  ${k%%|*}: ${sq[$k]}   ${k#*|}" )
    done < <(printf '%s\n' "${!sq[@]}" | LC_ALL=C sort)
    if (( ${#lines[@]} )); then echo; echo "DATABASES"; printf '%s\n' "${lines[@]}"; fi
    # PACKAGES
    echo; echo "PACKAGES"
    echo "  ${#PKG_APPS[@]} app$( (( ${#PKG_APPS[@]} == 1 )) || echo s), ${#PKG_VMS[@]} VM$( (( ${#PKG_VMS[@]} == 1 )) || echo s) in $UB_DUMPS"
    (( PKG_OLD_RUNS > 0 )) && [[ "$PKG_OLD_ACTION" == "removed" ]] && echo "  ${PKG_OLD_RUNS} old run folders cleared away"
    # VMS: what each held VM went through, and for how long
    lines=(); w1=0
    local -a vn=() vt=()
    for l in "${ST_VMS[@]}"; do
        IFS='|' read -r n p d secs _ <<<"$l"
        case "$d" in
            off|kept_running|not_running|planned) continue ;;
            shutdown) d="shut down for $(dur_n "$secs")" ;;
            paused)   case "$p" in
                          shutdown) d="did not shut down - paused for $(dur_n "$secs")" ;;
                          freeze)   d="not frozen - paused for $(dur_n "$secs")" ;;
                          *)        d="paused for $(dur_n "$secs")" ;;
                      esac ;;
            frozen)   d="frozen for $(dur_n "$secs")" ;;
            failed)   d="could not be paused - kept running" ;;
            *)        d="$d for $(dur_n "$secs")" ;;
        esac
        vn+=( "$n" ); vt+=( "$d" ); (( ${#n} > w1 )) && w1=${#n}
    done
    if (( ${#vn[@]} )); then
        echo; echo "VMS"
        for i in "${!vn[@]}"; do printf '  %-*s   %s\n' "$w1" "${vn[$i]}" "${vt[$i]}"; done
    fi
    # KOPIA: every failed source, then the 4 longest, then how many more
    (( ${#ST_KOPIA_DONE[@]} )) && { echo; kopia_report "$KOPIA_DONE" "$KOPIA_FAILED" "${ST_KOPIA_DONE[@]}"; }
    # TO <partner>: what went, how much and how fast; what didn't, and why
    local id b secs2 nb why
    for l in "${ST_PARTNER_IDS[@]}"; do
        id="${l%%|*}"; items=(); nb=0; secs2=0; lines=()
        for p in "${ST_PARTNER_DONE[@]}"; do
            IFS='|' read -r n d _ _ b secs _ <<<"$p"
            [[ "$n" == "$id" ]] || continue
            items+=( "${d#*:}" ); nb=$(( nb + b )); secs2=$(( secs2 + secs ))
        done
        for p in "${ST_PARTNER_SKIPPED[@]}" "${ST_PARTNER_FAILED[@]}"; do
            IFS='|' read -r n d why <<<"$p"
            [[ "$n" == "$id" ]] && lines+=( "  ${d#*:}   not sent ($(partner_why_text "$why"))" )
        done
        echo
        if (( ${#items[@]} )); then
            echo "TO $(partner_name "$id")   ${#items[@]} sent, $(human_sp "$nb"), $(partner_mbit "$nb" "$secs2") Mbit/s"
            wrap_words "  " ", " "${items[@]}"
        else
            echo "TO $(partner_name "$id")   nothing sent"
        fi
        (( ${#lines[@]} )) && printf '%s\n' "${lines[@]}"
    done
    # LEFT OUT, ASLEEP: the pools and disks asleep, their shares, the VMs not held and the apps kept running for it
    if (( ${#ST_ASLEEP_POOLS[@]} )); then
        echo; echo "LEFT OUT, ASLEEP (asleep_pools = skip)"
        wrap_words "  Disks:   " " " "${ST_ASLEEP_POOLS[@]}"
        if (( ${#ST_ASLEEP_SHARES[@]} )); then wrap_words "  Shares:  " ", " "${ST_ASLEEP_SHARES[@]}"; else echo "  Shares:  none"; fi
        (( ${#ST_ASLEEP_VMS[@]} )) && wrap_words "  VMs:     " " " "${ST_ASLEEP_VMS[@]}" "(not held)"
        (( ${#ST_ASLEEP_CTS[@]} )) && wrap_words "  Apps:    " " " "${ST_ASLEEP_CTS[@]}" "(kept running)"
    fi
    items=()
    for l in "${NEW_LIST[@]}"; do IFS=$'\x1f' read -r n p _ <<<"$l"; items+=( "$n/$p" ); done
    if (( ${#items[@]} )); then echo; echo "NEW, ONLY LOCAL UNTIL YOU DECIDE"; wrap_words "  " ", " "${items[@]}"; fi
    if (( ${#T_NEW[@]} )); then echo; echo "NEW CONTAINERS, KEPT RUNNING UNTIL YOU DECIDE"; wrap_words "  " ", " "${T_NEW[@]}"; fi
    if (( ${#T_PAUSED[@]} )); then echo; echo "PAUSED BY YOU, LEFT AS THEY WERE (still paused)"; wrap_words "  " ", " "${T_PAUSED[@]}"; fi
    echo
    echo "Log: $LOG_FILE"
}
SUMMARY="duration ${TOTAL}s, downtime ${DOWNTIME}s, ${KSUM}, packages ${RUN_SIZE}"
(( ${#ST_PARTNER_PLAN[@]} )) && SUMMARY+=", partners ${#ST_PARTNER_DONE[@]} sent/${#ST_PARTNER_FAILED[@]} failed/${#ST_PARTNER_SKIPPED[@]} skipped"
(( ${#ST_ASLEEP_SHARES[@]} )) && SUMMARY+=", ${#ST_ASLEEP_SHARES[@]} share$( (( ${#ST_ASLEEP_SHARES[@]} == 1 )) || echo s) asleep (left out)"
# the same for people, in the notification's description (the bell's line, the mail's header); the log keeps SUMMARY
NSUMMARY="$(dur_n "$TOTAL"), apps stopped $(dur_n "$DOWNTIME"), ${KSUM}, packages $(human_sp "$ST_DUMP_BYTES")"
(( ${#ST_PARTNER_PLAN[@]} )) && NSUMMARY+=", partners ${#ST_PARTNER_DONE[@]} sent/${#ST_PARTNER_FAILED[@]} failed/${#ST_PARTNER_SKIPPED[@]} skipped"
(( ${#ST_ASLEEP_SHARES[@]} )) && NSUMMARY+=", ${#ST_ASLEEP_SHARES[@]} share$( (( ${#ST_ASLEEP_SHARES[@]} == 1 )) || echo s) asleep (left out)"
# NSUMMARY in the office's language (ub_tell; English without the language files)
ub_summary() {
    local k t p
    if is_yes "$KOPIA_ENABLED"; then k="$(ub_say notify.sum_kopia "done=$KOPIA_DONE" "failed=$KOPIA_FAILED")"; else k="$(ub_say notify.sum_kopia_off)"; fi \
        || { printf '%s' "$NSUMMARY"; return 0; }
    t="$(ub_say notify.sum "total=$(dur_n "$TOTAL")" "downtime=$(dur_n "$DOWNTIME")" "kopia=$k" "size=$(human_sp "$ST_DUMP_BYTES")")" \
        || { printf '%s' "$NSUMMARY"; return 0; }
    if (( ${#ST_PARTNER_PLAN[@]} )) && p="$(ub_say notify.sum_partners "sent=${#ST_PARTNER_DONE[@]}" "failed=${#ST_PARTNER_FAILED[@]}" "skipped=${#ST_PARTNER_SKIPPED[@]}")"; then t+=", $p"; fi
    if (( ${#ST_ASLEEP_SHARES[@]} )) && p="$(ub_say notify.sum_asleep "n=${#ST_ASLEEP_SHARES[@]}")"; then t+=", $p"; fi
    printf '%s' "$t"
}
if (( ERRORS > 0 )); then status_finish errors
elif (( WARNINGS > 0 )); then status_finish warnings
else status_finish ok; fi
# 2.83: the coded warnings and errors named - in the description «{counts}: {items}. {summary}», in the long text in full
UB_ITEMS_DESC=""; UB_ITEMS_LINES=""
(( ERRORS > 0 || WARNINGS > 0 )) && ub_run_items
NTEXT=""; [[ -n "$UB_ITEMS_DESC" ]] && NTEXT="_items"
if (( ERRORS > 0 )); then
    log "Backup finished with ${ERRORS} error(s) and ${WARNINGS} warning(s). $SUMMARY"
    UB_TELL_TEXT="notify.errors$NTEXT" ub_tell errors alert "$(run_report "Backup finished with errors")" "Backup with errors" "${ERRORS} errors, ${WARNINGS} warnings. $NSUMMARY" \
        "counts=$(ub_counts "$ERRORS" "$WARNINGS")" "items=$UB_ITEMS_DESC" "summary=$(ub_summary)"
    exit 1
elif (( WARNINGS > 0 )); then
    log "Backup finished with ${WARNINGS} warning(s). $SUMMARY"
    UB_TELL_TEXT="notify.warnings$NTEXT" ub_tell warnings warning "$(run_report "Backup finished with warnings")" "Backup with warnings" "${WARNINGS} warnings. $NSUMMARY" \
        "counts=$(ub_counts "" "$WARNINGS")" "items=$UB_ITEMS_DESC" "summary=$(ub_summary)"
else
    log "Backup successful. $SUMMARY"
    # 2.66: with the Team Lead's notification centre (its notify.cfg in RAM) his «auch Erfolge» decides, not notify_success
    if is_yes "$NOTIFY_SUCCESS" || ub_centre_decides; then
        ub_tell ok normal "$(run_report "Backup successful")" "Backup successful" "$NSUMMARY" "summary=$(ub_summary)"
    fi
fi
exit 0
}
