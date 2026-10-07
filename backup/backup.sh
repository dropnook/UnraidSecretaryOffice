#!/bin/bash
###############################################################################
# unraid-backup - backup.sh                       Version 2.25 - 2026-10-07
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
#        last) - a first upload of terabytes no longer holds back everything behind it for days. At the
#        array stop the plugin runs --unmount with UB_KEEP_LATEST=1: latest.log stays the last run's
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
        --dry-run)  UB_DRY_RUN=1 ;;
        --no-kopia) UB_SKIP_KOPIA=1 ;;
        --about)    jq -nc --arg n "$UB_NAME" --arg v "$UB_VERSION" --argjson i "$UB_INTERFACE" --arg c "$UB_DIR" --arg d "$UB_DATA" \
                        '{name: $n, version: $v, interface: $i, code: $c, data: $d}'; exit 0 ;;
        -h|--help)  usage; exit 0 ;;
        *) echo "Unknown option: $a  (see --help)"; exit 2 ;;
    esac
done
UB_MODE="${UB_MODE:-backup}"
DRY="${UB_DRY_RUN:-0}"
SKIPK="${UB_SKIP_KOPIA:-0}"
case "$UB_MODE" in backup|check|unmount|recover) ;; *) echo "UB_MODE=$UB_MODE is unknown"; exit 2 ;; esac

[[ $EUID -eq 0 ]] || { echo "Please run as root."; exit 1; }
# --recover (2.25): no note of an interrupted run - nothing to do, nothing written; the array being
# stopped - not now (the notes stay for after the array start), exit 3 like a run the stop ends
if [[ "$UB_MODE" == "recover" ]]; then
    recover_notes || exit 0
    array_stopping && { echo "The array is being stopped - nothing is started now; the notes stay for after the array start."; exit 3; }
fi
ub_data_dirs || { echo "Cannot create folders in $UB_DATA"; exit 1; }

TS="$(date +%Y%m%d-%H%M)"
STARTED_AT="$(date +%s)"
case "$UB_MODE" in
    backup)  LOG_FILE="$UB_LOGS/run-$TS.log" ;;
    check)   LOG_FILE="$UB_LOGS/check-$TS.log" ;;
    unmount) LOG_FILE="$UB_LOGS/unmount.log" ;;
    recover) LOG_FILE="$UB_LOGS/recover.log" ;;
esac
[[ "$DRY" == "1" && "$UB_MODE" == "backup" ]] && LOG_FILE="$UB_LOGS/dryrun-$TS.log"
# latest.log points at this run's log only once it has the lock (Start, at the end)

STOPPED=()                  # containers actually stopped
declare -A NC_ON=()         # Nextclouds whose maintenance mode WE switched on
declare -A NC_OCC=() NC_USER=()
declare -A NC_SAME=()       # container -> first container of the same Nextcloud instance
declare -A BTRFS_OK=()     # btrfs base -> snapshot path
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
ARRAY_STOP="no"             # the array is being stopped: the run ends at once, starts nothing (2.24, array_stop_check)
CLEANUP_ARMED="no"          # the cleanup trap is set (from then on an array stop ends the run through it)
UB_ARRAY_LOOK="${UB_ARRAY_LOOK:-5}"   # seconds between looks at var.ini while Kopia uploads
UB_RECOVER_WAIT="${UB_RECOVER_WAIT:-300}"            # --recover: at most so long for Docker and libvirt to answer (2.25)
UB_RECOVER_LOOK="${UB_RECOVER_LOOK:-5}"              #   asking every so many seconds
UB_RECOVER_LOCK_WAIT="${UB_RECOVER_LOCK_WAIT:-900}"  # a run that finds a --recover holding the lock waits so long for it

dur_h() { local s="${1:-0}"; if (( s >= 60 )); then printf '%d min %d s' $(( s / 60 )) $(( s % 60 )); else printf '%d s' "$s"; fi; }

die() {
    # a failure while the array is being stopped is that stop (Docker gone, a pool going): ended as such
    [[ "$CLEANUP_ARMED" == "yes" && "$CLEANUP_DONE" != "yes" ]] && array_stopping && { log "  ($*)"; array_stop_abort; }
    err "$*"
    status_finish failed "$*"
    ub_notify "Backup FAILED" "$*" "alert" "Log: $LOG_FILE"
    exit 1
}
# die_code <code> <text>: like die, but status.json carries the code - the office translates it
die_code() {
    local code="$1"; shift
    [[ "$CLEANUP_ARMED" == "yes" && "$CLEANUP_DONE" != "yes" ]] && array_stopping && { log "  ($*)"; array_stop_abort; }
    err "$*"
    status_finish failed "$code"
    ub_notify "Backup FAILED" "$*" "alert" "Log: $LOG_FILE"
    exit 1
}

##############################################################################
# Stopping and starting containers
##############################################################################
T_APP=(); T_DB=(); T_NET=(); T_NEW=()

build_stop_tiers() {
    local n prov
    local -A provider=() isdb=()
    T_APP=(); T_DB=(); T_NET=(); T_NEW=()
    [[ "$DOCKER_STOP" == "none" ]] && return 0
    for n in "${CT_NAMES[@]}"; do
        [[ "${CT_NET[$n]}" == container:* ]] || continue
        prov="$(ct_resolve "${CT_NET[$n]#container:}")" && provider[$prov]=1
    done
    for n in $(cfg_names dump); do isdb[$n]=1; done
    for n in "${CT_NAMES[@]}"; do
        [[ "${CT_RUNNING[$n]}" == "true" ]] || continue
        [[ "$n" == "$KOPIA_CONTAINER" ]] && continue
        in_list "$n" "${DOCKER_NO_STOP[@]}" "${DOCKER_SKIP[@]}" && continue
        # a container the setup hasn't seen ([docker] known) keeps running until the user decided (2.21)
        if (( ${#DOCKER_KNOWN[@]} )) && ! in_list "$n" "${DOCKER_KNOWN[@]}"; then T_NEW+=( "$n" ); continue; fi
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
declare -A VM_SHUT_ASKED=()  # name -> 1: the shutdown request went through (vm_hold_begin)
declare -A VM_SHUT_DOWN=()   # name -> 1: seen shut off (vm_shutdown_wait, vm_hold)

vm_plan() {
    local n p
    VM_TODO=(); ST_VMS=()
    for n in "${VM_NAMES[@]}"; do
        p="$(vm_prepare "$n")"
        if [[ "$(vm_mode "$n")" == "off" ]]; then ST_VMS+=( "$n|$p|off|0|0" ); continue; fi
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
        if timeout 30 virsh shutdown "$n" >/dev/null 2>>"$LOG_FILE"; then log "  VM '$n': shutting down"; VM_SHUT_ASKED[$n]=1
        else warn "VM '$n' did not take the shutdown request - it keeps running and is paused for the snapshot instead"; fi
    done
}

# vm_shutdown_wait [abort]
# The shutdowns were all requested together (vm_hold_begin), so they share one deadline: each VM
# its request time + VM_SHUTDOWN_TIMEOUT, all polled in one loop - at most one timeout in all,
# not one after the other. A VM that didn't take the request isn't waited for. While waiting, a VM
# still running gets the request again every VM_SHUTDOWN_RETRY seconds: Windows swallows the first
# ACPI power button event while idle with the display off (harmless while a guest shuts down).
# One not off by its deadline keeps running until vm_hold pauses it (never forced off).
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
                    || warn "VM '$n' did not shut down within ${VM_SHUTDOWN_TIMEOUT} s of the request - it keeps running and is paused for the snapshot instead"
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
            # until now - held from the pause on
            unset "VM_HELD_AT[$n]"; p="pause"
        fi
        if [[ "$p" == "freeze" ]]; then
            VM_HELD[$n]="frozen"; VM_HELD_AT[$n]="$(date +%s)"; save_restore_state
            if timeout 60 virsh domfsfreeze "$n" >/dev/null 2>>"$LOG_FILE"; then log "  VM '$n': file systems frozen"; continue; fi
            warn "VM '$n': the guest agent did not freeze its file systems - paused instead"
            unset "VM_HELD[$n]"; p="pause"
        fi
        if [[ "$p" == "pause" ]]; then
            VM_HELD[$n]="paused"; VM_HELD_AT[$n]="${VM_HELD_AT[$n]:-$(date +%s)}"; save_restore_state
            if timeout 30 virsh suspend "$n" >/dev/null 2>>"$LOG_FILE"; then log "  VM '$n': paused"
            else
                warn "VM '$n' could not be paused - it keeps running (crash-consistent)"
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
            frozen)   timeout 60 virsh domfsthaw "$n" >/dev/null 2>>"$LOG_FILE" || warn "VM '$n': thawing its file systems failed - check the VM" ;;
            paused)   timeout 30 virsh resume "$n" >/dev/null 2>>"$LOG_FILE" \
                          || { warn "VM '$n' could NOT be resumed"; ub_notify "VM not resumed" "'$n' is still paused after the snapshot: virsh resume $n" "alert"; } ;;
            shutdown) st="$(vm_domstate "$n")"
                      if [[ "$st" != "shut off" ]]; then
                          # only when a run stops before vm_hold: it never went down (or not by its deadline)
                          log "  VM '$n': not shut down (${st:-state unknown}) - nothing to start"
                          unset "VM_HELD[$n]" "VM_HELD_AT[$n]"; continue
                      fi
                      timeout 60 virsh start "$n" >/dev/null 2>>"$LOG_FILE" \
                          || { warn "VM '$n' could NOT be started"; ub_notify "VM not started" "'$n' could not be started after the snapshot." "alert"; } ;;
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
    mkdir -p "$base/$BTRFS_SNAP_DIR"
    if btrfs subvolume snapshot -r "$base" "$base/$BTRFS_SNAP_DIR/$TS" >/dev/null 2>>"$LOG_FILE"; then
        BTRFS_OK[$base]="$base/$BTRFS_SNAP_DIR/$TS"
        log "  btrfs $base${2:+ ($2)}"
    else
        err "btrfs snapshot of $base failed"
    fi
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
    local tier n started c i out
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
                    ub_notify "Container not started" "'$n' could not be started after the snapshot." "alert"
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
            nc_occ "$c" status >/dev/null 2>&1 && break
            array_stopping && break
            sleep 2
        done
        if out="$(nc_occ "$c" maintenance:mode --off 2>&1)"; then
            log "  Nextcloud '$c': maintenance mode off"
            unset "NC_ON[$c]"
            save_restore_state
        elif array_stopping; then
            ARRAY_STOP="yes"
            log "  Nextcloud '$c': maintenance mode stays on (the array is being stopped) - noted (state/maintenance) for after the array start"
        else
            nc_log_output "$out"
            warn "Nextcloud '$c': maintenance mode would NOT switch off"
            ub_notify "Maintenance mode stuck" "Nextcloud '$c' is still in maintenance mode: occ maintenance:mode --off" "alert"
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

nextcloud_maintenance_on() {
    local c was pre id out try on
    local -A inst=()           # instanceid -> first container of this instance
    while IFS= read -r c; do
        [[ -z "$c" ]] && continue
        if [[ "$(docker inspect -f '{{.State.Running}}' "$c" 2>/dev/null)" != "true" ]]; then
            warn "Nextcloud '$c' is not running - no maintenance mode"; continue
        fi
        nc_find_occ "$c" || { warn "Nextcloud '$c': occ not found - no maintenance mode"; continue; }
        # Several containers can serve ONE Nextcloud (e.g. app and cron
        # with a shared config.php). The maintenance mode lives in that
        # config.php - after the first container the second would find it
        # "already on". The instanceid from config.php spots such siblings;
        # the first one's setting applies.
        id="$(nc_occ "$c" config:system:get instanceid 2>/dev/null | tr -d '\r')"
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
            ub_notify "Maintenance mode was already on" "Nextcloud '$c' was backed up anyway and stays in maintenance mode." "warning"
            continue
        fi
        # Note it before switching on: if occ sets the mode and only fails
        # afterwards, the abort still switches it off again. Another attempt
        # after such a half success reports "already enabled" with 0.
        NC_ON[$c]=1; save_restore_state
        on=0
        for try in 1 2 3; do
            if out="$(nc_occ "$c" maintenance:mode --on 2>&1)"; then on=1; break; fi
            nc_log_output "$out"
            (( try < 3 )) && { warn "Nextcloud '$c': maintenance mode not switched on (attempt $try/3) - trying again in 5 s"; sleep 5; }
        done
        if (( on )); then
            log "  Nextcloud '$c': maintenance mode on"
        else
            # Keep noted only what is really on - otherwise the abort
            # reports a stuck maintenance mode that does not exist.
            [[ "$(nc_occ "$c" config:system:get maintenance 2>/dev/null | tr -d '\r')" == "true" ]] \
                || { unset "NC_ON[$c]"; save_restore_state; }
            # the most common cause: Unraid reset the data folder (a share root) to 0777 when share settings were saved
            if grep -q "readable by other people" <<<"$out"; then
                die_code nc_datadir_readable "Nextcloud '$c': the data directory is readable by others, occ refuses to work. Unraid sets the root of a share to 0777 nobody:users when share settings are saved, unless it belongs to the group users. Lasting fix: chown 33:100 and chmod 0750 on the data directory (www-data:users), then start again."
            fi
            die "Nextcloud '$c': maintenance mode could not be switched on (occ's message is in the log)"
        fi
    done < <(cfg_names nextcloud)
    [[ ${#NC_ON[@]} -gt 0 ]] && sleep "${UB_NC_SETTLE:-5}"      # let running requests finish (tests: shorter)
    return 0
}

##############################################################################
# Database dumps
##############################################################################
# Password via MYSQL_PWD instead of -p: that way it does not show in the process list.
my_exec() { # my_exec <container> <bin> <login> <pw variable> <args...>
    local c="$1" bin="$2" login="$3" pwv="$4"; shift 4
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
declare -a DUMPS_DONE=()         # "<file>|bytes" written this run
dump_done() { # dump_done <container> <file>
    local size; size="$(stat -c %s "$2" 2>/dev/null)"
    DUMP_FILE_CT[$DUMP_KEY/db/${2##*/}]="$1"; DUMPS_DONE+=( "${2##*/}|${size:-0}" )
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

    if [[ -n "$single" ]]; then dbs="$single"
    else
        dbs="$(my_exec "$c" "$sql_bin" "$login" "$pwv" -N -B -e \
            "SELECT schema_name FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema','performance_schema','sys','mysql')" 2>/dev/null)"
    fi
    [[ -n "$dbs" ]] || { err "MariaDB '$c': no database found"; return 1; }

    local rc=0
    for db in $dbs; do
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
    done
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
    local c t f rc
    while IFS= read -r c; do
        [[ -z "$c" ]] && continue
        array_stop_check "before the dump of $c"
        t="$(cfg "dump|$c|type")"
        f="${PKG_APP_OF[$c]:-}"
        if [[ "$(docker inspect -f '{{.State.Running}}' "$c" 2>/dev/null)" != "true" ]]; then
            warn "Database container '$c' is not running - no dump (the package keeps its last one)"
            [[ -n "$f" ]] && pkg_mark apps "$f" warnings
            continue
        fi
        [[ -n "$f" ]] || { warn "Database container '$c' belongs to no package - no dump"; continue; }
        DUMP_KEY="apps/$f"; DUMP_DIR="$PKG_STAGE/$DUMP_KEY/db"
        mkdir -p "$DUMP_DIR" || { err "Cannot create $DUMP_DIR"; DUMP_STATE[$c]="failed"; pkg_mark apps "$f" errors; continue; }
        case "$t" in
            mariadb)  dump_mariadb "$c" ;;
            postgres) dump_postgres "$c" ;;
            mongodb)  dump_mongodb "$c" ;;
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
# server uses (so locks and the WAL index are shared), checked with PRAGMA quick_check. Made while
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
        [[ "${CT_RUNNING[$c]}" == "true" ]] || continue
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
            r="$(timeout "$SQ_TIMEOUT" sqlite3 "file:$(uri_escape "$out")?mode=ro" 'PRAGMA quick_check;' 2>&1 | head -3)"
            rm -f "$out-wal" "$out-shm" "$out-journal"
            if [[ "$r" == "ok" ]]; then SQ_CHECK[$key]="ok"
            elif grep -qiE 'no such (collation|module|function|tokenizer)' <<<"$r"; then
                SQ_CHECK[$key]="unchecked"; log "    (Unraid's sqlite3 can't check it - the server's own SQLite extensions: $r)"
            else
                SQ_STATE[$key]="failed"; rm -f "$out"
                warn "SQLite copy of '$c' ${p##*/} fails its check ($r) - the package keeps its last copy"; pkg_mark apps "$f" warnings; continue
            fi
            SQ_STATE[$key]="ok"; DUMP_FILE_CT[apps/$f/db/$file]="$c"
            sz="$(stat -c %s "$out" 2>/dev/null)"
            log "    OK: $(human "${sz:-0}")$([[ "${SQ_CHECK[$key]}" == "ok" ]] && echo ', checked')"
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
        docker ps -a --format '{{.Names}}\t{{.Image}}' | sort
    } >"$S/manifest.txt"
    local -a ids=(); mapfile -t ids < <(docker ps -aq 2>/dev/null)
    if (( ${#ids[@]} )); then
        docker inspect --format '{{.Name}}  {{.Config.Image}}  {{.Image}}' "${ids[@]}" >"$S/docker-images.txt" 2>&1
    else
        : >"$S/docker-images.txt"
    fi
    docker ps -a --format '{{.Names}}\t{{.Image}}\t{{.Status}}' >"$S/docker-ps.txt" 2>&1
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
    local f="$1" n="${PKG_VM_NAME[$1]}" V="$PKG_STAGE/vms/$1" Q=/etc/libvirt/qemu uuid nv x disks auto=false
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
            sz=""; [[ -f "$pth" ]] && sz="$(stat -c %s "$pth" 2>/dev/null)"
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
            --argjson sq "${sq:-[]}" --argjson own "$own" '
            ($img[0] // []) as $images |
            {interface: 1, engine: $engine, kind: "app", type: $type, name: $name, folder: $folder, run: $run, time: $time,
             host: $host, result: $result,
             compose: (if $type == "compose" then {project: $name, manager_dir: (if $cm == "" then null else ($cm | split("/") | last) end),
                       manager_path: (if $cm == "" then null else $cm end), working_dir: $wd,
                       config_files: ($cfgs | split(",") | map(select(length > 0)))} else null end),
             containers: [($ins[0] // [])[] | . as $c | (.Name | ltrimstr("/")) as $n | {name: $n, image: .Config.Image, image_id: .Image,
                 digests: (first($images[] | select(.id == $c.Image) | .digests) // []), running: .State.Running,
                 service: (.Config.Labels["com.docker.compose.service"] // null),
                 template: ($tpls[$n] // null),
                 inspect: .}],
             dumps: $dumps, nextcloud: $nc, sqlite: $sq, own_backups: $own, files: $files}' >"$new/manifest.json" 2>>"$LOG_FILE" \
            || { warn "App '${PKG_APP_NAME[$f]}': manifest.json not written"; pkg_mark apps "$f" warnings; }
        rm -f "$new/.inspect.json" "$new/.images.json"
        pkg_swap apps "$f" && pkg_count "apps/$f" "$old" "$kb"
    done
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
    mapfile -t doomed < <(zfs list -H -t snapshot -o name -s creation -d 1 "$ds" 2>/dev/null | zfs_prune_select "$2")
    [[ ${#doomed[@]} -eq 0 ]] && return 0
    mounts_load
    for s in "${doomed[@]}"; do
        snap_is_ours "$s" || continue                    # never anything else (and never a dataset)
        if in_list "$s" "${MT_SOURCE[@]}"; then log "  kept (mounted): $s"; continue; fi
        zfs destroy "$s" 2>>"$LOG_FILE" && { log "  removed: $s"; PRUNED_ZFS+=( "$s" ); }
    done
}

prune_btrfs() {
    local b base sdir s name cutoff free_gb oldest floor
    cutoff="$(date -d "-${BTRFS_KEEP_DAYS} days" +%Y%m%d)"
    for b in "${INV_BASES[@]}"; do
        [[ "${INV_BASE_FS[$b]}" == "btrfs" ]] || continue
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
        # with the disk (brake_floor_gb, 2.23); with none of ours left to release it is only a log line -
        # a full disk is Unraid's own warning (its disk thresholds), not something the brake can change
        floor="$(brake_floor_gb $(( $(df -Pm "$base" | awk 'NR==2{print $2}') / 1024 )))"
        while (( floor > 0 )); do
            free_gb=$(( $(df -Pm "$base" | awk 'NR==2{print $4}') / 1024 ))
            (( free_gb >= floor )) && break
            oldest="$(find "$sdir" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null \
                      | grep -E '^[0-9]{8}-[0-9]{4}$' | sort | head -1)"
            if [[ -z "$oldest" || "$oldest" == "$TS" ]]; then
                log "  $b: ${free_gb} GB free (brake at ${floor} GB) - no earlier snapshot of ours left to release"
                break
            fi
            btrfs subvolume delete "$sdir/$oldest" >/dev/null 2>>"$LOG_FILE" || break
            PRUNED_BTRFS+=( "$sdir/$oldest" )
            warn "$b: only ${free_gb} GB free (brake at ${floor} GB) - snapshot $oldest deleted early"
        done
    done
}

refresh_view() { # browsing view: symlinks instead of bind mounts (they hold no disk)
    local b base l
    mkdir -p "$VIEW_ROOT" 2>/dev/null || return 0
    for l in "$VIEW_ROOT"/*; do [[ -L "$l" && ! -e "$l" ]] && rm -f "$l"; done
    for b in "${INV_BASES[@]}"; do
        [[ "${INV_BASE_FS[$b]}" == "btrfs" ]] || continue
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
    ls -1 "$UB_LOGS"/run-*.log "$UB_LOGS"/check-*.log "$UB_LOGS"/dryrun-*.log 2>/dev/null | sort -t- -k2 | head -n -"$KEEP_LOGS" \
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
        ub_notify "settings.ini is out of date" \
            "${ne} errors, ${nw} warnings - please run setup.sh. Details: $UB_STATE/drift.txt" \
            "$lvl" "$(grep -v '^INFO' "$UB_STATE/drift.txt")"
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

array_stop_report() { # a few lines for the notification
    local l list="" n
    echo "The run of $(date -d "@$STARTED_AT" '+%Y-%m-%d %H:%M' 2>/dev/null) ended at once when the array stop began (phase ${ARRAY_STOP_PHASE:-?}), after $(dur_h $(( $(date +%s) - STARTED_AT )))."
    (( ${#ST_KOPIA_PLAN[@]} )) && echo "Kopia: $(( ${#ST_KOPIA_PLAN[@]} - ${#ST_KOPIA_SKIPPED[@]} )) of ${#ST_KOPIA_PLAN[@]} sources done${ST_KOPIA_INTERRUPTED:+, $ST_KOPIA_INTERRUPTED interrupted (Kopia keeps what it uploaded)}; skipped until the next run: ${#ST_KOPIA_SKIPPED[@]}"
    for l in "${!VM_HELD[@]}"; do list+="${list:+, }VM $l"; done
    for n in "${STOPPED[@]}"; do list+="${list:+, }$n"; done
    for n in "${!NC_ON[@]}"; do list+="${list:+, }maintenance mode of $n"; done
    [[ -n "$list" ]] && echo "Left as the stop found them - brought back right after the array start: $list"
    if (( ${#PRUNED_ZFS[@]} + ${#PRUNED_BTRFS[@]} )); then echo "Its retention had removed $(( ${#PRUNED_ZFS[@]} + ${#PRUNED_BTRFS[@]} )) snapshot(s) when the stop began; the rest waits for the next run. Log: $LOG_FILE"
    else echo "No snapshots were pruned. Log: $LOG_FILE"; fi
}
array_stop_notify() {
    [[ "$ST_MODE" == "backup" ]] || return 0           # a check or dry run is started by hand: its answer is seen there
    local short
    if [[ -n "$ST_KOPIA_INTERRUPTED" ]] || (( ${#ST_KOPIA_SKIPPED[@]} && ${#ST_KOPIA_DONE[@]} )); then short="Backup stopped because the array is being stopped - nothing is lost; the next run continues the Kopia upload."
    else short="Backup stopped because the array is being stopped - nothing is lost; the next run backs up as usual."; fi
    ub_notify "Backup stopped for the array stop" "$short" "normal" "$(array_stop_report)"
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

cleanup() {
    local rc=$?
    [[ "$CLEANUP_DONE" == "yes" ]] && exit "$rc"
    CLEANUP_DONE="yes"
    # ending for another reason (a signal, a failure) while the array is being stopped: then that is the stop
    if [[ "$ARRAY_STOP" != "yes" && "$CLEANUP_ARMED" == "yes" && "$ST_RESULT" == "running" ]] && array_stopping; then
        ARRAY_STOP="yes"; ARRAY_STOP_PHASE="${ARRAY_STOP_PHASE:-$ST_PHASE}"
        log "The array is being stopped (Unraid: fsState $(array_fsstate)) - nothing is started again"
    fi
    if [[ "$ARRAY_STOP" == "yes" ]]; then
        [[ -n "$KOPIA_PID" ]] && ST_KOPIA_INTERRUPTED="$ST_KOPIA_CUR"
        status_phase "aborting"
    elif [[ "$ST_ABORTED" == "yes" ]]; then
        warn "Run aborted (signal)"
        status_phase "aborting"
    fi
    kopia_stop
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
        status_finish aborted "array_stopping"
        log "Backup stopped because the array is being stopped - $( (( ${#PRUNED_ZFS[@]} + ${#PRUNED_BTRFS[@]} )) && echo "retention stopped midway" || echo "nothing pruned"), nothing started; the next run continues."
        array_stop_notify
        rc=3
    elif [[ "$ST_ABORTED" == "yes" ]]; then status_finish aborted "signal"
    elif (( rc != 0 )); then status_finish failed "exit $rc"
    fi
    ub_holder_clear
    exit "$rc"
}
on_signal() { ST_ABORTED="yes"; exit 143; }

# The lock is busy: this run does not happen, and nobody may miss that. It touches nothing the run
# going on uses - not status.json (that describes the run going on), not latest.log, no settings, no
# mounts - and says so: state/skipped.json (every mode), a line in history.jsonl and a notification
# (warning) for a real backup run - a check or dry run is only ever started by hand (the office, a
# terminal), where this answer is seen right away. Exit code 75 (EX_TEMPFAIL: try again later), so a
# caller can tell "busy" from "failed".
skip_busy() {
    local mode="backup" st phase="" current="" since="" why what
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
    [[ "$mode" == "backup" ]] && ub_notify "Backup skipped" \
        "The run of $(date '+%Y-%m-%d %H:%M') was skipped: $why. The next run backs up as usual." "warning"
    exit 75
}

# --recover (2.25): Docker - and libvirt, when a VM is noted - answers? Asked every UB_RECOVER_LOOK s, at most
# UB_RECOVER_WAIT s (right after the array start they may still be coming up); 1 when the array is being
# stopped meanwhile. A service still silent then: recover_interrupted_run keeps its notes for the next run.
# libvirt never answers while Unraid's VM service is switched off: not waited for (the VMs' note goes then).
recover_wait() {
    local until=$(( $(date +%s) + UB_RECOVER_WAIT )) docker=0 virt=0 told=0
    [[ -s "$UB_STATE/stopped" || -s "$UB_STATE/maintenance" ]] && docker=1
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
# Start
##############################################################################
# The lock (flock on state/lock) is held by one run at a time - see lib/common.sh, "Who holds the
# lock". Opened without truncating it: a run turned away must not touch it (the office reads the
# start of the run holding it from its time); the one that gets it touches it.
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

[[ "$SETTINGS_OK" == "yes" ]] || die "settings.ini is missing ($UB_SETTINGS) - run setup.sh first"
if [[ ${#CFG_ERRORS[@]} -gt 0 ]]; then
    for e in "${CFG_ERRORS[@]}"; do err "settings.ini: $e"; done
    die "settings.ini has errors (${#CFG_ERRORS[@]}) - run aborted"
fi

trap cleanup EXIT
trap on_signal INT TERM
CLEANUP_ARMED="yes"

log "===================== $UB_NAME $UB_VERSION - $UB_MODE $TS ====================="
array_stop_check "at its start"      # before anything - also before an earlier run's containers are started again
recover_interrupted_run
[[ "$DRY" == "1" ]] && log "DRY RUN - nothing is changed."

for t in docker jq flock timeout gzip numfmt mountpoint awk; do
    command -v "$t" >/dev/null 2>&1 || die "Tool '$t' is missing"
done
docker info >/dev/null 2>&1 || die "Docker does not answer"
mountpoint -q "$UB_MNT/user" || die "$UB_MNT/user is not mounted - array/pools not started?"

SNAP_NAME="${SNAP_PREFIX}${TS}"
# settings.ini still names the old default prefix: new snapshots get the new one, the old ones age out
if [[ "$SNAP_PREFIX_SET" == "$UB_SNAP_PREFIX_LEGACY" ]]; then
    log "Snapshot names: settings.ini still says the old default prefix $UB_SNAP_PREFIX_LEGACY - new snapshots are called ${SNAP_PREFIX}YYYYMMDD-HHMM, the ${UB_SNAP_PREFIX_LEGACY}... ones stay the engine's and age out by the retention (Set up... > Apply writes the new prefix)"
fi

# --- Inventory, plan, drift ------------------------------------------------
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

# The backup place: a share of its own, backed up - otherwise no run (nothing is paused up to here)
DUMPS_PROBLEM="$(dumps_share_problem "$DUMPS_SHARE")"
if [[ -n "$DUMPS_PROBLEM" ]]; then
    if [[ "$UB_MODE" == "check" ]]; then
        drift_add error "$(dumps_share_text "$DUMPS_PROBLEM" "$DUMPS_SHARE")" "dumps_$DUMPS_PROBLEM" "$DUMPS_SHARE"
    else
        die_code "dumps_$DUMPS_PROBLEM" "$(dumps_share_text "$DUMPS_PROBLEM" "$DUMPS_SHARE") - choose the place in Mr. Backupsy's setup"
    fi
elif is_yes "$KOPIA_ENABLED" && [[ "$(share_mode "$DUMPS_SHARE")" != "kopia" ]]; then
    drift_add warn "The backup place '$DUMPS_SHARE' does not go to Kopia (mode=$(share_mode "$DUMPS_SHARE")) - the packages stay local only" \
        place_not_kopia "$DUMPS_SHARE"
fi
# the packages keep their history in the snapshots of the backup place's share
if [[ -z "$DUMPS_PROBLEM" && "$(share_method "$DUMPS_SHARE")" == "live" ]]; then
    drift_add warn "The backup place '$DUMPS_SHARE' cannot take snapshots ($(printf '%s' "${INV_NOTE[$DUMPS_SHARE]:-}" | head -1)) - its packages keep no history, only the newest state; put the share on a ZFS or btrfs pool" \
        place_no_history "$DUMPS_SHARE"
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

# --- Showing the plan -----------------------------------------------------
build_stop_tiers
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
    log "                    (shares and VMs the smallest first - the larger of the newest complete Kopia snapshot's size and the server's, * = the server's (ZFS, the VM's disks); ? unknown, last)"
fi
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
(( ${FREE_MB:-0} >= MIN_FREE_GB * 1024 )) || die "Not enough space for the packages in $UB_DUMPS (${FREE_MB} MB free)"
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
kopia_one() { # kopia_one <name> <container path>
    local name="$1" cp="$2" t0 rc secs
    t0="$(date +%s)"
    log "Kopia: $name  ($cp)"
    ST_KOPIA_CUR="$name"; ST_KOPIA_CUR_T="$t0"; status_write
    # In the background + wait: a signal (abort) interrupts wait at once,
    # while bash waits for a foreground command until it ends -
    # with Kopia that can be hours. kopia_stop then ends it.
    # 2.24: waited for in steps of UB_ARRAY_LOOK seconds (a sleep beside it, wait -n for whichever ends
    # first), looking at var.ini each time - an array stop interrupts the upload at once (kopia_stop)
    KOPIA_CP="$cp"
    kopia_x snapshot create "$cp" --description "$UB_KOPIA_DESC $TS" >>"$LOG_FILE" 2>&1 &
    KOPIA_PID=$!
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
    done
    kill "$w" 2>/dev/null; wait "$w" 2>/dev/null
    KOPIA_PID=""; KOPIA_CP=""
    # it ended badly just as the array stop began (Docker going): interrupted by the stop, not failed
    if (( rc != 0 )) && array_stopping; then ST_KOPIA_INTERRUPTED="$name"; array_stop_check "Kopia ended with exit code $rc"; fi
    secs=$(( $(date +%s) - t0 ))
    if [[ $rc -eq 0 ]]; then KOPIA_DONE=$((KOPIA_DONE+1)); log "  ok ($secs s)"
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
    local t="$1" n="$2" f="$3" key="$1:$2" cp mp c_mp missing
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
    kopia_one "$key" "$cp"
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
    kopia_one "$s" "$cp"
}

# kopia_flash_one  - the flash's ZFS snapshot (only when /boot is on ZFS); its snapshot or mount failed: not backed up
kopia_flash_one() {
    local cp
    array_stop_check "before Kopia: flash"
    [[ "$PLAN_FLASH" == "snapshot" ]] || { kopia_skip "flash"; return 0; }
    cp="$(k_path "$MOUNT_ROOT/$FLASH_SOURCE_NAME")"
    if [[ -n "${KMI[$cp]+x}" ]]; then kopia_one "flash" "$cp"
    else err "Kopia does not see the flash snapshot ($cp)"; kopia_skip "flash"; fi
}

if [[ "$SKIPK" == "1" ]]; then
    log "Kopia skipped (UB_SKIP_KOPIA=1)."
elif [[ "$KOPIA_OK" == "off" ]]; then
    log "Kopia switched off - local snapshots and dumps are done."
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
    [[ $KOPIA_FAILED -gt 0 ]] && ub_notify "Kopia incomplete" \
        "$KOPIA_FAILED source(s) not backed up, $KOPIA_DONE ok. Log: $LOG_FILE" "warning"
fi

# --- Unmounting and cleaning up --------------------------------------------
if [[ "$KEEP_MOUNTS" != "yes" ]]; then
    next_phase "unmounting"
    unmount_all || warn "Not all snapshot mounts could be released"
fi

next_phase "cleanup"                 # never prunes while the array is being stopped
log "Cleaning up ..."
if command -v zfs >/dev/null 2>&1; then
    mapfile -t OWNERS < <(zfs list -H -t snapshot -o name 2>/dev/null | snap_filter | sed 's/@.*//' | sort -u)
    for ds in "${OWNERS[@]}"; do
        array_stop_check "while pruning"
        prune_zfs "$ds" "${PLAN_ZFS_RET[$ds]:-$ZFS_RETENTION}"
    done
fi
array_stop_check "while pruning"
command -v btrfs >/dev/null 2>&1 && prune_btrfs
pruned_write                         # what the retention removed, for whoever watches the server (state/pruned.json)
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
    echo "drift=$(drift_count warn)/$(drift_count error)"
    echo "log=$LOG_FILE"
} >"$UB_STATE/last-run"

if is_yes "$KOPIA_ENABLED"; then KSUM="Kopia ${KOPIA_DONE} ok/${KOPIA_FAILED} failed"; else KSUM="Kopia off"; fi
# A few lines for the notification: what this run did, like the office's overview
run_report() {
    local l n p d secs ok f list=""
    echo "Duration $(dur_h "$TOTAL"), containers stopped $(dur_h "$DOWNTIME")"
    echo "Snapshots: ${#PLAN_ZFS[@]} ZFS datasets, ${#BTRFS_OK[@]} btrfs disks$([[ "$PLAN_FLASH" == "snapshot" ]] && echo ", flash")"
    for l in "${DUMPS_DONE[@]}"; do list+="${list:+, }${l%|*} $(human "${l##*|}")"; done
    [[ -n "$list" ]] && echo "Dumps: $list"
    list=""
    for l in "${!SQ_STATE[@]}"; do list+="${list:+, }${l#*|} ${SQ_STATE[$l]}"; done
    [[ -n "$list" ]] && echo "SQLite copies: $list"
    echo "Packages: ${#PKG_APPS[@]} apps, ${#PKG_VMS[@]} VMs in $UB_DUMPS$( (( PKG_OLD_RUNS > 0 )) && [[ "$PKG_OLD_ACTION" == "removed" ]] && echo "; ${PKG_OLD_RUNS} old run folders cleared away")"
    list=""
    for l in "${ST_VMS[@]}"; do
        IFS='|' read -r n p d secs _ <<<"$l"
        case "$d" in off|kept_running|not_running|planned) continue ;; esac
        list+="${list:+, }$n ($d, $(dur_h "$secs"))"
    done
    [[ -n "$list" ]] && echo "VMs: $list"
    list=""
    for l in "${ST_KOPIA_DONE[@]}"; do
        IFS='|' read -r n ok secs _ <<<"$l"
        if [[ "$ok" == "1" ]]; then list+="${list:+, }$n $(dur_h "$secs")"; else list+="${list:+, }$n FAILED"; fi
    done
    [[ -n "$list" ]] && echo "Kopia: $list"
    list=""
    for l in "${NEW_LIST[@]}"; do IFS=$'\x1f' read -r n p _ <<<"$l"; list+="${list:+, }$n/$p"; done
    [[ -n "$list" ]] && echo "New, only local until you decide: $list"
    (( ${#T_NEW[@]} )) && echo "New containers, kept running until you decide: ${T_NEW[*]}"
    echo "Errors $ERRORS, warnings $WARNINGS"
    echo "Log: $LOG_FILE"
}
SUMMARY="duration ${TOTAL}s, downtime ${DOWNTIME}s, ${KSUM}, packages ${RUN_SIZE}"
if (( ERRORS > 0 )); then status_finish errors
elif (( WARNINGS > 0 )); then status_finish warnings
else status_finish ok; fi
if (( ERRORS > 0 )); then
    log "Backup finished with ${ERRORS} error(s) and ${WARNINGS} warning(s). $SUMMARY"
    ub_notify "Backup with errors" "${ERRORS} errors, ${WARNINGS} warnings. $SUMMARY" "alert" "$(run_report)"
    exit 1
elif (( WARNINGS > 0 )); then
    log "Backup finished with ${WARNINGS} warning(s). $SUMMARY"
    ub_notify "Backup with warnings" "${WARNINGS} warnings. $SUMMARY" "warning" "$(run_report)"
else
    log "Backup successful. $SUMMARY"
    is_yes "$NOTIFY_SUCCESS" && ub_notify "Backup successful" "$SUMMARY" "normal" "$(run_report)"
fi
exit 0
}
