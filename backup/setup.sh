#!/bin/bash
###############################################################################
# unraid-backup - setup.sh                        Version 2.30 - 2026-10-08
#   2.30 Sleeping pools: an SSD in standby is never asleep (ub_asleep_load: spundown AND rotational) - the plan's
#        pool_asleep / asleep_bases follow it
#   2.29 Partner offices: a unit that is a dataset of its own but not among what the partner agreed to keep
#        (pairs.json send.units) is partner_ok false, partner_why not_agreed in the plan, with a hint to ask
#        at the Team Lead («Change what <host> sends…»); its partner stays in the unit's key (settings.ini
#        unchanged) - the run skips it until the partner agrees, then it goes along by itself
#   2.28 Sleeping pools: [general] asleep_pools = wake (default) | skip - asked with the snapshots, taken from
#        the decisions (general|asleep_pools), written only when it is skip or was there before (so nothing
#        changes for an install that never chose). The plan carries asleep_pools and per share and VM
#        pool_asleep / asleep_bases: what sleeps right now (disks.ini, nothing woken)
#   2.27 Partner offices: the [partner "<id>"] sections come from the Team Lead's pairs (data/partner/
#        pairs.json - the pairs this office sends to; without the file they stay as settings.ini has
#        them), the units' keys ([share|vm] partner = <id>, [general] partner_place = <id>) from the
#        decisions, kept only for a partner that exists and a unit that is one dataset of its own. The
#        plan lists partners[] (never connects) and per share/VM partner, partner_ok, partner_why;
#        --forget keeps the [partner] sections for the next setup (state/partners-kept.ini).
#   2.26 The plan carries per VM what its disk files take (bytes, allocated blocks) and what they are
#        (apparent: a sparse vdisk's full virtual size - what Kopia reads at a first upload, holes as
#        zeros); backup.sh orders the VMs' Kopia sources by the latter.
#   2.25 (backup.sh only: --recover brings back what an interrupted run left right after the array
#        start; the Kopia phase goes small and important first. Here too: an interrupted run's notes
#        keep exactly what didn't come back - Docker or libvirt silent, a container or VM that doesn't
#        start, a Nextcloud whose container doesn't run -, its containers start network first, then
#        databases, then apps, a warning names what didn't come back; with the VM service off the VMs'
#        note goes; the notifications take turns with the office's)
#   2.24 (backup.sh only: a run notices the array being stopped and ends at once, cleanly; the
#        notes of an interrupted run are never acted on while the array is being stopped - here too)
#   2.23 (backup.sh only: the btrfs emergency brake scales with the disk)
#   2.22 (backup.sh only: VMs with prepare = shutdown go down before anything stops - the apps no
#        longer wait for them)
#   2.21 New things stay local and keep running until you decide: per share that goes to Kopia the
#        top-level folders that exist are recorded as kopia_known (the first time all of them, later
#        what was known plus what you send to Kopia; a share with a sleeping disk gets its first record
#        at a later setup); a folder neither known nor left out is new and waits - the plan lists it per
#        share (waiting), a terminal asks (local + Kopia / only local / later). A container that is not in
#        [docker] known is proposed to keep running, a VM without a [vm] section not to be held
#        (prepare none). Kopia's policies leave the waiting folders out
#   2.20 Names: the default snapshot prefix is uso-backup-; a settings.ini with the old default
#        unraidbackup- gets the new one proposed (a normal change), a prefix of the user's own stays.
#        A prefix may join words with - (uso-backup-), never begin with uso-plan- (Ms. Snapshotini's).
#        The Kopia container path shown for new setups is /uso
#   2.20 Notes itself as the lock's holder in state/lock-holder.json while it runs (a backup run that
#        finds the lock busy says why it was skipped); when the lock is busy it names who holds it,
#        exit code 75
#   2.19 [app "<name>"] and [vm "<name>"] kopia = yes, folder, kopia_retention, kopia_ignore: apps and VMs
#        with a Kopia source of their own (backup.sh); their policies are written and compared like the
#        shares' (kind app / vm), the shares leave their parts out. With --apply only the decisions
#        count for them. The plan names per container the media server (media), Kopia rules to offer for
#        caches, transcodes, logs and Immich's thumbnails (offers), and where Nextcloud and Immich keep
#        their files (data)
#   2.18 The backup place holds a package per app and VM (backup.sh); its share keeps their history
#        in snapshots: proposed as at least a local snapshot, never off (why code backup_place), a
#        warning when it can't take snapshots. keep_runs is no longer written (old files: ignored)
#   2.17 --forget: start the setup anew - settings.ini, the office's decisions and the
#        last plan go to state/reset-<time>/; nothing backed up is touched. The share domains
#        is proposed as a local snapshot (VMs are held for it since 2.16), not as off. The plan
#        lists the shares each container binds (containers[].binds); [docker] skip = apps not
#        backed up on purpose (keep running like no_stop)
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
#   2.13 Messages and comments in English; questions take y/n (j still counts as yes),
#        deleting an old Kopia source is confirmed with DELETE
#   2.12 Backup place (general|dumps_share): a share of its own proposed and checked,
#        without a valid place settings.ini is not written
#   2.11 User Scripts entry unraid-secretary-office_backup (the old name is moved)
#        The office's containers always keep running during the backup (like Kopia)
#   2.10 The VM configuration (libvirt.img) is proposed as an archive
#   2.9  New shares of unknown size (not measured, no ZFS) are only
#        proposed as local, no longer for Kopia without asking; snapshot
#        prefix default "unraidbackup-"; media servers (Emby, Jellyfin, Plex)
#        are proposed to keep running
#   2.8  (backup.sh only: pause apps before the dumps)
#   2.7  --plan / --apply for Mr. Backupsy's setup assistant: proposals
#        with reason codes as JSON, decisions back as JSON,
#        progress in state/setup-status.json
#   2.6  Part of the Unraid Secretary Office: data in <office>/data/unraid-backup
#   2.5  (backup.sh only: status files for other programs)
#   2.4  More readable output: steps as a coloured bar, tables with
#        a header and every second row slightly shaded (UB_STRIPES),
#        questions highlighted; Nextcloud from several containers (app + cron)
#        is recognised as one instance and asked about only once
#   2.3  Fix "gone_by_id: bad array subscript" for shares without data; Time
#        Machine also detected by name and container; container data (appdata)
#        is not switched off because of its size; column "location" shorter
#   2.2  General version without server-specific leftovers; Time Machine
#        shares are detected; a snapshot prefix of its own "ub-"
#   2.1  Kopia optional (step 3, explained), mount_root is created,
#        databases in containers and Compose stacks, MongoDB
#   2.0  First version
#
# Checks the server, makes proposals and, after asking, writes them to
# settings.ini. Kopia (encrypted offsite backup) is optional; if
# wanted, setup.sh sets it up: checks the container mapping,
# mount propagation and repository, sets the policies and retires old
# sources. Runs in a terminal (SSH or Unraid's web terminal), not
# through User Scripts - there is no input there.
#
# Run it again whenever backup.sh reports drift: the existing
# decisions in settings.ini are then the defaults, only what changed
# is new.
#
# USAGE
#   bash /usr/local/emhttp/plugins/unraid-secretary-office/backup/setup.sh [option]
#
# VARIANTS (environment variable - the option after it is a shortcut)
#   UB_SETUP=interactive          check everything, ask, write (default)
#   UB_SETUP=check     --check    only check and report, write nothing
#   UB_SETUP=kopia     --kopia    only the Kopia part with the existing
#                                 settings.ini (align the policies)
#   UB_SETUP=plan      --plan     like --yes, but writes nothing: all proposals,
#                                 reasons and check results go to
#                                 state/setup-plan.json (for Mr. Backupsy)
#   UB_SETUP=apply     --apply=<file>  lay decisions (JSON: settings.ini key
#                                 as in setup-plan.json -> value) over the current
#                                 values, then check and write like --yes
#                                 Both write their progress to
#                                 state/setup-status.json
#   UB_SETUP=forget    --forget   start anew: settings.ini, the office's decisions
#                                 and the last plan go to state/reset-<time>/
#                                 (asks first; with --yes without asking). Snapshots,
#                                 dumps, Kopia and the history stay. Progress also
#                                 to state/setup-status.json
#   UB_YES=1           --yes      take all proposals without asking
#                                 (also together with --kopia; never deletes Kopia sources)
#   UB_EXPLAIN=0                  leave out the explanations for each step
#                                 (default 1 = explain everything)
#   UB_SIZE_TIMEOUT=120           seconds per share for measuring the size
#                                 (du); 0 = do not measure
#   UB_SETTINGS=/path/settings.ini  another settings file
#   UB_STRIPES=auto               table rows alternately slightly shaded;
#                                 auto asks the terminal for its back-
#                                 ground, dark / light set it, off = none
#   Example: UB_SETUP=check bash /usr/local/emhttp/plugins/unraid-secretary-office/backup/setup.sh
#
# What setup.sh NEVER does: change container templates, connect Kopia to a
# repository, delete snapshots or data (unless you expressly confirm
# deleting an old Kopia source with DELETE).
###############################################################################

# One block up to the end: bash parses all of it before running any of it. A run
# takes hours - without the block bash would read on from a file replaced meanwhile.
# (lib/common.sh is safe anyway: "source" reads a file completely.)
{

set -uo pipefail

# shellcheck source=lib/common.sh
source "$(dirname "$(readlink -f "$0")")/lib/common.sh" \
    || { echo "lib/common.sh is missing next to setup.sh"; exit 1; }

usage() { awk 'NR>1 && /^#+$/ {next} NR>1 && /^#/ {sub(/^# ?/,""); print; next} NR>1 {exit}' "$0"; }

for a in "$@"; do
    case "$a" in
        --check) UB_SETUP="check" ;;
        --kopia) UB_SETUP="kopia" ;;
        --plan)  UB_SETUP="plan" ;;
        --apply=*) UB_SETUP="apply"; UB_DECISIONS="${a#--apply=}" ;;
        --forget) UB_SETUP="forget" ;;
        --yes)   UB_YES=1 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $a  (see --help)"; exit 2 ;;
    esac
done
MODE="${UB_SETUP:-interactive}"
YES="${UB_YES:-0}"
[[ "$MODE" == "auto" || "$MODE" == "plan" || "$MODE" == "apply" ]] && YES=1
[[ "$MODE" == "interactive" && "$YES" == "1" ]] && MODE="auto"
case "$MODE" in interactive|check|kopia|auto|plan|apply|forget) ;; *) echo "UB_SETUP=$MODE is unknown"; exit 2 ;; esac
SIZE_TIMEOUT="${UB_SIZE_TIMEOUT:-120}"
EXPLAIN="${UB_EXPLAIN:-1}"

if [[ "$YES" != "1" && ( "$MODE" == "interactive" || "$MODE" == "kopia" || "$MODE" == "forget" ) ]] && ! [[ -r /dev/tty && -t 1 ]]; then
    echo "No terminal - setup.sh needs input. Without questions: --yes, only checking: --check"
    exit 2
fi
[[ $EUID -eq 0 ]] || { echo "Please run as root."; exit 1; }
ub_data_dirs || { echo "Cannot create folders in $UB_DATA"; exit 1; }
TS="$(date +%Y%m%d-%H%M)"
LOG_FILE="$UB_LOGS/setup-$TS.log"

##############################################################################
# Output and input
##############################################################################
# Ask the terminal for its background colour (OSC 11) -> light | dark | empty
# if the terminal does not answer. That way the table stripes stay equally
# subtle on light and dark backgrounds.
term_bg() {
    local old reply="" r g b
    old="$(stty -g </dev/tty 2>/dev/null)" || return 0
    stty -echo -icanon min 1 time 0 </dev/tty 2>/dev/null
    printf '\e]11;?\a' >/dev/tty
    IFS= read -r -d $'\a' -t 0.5 reply </dev/tty
    stty "$old" </dev/tty 2>/dev/null
    [[ "$reply" =~ rgb:([0-9a-fA-F]{2})[0-9a-fA-F]*/([0-9a-fA-F]{2})[0-9a-fA-F]*/([0-9a-fA-F]{2}) ]] || return 0
    r=$((16#${BASH_REMATCH[1]})); g=$((16#${BASH_REMATCH[2]})); b=$((16#${BASH_REMATCH[3]}))
    if (( r*299 + g*587 + b*114 > 128000 )); then echo light; else echo dark; fi
}

C_B=""; C_G=""; C_Y=""; C_R=""; C_D=""; C_0=""
C_H=""; C_S=""; C_TH=""; C_Z=""; C_Q=""; C_EL=""
if [[ -t 1 ]]; then
    C_B=$'\e[1m'; C_G=$'\e[32m'; C_Y=$'\e[33m'; C_R=$'\e[31m'; C_D=$'\e[2m'; C_0=$'\e[0m'
    C_H=$'\e[1;37;44m'      # step: white on blue, across the whole width
    C_TH=$'\e[1;4m'         # table header: bold, underlined
    C_Q=$'\e[1;36m'         # questions
    C_EL=$'\e[K'            # fill the line in the current background colour
    # Stripes need 256 colours; without an answer from the terminal dark applies
    # (Unraid's web terminal, most SSH terminals)
    STRIPES="${UB_STRIPES:-auto}"
    if [[ "$STRIPES" == "auto" ]]; then
        STRIPES="off"
        if [[ "${TERM:-}" == *256color* || "${COLORTERM:-}" == truecolor || "${COLORTERM:-}" == 24bit ]] && [[ -r /dev/tty ]]; then
            STRIPES="$(term_bg)"; STRIPES="${STRIPES:-dark}"
        fi
    fi
    case "$STRIPES" in
        dark)  C_Z=$'\e[48;5;236m'; C_S=$'\e[1;48;5;238m' ;;
        light) C_Z=$'\e[48;5;255m'; C_S=$'\e[1;48;5;253m' ;;
    esac
fi
say()  { printf '%s\n' "$*"; printf '%s\n' "$*" | sed 's/\x1b\[[0-9;]*[mK]//g' >>"$LOG_FILE"; }
# say2 <screen> <log> - where the two should look different
say2() { printf '%s\n' "$1"; printf '%s\n' "$2" >>"$LOG_FILE"; }
ok()   { say "  ${C_G}OK${C_0}      $*"; msg ok "$*"; }
hint() { say "  ${C_D}..${C_0}      $*"; msg hint "$*"; }
wrn()  { WARNINGS=$((WARNINGS+1)); say "  ${C_Y}WARNING${C_0} $*"; msg warn "$*"; }
bad()  { ERRORS=$((ERRORS+1));     say "  ${C_R}ERROR${C_0}   $*"; msg error "$*"; }
# Collect messages for --plan/--apply: "level<US>step<US>text" (without colours)
declare -a MSGS=()
STEP_ID=""
msg() {
    [[ "$MODE" == "plan" || "$MODE" == "apply" || "$MODE" == "forget" ]] || return 0
    local t
    t="$(sed 's/\x1b\[[0-9;]*[mK]//g' <<<"$2")"
    MSGS+=( "$1"$'\x1f'"$STEP_ID"$'\x1f'"$t" )
}
# explain <<'TXT' ... TXT  - background for a step (UB_EXPLAIN=0 switches it off)
explain() {
    local l
    if [[ "$EXPLAIN" == "0" || "$MODE" == "check" ]]; then cat >/dev/null; return 0; fi
    while IFS= read -r l; do say "  ${C_D}|${C_0} $l"; done
    say ""
}
STEP=0; STEPS=10
# A step as a coloured bar, a subsection as a slightly shaded line;
# the log keeps "== ... ==" and "-- ... --"
band() { # band <title> <log line>
    if [[ -n "$C_H" ]]; then say2 "${C_H}${C_EL} $1${C_0}"$'\n' "$2"; else say "$2"; fi
}
hdr() { STEP=$((STEP+1)); say ""; band "Step $STEP/$STEPS   $*" "== Step $STEP/$STEPS: $* =="; }
sub() {
    say ""
    if [[ -n "$C_S" ]]; then say2 "${C_S}${C_EL} $*${C_0}" "-- $* --"
    else say "${C_B}-- $* --${C_0}"; fi
}
# Tables: header underlined, every second row slightly shaded (on the
# screen only). Colour changes inside the row set the background again.
TROW=0
thead() { local h="$*" ind; ind="${h%%[! ]*}"; TROW=0; say "$ind${C_TH}${h#"$ind"}${C_0}"; }
trow() {
    local l="$*"
    TROW=$((TROW+1))
    if [[ -n "$C_Z" ]] && (( TROW % 2 == 0 )); then say "${C_Z}${C_EL}${l//"$C_0"/"$C_0$C_Z"}${C_0}"
    else say "$l"; fi
}

# Is there really someone to ask? (not with --yes and not in check mode)
interactive() { [[ "$YES" != "1" && "$MODE" != "check" ]]; }

REPLY=""
ask() { # ask <question> [default] -> REPLY
    local q="$1" d="${2-}"
    if [[ "$YES" == "1" || "$MODE" == "check" ]]; then REPLY="$d"; return 0; fi
    printf '%s%s%s%s: ' "$C_Q" "$q" "$C_0" "${d:+ [$d]}" >/dev/tty
    IFS= read -r REPLY </dev/tty || REPLY=""
    [[ -z "$REPLY" ]] && REPLY="$d"
    printf '%s > %s\n' "$q" "$REPLY" >>"$LOG_FILE"
    return 0
}
ask_yn() { # ask_yn <question> <y|n>  -> 0 = yes ("j" for ja counts too)
    ask "$1 (y/n)" "$2"
    [[ "${REPLY,,}" == j* || "${REPLY,,}" == y* ]]
}

##############################################################################
# Proposals (P) - the same keys as in settings.ini
##############################################################################
declare -A P=()
declare -A WHY=()          # reason per share
declare -A WHY_CODE=() WHY_ARG=()   # the same as code + value (for user interfaces)
why() { WHY[$1]="$4"; WHY_CODE[$1]="$2"; WHY_ARG[$1]="$3"; }   # why <share> <code> <value> <text>

declare -A OLD=()          # settings.ini as it was at the start
declare -a OLD_SECTIONS=()
old_keep()  { local k; OLD=(); for k in "${!CFG[@]}"; do OLD[$k]="${CFG[$k]}"; done; OLD_SECTIONS=( "${CFG_SECTIONS[@]}" ); }
old()       { local k="$1"; if [[ -n "${OLD[$k]:-}" ]]; then printf '%s' "${OLD[$k]##*$'\n'}"; else printf '%s' "${2-}"; fi; }
old_list()  { local k="$1" v; [[ -n "${OLD[$k]:-}" ]] || return 0; while IFS= read -r v; do [[ -n "$v" ]] && printf '%s\n' "$v"; done <<<"${OLD[$k]}"; }
old_names() { local s; for s in "${OLD_SECTIONS[@]}"; do [[ "$s" == "$1|"* ]] && printf '%s\n' "${s#*|}"; done; return 0; }
old_has()   { local s; for s in "${OLD_SECTIONS[@]}"; do [[ "$s" == "$1" ]] && return 0; done; return 1; }

pget() { local k="$1"; if [[ -n "${P[$k]+x}" ]]; then printf '%s' "${P[$k]}"; else printf '%s' "${2-}"; fi; }
pset() { P[$1]="$2"; }
plist() { local k="$1" v; [[ -n "${P[$k]:-}" ]] || return 0; while IFS= read -r v; do [[ -n "$v" ]] && printf '%s\n' "$v"; done <<<"${P[$k]}"; }
plist_add() { local k="$1" v="$2"; plist "$k" | grep -Fxq -- "$v" && return 0; if [[ -n "${P[$k]:-}" ]]; then P[$k]+=$'\n'"$v"; else P[$k]="$v"; fi; }
plist_del() { local k="$1" v="$2"; P[$k]="$(plist "$k" | grep -Fxv -- "$v")"; }
# Default: the existing settings.ini, otherwise a heuristic
pinit() { local k="$1" d="$2"; if [[ -n "${OLD[$k]+x}" ]]; then P[$k]="${OLD[$k]}"; else P[$k]="$d"; fi; }

##############################################################################
# Helpers
##############################################################################
# Physical script path -> /mnt/user/<share>/... (stays valid when the share moves)
ub_user_path() {
    local p="$UB_DIR" b
    for b in "${INV_BASES[@]}"; do
        if [[ "$p" == "${INV_BASE_PATH[$b]}/"* ]]; then printf '%s' "$UB_MNT/user/${p#"${INV_BASE_PATH[$b]}"/}"; return; fi
    done
    printf '%s' "$p"
}

# host path -> "share|relative" (relative without a leading /), "*|" for whole roots
path_share() {
    local p="${1%/}" b r
    [[ "$p" == "$UB_MNT" || "$p" == "$UB_MNT/user" || "$p" == "$UB_MNT/user0" ]] && { echo "*|"; return; }
    if [[ "$p" == "$UB_MNT/user/"* || "$p" == "$UB_MNT/user0/"* ]]; then r="${p#"$UB_MNT"/user/}"; r="${r#"$UB_MNT"/user0/}"
    else
        for b in "${INV_BASES[@]}"; do
            [[ "$p" == "${INV_BASE_PATH[$b]}" ]] && { echo "*|"; return; }
            [[ "$p" == "${INV_BASE_PATH[$b]}/"* ]] && { r="${p#"${INV_BASE_PATH[$b]}"/}"; break; }
        done
    fi
    [[ -z "${r:-}" ]] && return 1
    if [[ "$r" == */* ]]; then echo "${r%%/*}|${r#*/}"; else echo "$r|"; fi
}

# Is <relative> in the share caught by a simple, anchored ignore rule?
ignored_rel() { # ignored_rel <share> <relative>
    local s="$1" rel="$2" r
    [[ -z "$rel" ]] && return 1
    while IFS= read -r r; do
        [[ "$r" == /* ]] || continue
        r="${r#/}"; r="${r%/}"
        [[ -z "$r" || "$r" == *[\*\?\[]* ]] && continue
        [[ "$rel" == "$r" || "$rel" == "$r/"* ]] && return 0
    done < <(plist "share|$s|kopia_ignore")
    return 1
}

gb_fmt() { local g="$1"; [[ -z "$g" ]] && { printf '?'; return; }; [[ "$g" == "-1" ]] && { printf '>time'; return; }
           if (( g >= 1024 )); then printf '%d.%d TB' $((g/1024)) $(( (g%1024)*10/1024 )); else printf '%d GB' "$g"; fi; }

# Number list "1 3 5-7" -> numbers
expand_nums() { local t a b; local -a toks; read -r -a toks <<<"$*"; for t in "${toks[@]}"; do if [[ "$t" =~ ^([0-9]+)-([0-9]+)$ ]]; then a=${BASH_REMATCH[1]}; b=${BASH_REMATCH[2]}; seq "$a" "$b"; elif is_uint "$t"; then echo "$t"; fi; done; }

##############################################################################
# Step 1: environment
##############################################################################
step_environment() {
    hdr "Environment"
    explain <<'TXT'
What happens here: setup.sh checks that everything the nightly run needs is there.
- zfs / btrfs: they make the snapshots. A snapshot freezes the state of a
  dataset or a disk within seconds; Kopia then reads calmly from this
  frozen state while the services are long running again.
- overlayfs: joins the snapshot parts of a share that lies on several bases
  (e.g. cache pool + array) into ONE view - the way /mnt/user does it live.
- jq: reads the JSON output of docker and kopia.
- notify: Unraid's notifications (bell, mail, push - depending on your Unraid settings).
- The schedule: the office's plugin starts backup.sh from its cron file (a copy
  outside the plugin folder needs a User Scripts entry, a 3-line call on the
  flash); settings, dumps and logs stay in the office's folders.
TXT
    say "  Script folder: $UB_DIR"
    say "  Data folder:   $UB_DATA"
    if [[ "$UB_DIR" == "$UB_BOOT"* ]]; then
        wrn "The script folder is on the flash ($UB_BOOT) - better under $UB_MNT/user/appdata"
    fi
    local t miss=0
    for t in docker jq flock timeout gzip numfmt mountpoint awk du; do
        command -v "$t" >/dev/null 2>&1 || { bad "Tool '$t' is missing"; miss=1; }
    done
    (( miss )) && { say "Without these tools it cannot go on."; exit 1; }
    [[ -f /etc/unraid-version ]] && ok "Unraid $(sed -n 's/version="\(.*\)"/\1/p' /etc/unraid-version)" \
                                 || wrn "/etc/unraid-version is missing - is this an Unraid server?"
    docker info >/dev/null 2>&1 && ok "Docker $(docker version -f '{{.Server.Version}}' 2>/dev/null)" \
                                || { bad "Docker does not answer"; exit 1; }
    mountpoint -q "$UB_MNT/user" && ok "$UB_MNT/user is mounted" \
                                 || { bad "$UB_MNT/user is missing - start the array/pools first"; exit 1; }
    command -v zfs   >/dev/null 2>&1 && ok "zfs present"   || hint "zfs not present"
    command -v btrfs >/dev/null 2>&1 && ok "btrfs present" || hint "btrfs not present"
    grep -qw overlay /proc/filesystems 2>/dev/null || modprobe overlay 2>/dev/null
    grep -qw overlay /proc/filesystems 2>/dev/null && ok "overlayfs present (shares across several bases)" \
        || wrn "overlayfs is missing - shares across several bases appear as one subfolder per base"
    [[ -x "$UB_NOTIFY_BIN" ]] && ok "Unraid notifications available" || wrn "Unraid's notify is missing - no notifications"
    if ub_is_plugin; then
        ok "The office's plugin schedules the nightly run (no User Scripts needed)"
    else
        [[ -d "$UB_BOOT/config/plugins/user.scripts" ]] && ok "User Scripts installed" \
            || wrn "Plugin 'User Scripts' not found - needed for the nightly run"
    fi
}

##############################################################################
# Step 2: the basis (existing settings.ini)
##############################################################################
HAVE_SETTINGS="no"
ORIG_DUMPS_SHARE=""
step_basis() {
    hdr "Existing settings"
    explain <<'TXT'
If there already is a settings.ini, its values are the defaults - only what changed
since then is proposed anew. Otherwise the proposals come from the system itself.
You confirm or change; nothing is written until the end.
TXT
    if load_settings; then
        HAVE_SETTINGS="yes"
        ok "settings.ini read - the decisions so far are the defaults"
        local e; for e in "${CFG_ERRORS[@]}"; do wrn "settings.ini: $e"; done
    else
        CFG=(); CFG_SECTIONS=(); CFG_ERRORS=()
        # the partner offices --forget kept (2.27): only their [partner] sections
        if [[ -f "$UB_STATE/partners-kept.ini" && ! -L "$UB_STATE/partners-kept.ini" ]] && cfg_load "$UB_STATE/partners-kept.ini"; then
            local pk
            for pk in "${!CFG[@]}"; do [[ "$pk" == partner\|* ]] || unset "CFG[$pk]"; done
            local -a psecs=(); for pk in "${CFG_SECTIONS[@]}"; do [[ "$pk" == partner\|* ]] && psecs+=( "$pk" ); done
            CFG_SECTIONS=( "${psecs[@]}" ); CFG_ERRORS=()
        fi
        apply_settings
        hint "No settings.ini yet - the proposals come from the system"
    fi
    old_keep
    ORIG_DUMPS_SHARE="$(old "general|dumps_share")"       # before --apply lays the decisions over it
    ORIG_ASLEEP_SET="${CFG[general|asleep_pools]:+yes}"  # settings.ini names asleep_pools (2.28): it is written again, wake too
    [[ "$MODE" == "apply" ]] && decisions_load

    # General defaults
    pinit "general|server"         "$(hostname -s 2>/dev/null)"
    pinit "general|mount_root"     "$UB_MNT/addons/$UB_OFFICE_SHARE/snapshots"
    pinit "general|view_root"      "$UB_MNT/addons/$UB_OFFICE_SHARE/btrfs-snap"
    # Before 2.14 both were folders directly in /mnt - move them to /mnt/addons (Kopia's
    # mapping has to follow for mount_root: step_kopia keeps the old one until it does)
    [[ "$(pget "general|mount_root")" == "$UB_MNT/backup-snapshots" ]] && pset "general|mount_root" "$UB_MNT/addons/$UB_OFFICE_SHARE/snapshots"
    [[ "$(pget "general|view_root")" == "$UB_MNT/btrfs-snap" ]] && pset "general|view_root" "$UB_MNT/addons/$UB_OFFICE_SHARE/btrfs-snap"
    pinit "general|btrfs_snap_dir" ".btrfs-snap"
    pinit "general|dumps_share"    ""             # the choice so far stays (before 2.14 it was guessed anew)
    pinit "general|keep_logs"      "60"
    pinit "general|min_free_gb"    "8"
    pinit "general|keep_mounts"    "no"
    pinit "general|notify_success" "yes"
    pinit "general|asleep_pools"   "wake"           # 2.28: wake = as before; skip = sleeping pools are left out that night
    [[ "$(pget "general|asleep_pools")" == "skip" ]] || pset "general|asleep_pools" "wake"
    pinit "zfs|retention"          "7 4 6"
    pinit "btrfs|keep_days"        "7"
    pinit "btrfs|min_free_gb"      "150"
    pinit "btrfs|snapshot_all"     "no"
    pinit "drift|remind_days"      "7"
    pinit "drift|ignore"           ""
    pinit "docker|stop"            "all"
    pinit "docker|stop_timeout"    "60"
    pinit "kopia|keep_latest"      "7"
    pinit "kopia|keep_hourly"      "0"
    pinit "kopia|keep_daily"       "7"
    pinit "kopia|keep_weekly"      "4"
    pinit "kopia|keep_monthly"     "12"
    pinit "kopia|keep_annual"      "3"
    pinit "kopia|compression"      "inherit"
    pinit "kopia|ignore"           "$(printf '%s\n' .DS_Store '._*' '.Trash-*' '.Recycle.Bin/' '*@eaDir*' '*@__thumb*' '*SynoResource*')"
    # Ms. Dustdevil's storeroom never goes offsite: what lies there was backed up under its old
    # path before, and the local snapshots keep it until it is emptied (folders and datasets)
    if ! plist "kopia|ignore" | grep -Fxq "_$UB_OFFICE_SHARE-trash*/"; then
        plist_add "kopia|ignore" "_$UB_OFFICE_SHARE-trash*/"
        hint "Kopia leaves out Ms. Dustdevil's storeroom (_$UB_OFFICE_SHARE-trash) - the local snapshots keep it"
    fi

    # Snapshot prefix: a prefix of its own, "uso-backup-". backup.sh never touches
    # snapshots of other tools (other prefixes) - not even when cleaning up.
    pinit "general|snap_prefix" "$UB_SNAP_PREFIX"
    # The old default (2.9-2.19) counts as the default: proposed as the new one, a normal change Apply
    # writes. backup.sh already names new snapshots so; the old ones age out by the retention (lib/common.sh 10)
    if [[ "$(pget "general|snap_prefix")" == "$UB_SNAP_PREFIX_LEGACY" ]]; then
        pset "general|snap_prefix" "$UB_SNAP_PREFIX"
        hint "Snapshot prefix: $UB_SNAP_PREFIX instead of the old default $UB_SNAP_PREFIX_LEGACY - the ${UB_SNAP_PREFIX_LEGACY}... snapshots stay the engine's and age out by the retention"
    fi
    if command -v zfs >/dev/null 2>&1 && [[ -z "${OLD[general|snap_prefix]+x}" ]]; then
        local others
        others="$(zfs list -H -t snapshot -o name 2>/dev/null | snap_filter_not | sed -n 's/.*@\([a-zA-Z0-9_]*[-_]\).*/\1/p' \
                  | sort -u | head -5 | paste -sd' ' -)"
        [[ -n "$others" ]] && hint "ZFS snapshots of other tools present (prefix $others) - they stay untouched"
    fi
    pinit "kopia|container" ""
    pinit "kopia|identity"  ""
    pinit "flash|mode"      ""
    [[ -z "$(pget "flash|mode")" ]] && unset "P[flash|mode]"
    # apps and VMs with a Kopia source of their own (since 2.19): as they are - the office decides about them
    local k
    for k in "${!OLD[@]}"; do
        [[ "$k" == app\|* || "$k" =~ ^vm\|.+\|(kopia|folder|kopia_retention|kopia_ignore)$ ]] && P[$k]="${OLD[$k]}"
    done

    # From here on the proposals apply to inventory and plan
    _apply_P
    inv_scan
    docker_load
    vm_load
    if [[ -z "$(pget "kopia|container")" ]] || ! in_list "$(pget "kopia|container")" "${CT_NAMES[@]}"; then
        pset "kopia|container" "$(kopia_find_container)"
        _apply_P
    fi
}

# P -> CFG -> variables (so that inventory/plan/Kopia work with the proposals)
_apply_P() {
    local k
    CFG=(); CFG_SECTIONS=()
    local -A seen=()
    for k in "${!P[@]}"; do
        CFG[$k]="${P[$k]}"
        local sec="${k%|*}"
        [[ -z "${seen[$sec]:-}" ]] && { seen[$sec]=1; CFG_SECTIONS+=( "$sec" ); }
    done
    apply_settings
}

##############################################################################
# Step 3: offsite backup with Kopia - yes or no?
##############################################################################
step_offsite() {
    hdr "Offsite backup with Kopia?"
    explain <<'TXT'
Without Kopia everything stays on this server: local ZFS/btrfs snapshots and database
dumps. That helps against deleting by mistake and broken updates - not against fire,
theft, a power surge or the server failing completely.

What Kopia would do here:
- Every night, right after the snapshots, Kopia reads the frozen states under
  <mount_root>/<share> and uploads only what is new. Files are split into
  blocks and deduplicated (across shares too), compressed if you like.
- Everything is encrypted here on the server BEFORE uploading (AES-256-GCM or
  ChaCha20-Poly1305, key from the repository password). The storage provider sees
  only unreadable blocks - neither contents nor file names.
- The target is S3-compatible storage (e.g. Backblaze B2, Wasabi, MEGA S4, Hetzner,
  your own MinIO/Garage); Kopia can also do SFTP, WebDAV, B2, Azure, GCS or local.
- Kopia keeps versions according to the retention (e.g. 7 daily, 4 weekly,
  12 monthly, 3 yearly) and clears away the rest itself.

You set up the connection to the repository ONCE yourself in the KopiaUI (Repository >
S3: endpoint, bucket, access key, secret key, repository password). For security
reasons setup.sh deliberately does not ask for these: access keys and password should
never end up in scripts, logs or settings.ini - Kopia keeps them in its own
config inside the container.
IMPORTANT: Without the repository password the backup cannot be restored, not even by
the provider. Keep it separately (password manager and on paper).

What setup.sh checks and sets up afterwards: the Kopia container (e.g. imagegenius/kopia
from Community Apps, PUID=0/PGID=0), the mapping of <mount_root> (Read Only - Slave),
the connection, the policies per share and old sources.
Without Kopia now: it can be switched on any time later with another setup.sh run.
TXT
    local n has_ct="" dflt
    for n in "${CT_NAMES[@]}"; do is_kopia_image "${CT_IMAGE[$n]}" && has_ct="$n"; done
    if [[ -n "${OLD[kopia|enabled]+x}" ]]; then dflt="$([[ $(old "kopia|enabled") == yes ]] && echo y || echo n)"
    elif [[ -n "$has_ct" ]]; then dflt="y"
    else dflt="n"; fi
    [[ -n "$has_ct" ]] && hint "Kopia container found: $has_ct" || hint "No Kopia container found (image with 'kopia' in its name)"
    if ask_yn "Set up offsite backup with Kopia?" "$dflt"; then
        pset "kopia|enabled" yes
        if [[ ! -d "$MOUNT_ROOT" ]]; then
            if mkdir -p "$MOUNT_ROOT"; then ok "$MOUNT_ROOT created (target for the Kopia mapping)"
            else bad "$MOUNT_ROOT could not be created"; fi
        else
            ok "$MOUNT_ROOT exists"
        fi
        hint "Unraid keeps /mnt in RAM: after a reboot Docker creates the folder again when the Kopia container starts."
    else
        pset "kopia|enabled" no
        ok "Without Kopia: local snapshots and dumps. Shares can be 'snapshot' or 'off'."
    fi
    _apply_P
}

##############################################################################
# Step 4: shares
##############################################################################
declare -a SH=()     # order shown
declare -A SH_GB=()

# Proposal for a share not yet in settings.ini -> PROP_MODE, WHY[s]
# (no $(...): the reason in WHY must arrive in this shell)
PROP_MODE=""
share_propose() {
    local s="$1" gb n img b src r
    if ! share_name_ok "$s"; then why "$s" name_bad "" "name with @ : \" or | is not supported"; PROP_MODE=off; return; fi
    case "$s" in
        system)  why "$s" system "" "Docker image/libvirt - created anew"; PROP_MODE=off; return ;;
        domains)
            # Since 2.16 every VM is held (freeze/pause/shutdown) for the seconds of the snapshot:
            # with snapshots the VM disks are consistent; without them (live) they are not
            if [[ "${INV_METHOD[$s]:-}" == "snap" ]]; then
                why "$s" domains_snap "" "VM disks - local snapshot, each VM held for it (see the VMs)"; PROP_MODE=snapshot
            else
                why "$s" domains "" "VM vdisks - running VMs are not consistent, VM backup separately"; PROP_MODE=off
            fi
            return ;;
    esac
    # Time Machine target: already holds the backups of other computers and
    # keeps changing in large blocks
    local tm
    if tm="$(timemachine_reason "$s")"; then
        why "$s" timemachine "$tm" "Time Machine ($tm) - backups of other computers"; PROP_MODE=off; return
    fi
    if matches_any "$s" "${DRIFT_IGNORE[@]}"; then why "$s" drift_ignore "" "matches drift.ignore"; PROP_MODE=off; return; fi
    # Never back up the config, cache, logs or local repository of the Kopia container
    # (data mappings for Kopia sources of its own do not count)
    if [[ -n "$KOPIA_CONTAINER" ]]; then
        local dst
        while IFS='|' read -r src dst _; do
            [[ -z "$src" ]] && continue
            kopia_workdir "$dst" || continue
            r="$(path_share "$src")" || continue
            [[ "${r%%|*}" == "$s" && -z "${r#*|}" ]] && { why "$s" kopia_workdir "" "Kopia's config/cache/repository"; PROP_MODE=off; return; }
        done <<<"${CT_BINDS[$KOPIA_CONTAINER]:-}"
    fi
    gb="${SH_GB[$s]:-}"
    if [[ "$gb" == "-1" ]] || { [[ -n "$gb" ]] && (( gb > 500 )); }; then
        # Never switch container data (appdata & co.) off because of its size - there
        # the big folders (caches, blockchains ...) are left out, not the share
        if is_container_share "$s"; then
            why "$s" big_container "$gb" "container data, big ($(gb_fmt "$gb")) - leave out big folders right after"; PROP_MODE=kopia; return
        fi
        why "$s" big "$gb" "big ($(gb_fmt "$gb")) - decide on purpose"; PROP_MODE=off; return
    fi
    [[ "${INV_METHOD[$s]}" == "none" ]] && { why "$s" empty "" "still empty"; PROP_MODE=kopia; return; }
    # Size unknown (no ZFS, not measured): send nothing offsite without asking
    if [[ -z "$gb" ]] && ! is_container_share "$s"; then
        why "$s" size_unknown "" "size unknown - measure or decide on purpose"; PROP_MODE=snapshot; return
    fi
    why "$s" new "" "new"; PROP_MODE=kopia
}

share_table() {
    local i=0 s mode meth gb note
    say ""
    thead "$(printf '  %-3s %-24s %-20s %-8s %-9s %-9s %s' No Share Location Method Size Mode Note)"
    for s in "${SH[@]}"; do
        i=$((i+1))
        mode="$(pget "share|$s|mode")"
        meth="$(share_method "$s")"; [[ "$meth" == "snap" ]] && meth="${INV_LAYOUT[$s]:-}"
        gb="$(gb_fmt "${SH_GB[$s]:-}")"
        note="${WHY[$s]:-}"
        [[ "$mode" != "off" && -n "${INV_NOTE[$s]:-}" ]] && note="${note:+$note; }$(head -1 <<<"${INV_NOTE[$s]}")"
        case "$mode" in kopia) mode="${C_G}kopia${C_0}    " ;; snapshot) mode="${C_Y}snapshot${C_0} " ;; off) mode="${C_D}off${C_0}      " ;; esac
        trow "$(printf '  %-3s %-24s %-20s %-8s %-9s ' "$i" "${s:0:24}" "$(inv_locnames_short "$s" | cut -c1-20)" "$meth" "$gb")${mode} ${note}"
    done
}

share_details() { # editing one share in detail
    local s="$1" c x
    while :; do
        sub "Share '$s'"
        say "  Location:    $(inv_locnames "$s")  ($(share_method "$s"), ${INV_LAYOUT[$s]:-})"
        say "  Mode:        $(pget "share|$s|mode")"
        say "  Method:      $(pget "share|$s|method" auto)"
        say "  ZFS retention:      $(pget "share|$s|retention" "$(pget "zfs|retention")")  (daily weekly monthly)"
        say "  Kopia retention:    $(pget "share|$s|kopia_retention" "like all shares")  (latest hourly daily weekly monthly annual)"
        say "  Kopia ignores:"; plist "share|$s|kopia_ignore" | sed 's/^/                 /' | while IFS= read -r x; do say "$x"; done
        if [[ -n "${INV_CHILDREN[$s]:-}" ]]; then
            say "  Child datasets:"
            while IFS='|' read -r _ c _; do
                [[ -z "$c" ]] && continue
                if plist "share|$s|exclude_dataset" | grep -Fxq -- "$c"; then say "                 $c  (excluded)"
                else say "                 $c"; fi
            done <<<"${INV_CHILDREN[$s]}"
        fi
        [[ -n "${INV_NOTE[$s]:-}" ]] && printf '%s' "${INV_NOTE[$s]}" | while IFS= read -r x; do say "  Note:        $x"; done
        say "  [r] ZFS retention  [k] Kopia retention  [i] add ignore  [x] remove ignore"
        say "  [m] method auto/live  [e] child dataset out/in  [Enter] back"
        ask "  Choice" ""
        case "$REPLY" in
            r) ask "  Retention (d w m)" "$(pget "share|$s|retention" "$(pget "zfs|retention")")"
               [[ "$REPLY" =~ ^[0-9]+\ [0-9]+\ [0-9]+$ ]] && pset "share|$s|retention" "$REPLY" || say "  invalid" ;;
            k) ask "  Kopia: latest hourly daily weekly monthly annual (empty = like all shares)" "$(pget "share|$s|kopia_retention")"
               if [[ -z "$REPLY" ]]; then unset "P[share|$s|kopia_retention]"
               elif [[ "$REPLY" =~ ^([0-9]+|inherit)(\ ([0-9]+|inherit)){5}$ ]]; then pset "share|$s|kopia_retention" "$REPLY"
               else say "  invalid - six numbers or inherit"; fi ;;
            i) ask "  Rule (e.g. /cache/ - relative to the share)" ""
               [[ -n "$REPLY" ]] && plist_add "share|$s|kopia_ignore" "$REPLY" ;;
            x) ask "  Which rule to remove" ""
               [[ -n "$REPLY" ]] && plist_del "share|$s|kopia_ignore" "$REPLY" ;;
            m) if [[ "$(pget "share|$s|method" auto)" == "auto" ]]; then pset "share|$s|method" live; else pset "share|$s|method" auto; fi ;;
            e) ask "  Dataset name" ""
               if [[ -n "$REPLY" ]]; then
                   if plist "share|$s|exclude_dataset" | grep -Fxq -- "$REPLY"; then plist_del "share|$s|exclude_dataset" "$REPLY"
                   else plist_add "share|$s|exclude_dataset" "$REPLY"; fi
               fi ;;
            "") return 0 ;;
        esac
        _apply_P
    done
}

# Is the share a Time Machine target? Prints the reason (returns 0 = yes).
#   - SMB export "Yes/Time Machine" in Unraid's share config
#   - a Time Machine container (e.g. mbentley/timemachine) maps it
#   - the name says so (timemachine, time_machine, time-machine)
timemachine_reason() {
    local s="$1" n src r
    if grep -qE '^shareExport="et' "$UB_SHARES_CFG/$s.cfg" 2>/dev/null; then echo "SMB export"; return 0; fi
    for n in "${CT_NAMES[@]}"; do
        [[ "${CT_IMAGE[$n],,}" == *timemachine* || "${CT_IMAGE[$n],,}" == *time-machine* ]] || continue
        while IFS='|' read -r src _ _; do
            [[ -z "$src" ]] && continue
            r="$(path_share "$src")" || continue
            [[ "${r%%|*}" == "$s" ]] && { echo "container $n"; return 0; }
        done <<<"${CT_BINDS[$n]:-}"
    done
    case "${s,,}" in *timemachine*|*time_machine*|*time-machine*|*"time machine"*) echo "name"; return 0 ;; esac
    return 1
}

# Does the share hold the data of several containers (appdata & co.)?
is_container_share() {
    [[ "$1" == "appdata" ]] && return 0
    (( $(appdata_suggestions "$1" | cut -d'|' -f2 | sort -u | wc -l) >= 3 ))
}

# Target path in the Kopia container that holds Kopia's own working data?
kopia_workdir() { [[ "$1" =~ ^/(config|cache|logs|tmp|backups|repo|repository|app/config|app/cache|app/logs)(/|$) ]]; }

# Folders of an "appdata-like" share that belong to containers
appdata_suggestions() { # appdata_suggestions <share>  -> lines "folder|container"
    local s="$1" n src dst r rel
    for n in "${CT_NAMES[@]}"; do
        while IFS='|' read -r src dst _; do
            [[ -z "$src" ]] && continue
            [[ "$n" == "$KOPIA_CONTAINER" ]] && ! kopia_workdir "$dst" && continue
            r="$(path_share "$src")" || continue
            [[ "${r%%|*}" == "$s" ]] || continue
            rel="${r#*|}"; [[ -z "$rel" ]] && continue
            printf '%s|%s\n' "${rel%%/*}" "$n"
        done <<<"${CT_BINDS[$n]:-}"
    done | sort -u
}

step_shares() {
    hdr "Shares"
    explain <<'TXT'
A share is the unit you decide about. Per share:
  kopia     local snapshot + Kopia (offsite). Kopia source: <mount_root>/<share>
  snapshot  local snapshot only (quick to get back, no offsite)
  off       nothing at all
Method (derived from the file system):
  single    the share lies on one base -> the snapshot is mounted directly
  overlay   the share lies on several bases (pool + array, several disks) -> the snapshots
            are joined into one view with overlayfs; the pool comes first, as in /mnt/user
  split     like overlay, but with child datasets -> overlayfs shows no sub-mounts,
            so every base appears as a subfolder
  live      the base cannot do snapshots (e.g. XFS) -> /mnt/user/<share> is bound
            read-only; Kopia reads the live state (databases there are safe only through a dump)
Id: ZFS shares remember the dataset GUID, others the inode number of the folder.
If a share is renamed, backup.sh recognises it by that and reports the rename.
Size over 500 GB -> proposal "off", so that nothing big goes offsite without asking.
TXT
    local s mode gb
    SH=( "${INV_SHARES[@]}" )
    while IFS= read -r s; do [[ -n "$s" ]] && ! inv_has_share "$s" && SH+=( "$s" ); done < <(old_names share)

    # Sizes: ZFS at once, otherwise du (with a time limit)
    local measure="no"
    for s in "${INV_SHARES[@]}"; do [[ -z "${INV_GB[$s]}" && "${INV_METHOD[$s]}" != "none" ]] && measure="ask"; done
    if [[ "$measure" == "ask" && "$SIZE_TIMEOUT" != "0" ]]; then
        if ask_yn "Measure the size of shares without a ZFS dataset of their own? (du, at most ${SIZE_TIMEOUT}s per share, wakes array disks)" y; then
            measure="yes"
        fi
    fi
    for s in "${INV_SHARES[@]}"; do
        if [[ -n "${INV_GB[$s]}" ]]; then SH_GB[$s]="${INV_GB[$s]}"
        elif [[ "$measure" == "yes" && "${INV_METHOD[$s]}" != "none" ]]; then
            interactive && printf '  measuring %-40s\r' "$s" >/dev/tty
            SH_GB[$s]="$(inv_measure "$s" "$SIZE_TIMEOUT")"
        fi
    done
    interactive && printf '%-60s\r' "" >/dev/tty

    # Spotting renames: a vanished share with the same id
    local -A gone_by_id=()
    local id o k
    while IFS= read -r s; do
        [[ -z "$s" ]] && continue
        inv_has_share "$s" && [[ "${INV_METHOD[$s]}" != "none" ]] && continue
        id="$(old "share|$s|id")"; [[ -n "$id" && "$id" != *":?" ]] && gone_by_id[$id]="$s"
    done < <(old_names share)

    # Proposals
    for s in "${SH[@]}"; do
        o=""
        # A share without data (Unraid config only) has no id -> no matching
        if inv_has_share "$s" && ! old_has "share|$s" && [[ -n "${INV_ID[$s]:-}" ]]; then
            o="${gone_by_id[${INV_ID[$s]}]:-}"
        fi
        if [[ -n "$o" ]]; then
            for k in mode retention kopia_retention method kopia_ignore kopia_known exclude_dataset; do
                [[ -n "${OLD[share|$o|$k]+x}" ]] && P[share|$s|$k]="${OLD[share|$o|$k]}"
            done
            why "$s" renamed_from "$o" "renamed from '$o' - settings taken over"
            why "$o" renamed_to "$s" "no longer exists (renamed to '$s')"
        elif old_has "share|$s"; then
            [[ -z "${WHY[$s]:-}" ]] && why "$s" previous "" "as before"
            pinit "share|$s|mode" off
            # Kopia was just switched on: what ran only locally so far comes along
            if is_yes "$KOPIA_ENABLED" && [[ "$(old "kopia|enabled" yes)" == "no" && "$(pget "share|$s|mode")" == "snapshot" ]]; then
                pset "share|$s|mode" kopia; why "$s" kopia_on "" "Kopia newly switched on"
            fi
        else
            share_propose "$s"
            pset "share|$s|mode" "$PROP_MODE"
        fi
        # Without Kopia there is only local or nothing
        if ! is_yes "$KOPIA_ENABLED" && [[ "$(pget "share|$s|mode")" == "kopia" ]]; then
            pset "share|$s|mode" snapshot; why "$s" kopia_off_local "" "${WHY[$s]:+${WHY[$s]}; }Kopia off -> local"
        fi
        if ! inv_has_share "$s"; then
            [[ "${WHY[$s]:-}" == "no longer exists"* ]] || why "$s" gone "" "no longer exists - the entry is removed"
        fi
        [[ -n "${P[share|$s|retention]+x}" ]] || pinit "share|$s|retention" ""
        [[ -z "$(pget "share|$s|retention")" ]] && unset "P[share|$s|retention]"
        [[ -n "${P[share|$s|kopia_retention]+x}" ]] || pinit "share|$s|kopia_retention" ""
        [[ -z "$(pget "share|$s|kopia_retention")" ]] && unset "P[share|$s|kopia_retention]"
        [[ -n "${P[share|$s|method]+x}" ]]          || pinit "share|$s|method"          "auto"
        [[ -n "${P[share|$s|kopia_ignore]+x}" ]]    || pinit "share|$s|kopia_ignore"    ""
        [[ -n "${P[share|$s|exclude_dataset]+x}" ]] || pinit "share|$s|exclude_dataset" ""
        # the folders that go to Kopia (2.21): as recorded - share_known_all decides at the end
        [[ -z "${P[share|$s|kopia_known]+x}" && -n "${OLD[share|$s|kopia_known]+x}" ]] && P[share|$s|kopia_known]="${OLD[share|$s|kopia_known]}"
    done
    _apply_P

    # Always ignore Kopia's own folders in appdata-like shares
    local dir n line
    for s in "${SH[@]}"; do
        [[ "$(pget "share|$s|mode")" == "kopia" ]] || continue
        while IFS='|' read -r dir n; do
            [[ -z "$dir" ]] && continue
            if [[ "$n" == "$KOPIA_CONTAINER" ]] && ! plist "share|$s|kopia_ignore" | grep -Fxq "/$dir/"; then
                plist_add "share|$s|kopia_ignore" "/$dir/"
                hint "Share '$s': /$dir/ belongs to the Kopia container - ignored"
            fi
        done < <(appdata_suggestions "$s")
    done
    # Editing
    while :; do
        share_table
        interactive || break
        say ""
        if is_yes "$KOPIA_ENABLED"; then
            say "  ${C_D}k <no..> = kopia   s <no..> = local snapshot only   o <no..> = off   d <no> = details/ignores${C_0}"
        else
            say "  ${C_D}s <no..> = local snapshot   o <no..> = off   d <no> = details   (Kopia is off)${C_0}"
        fi
        say "  ${C_D}Numbers also as a range, e.g. 'o 4 7-9'. Enter = take it like this${C_0}"
        ask "Shares" ""
        [[ -z "$REPLY" ]] && break
        local cmd rest i
        cmd="${REPLY%% *}"; rest="${REPLY#"$cmd"}"
        for i in $(expand_nums "$rest"); do
            s="${SH[$((i-1))]:-}"; [[ -z "$s" ]] && continue
            case "$cmd" in
                k) if is_yes "$KOPIA_ENABLED"; then pset "share|$s|mode" kopia; why "$s" user "" "set by you"
                   else say "  Kopia is off - '$s' stays local (to switch Kopia on: setup.sh again, step 3)"; fi ;;
                s) pset "share|$s|mode" snapshot; why "$s" user "" "set by you" ;;
                o) pset "share|$s|mode" off;      why "$s" user "" "set by you" ;;
                d) share_details "$s" ;;
            esac
        done
        _apply_P
    done

    # Offer big folders in appdata-like shares
    if interactive; then
        for s in "${SH[@]}"; do
            [[ "$(pget "share|$s|mode")" == "kopia" ]] || continue
            local -a cand=()
            while IFS='|' read -r dir n; do [[ -n "$dir" ]] && cand+=( "$dir|$n" ); done < <(appdata_suggestions "$s")
            [[ ${#cand[@]} -lt 3 ]] && continue
            sub "Share '$s' holds container data"
            if ask_yn "  Show folder sizes to leave out big folders (caches, blockchains, media data)?" y; then
                local -A seen=()
                local k=0 b p bytes x slow sz
                local -a rows=()
                # Folders whose measuring takes longer than 60 s are very big:
                # they go to the top and show "> 60 s" instead of a number
                local SLOW=9000000000000000000
                for line in "${cand[@]}"; do
                    dir="${line%%|*}"; [[ -n "${seen[$dir]:-}" ]] && continue; seen[$dir]=1
                    bytes=0; slow=""
                    while IFS='|' read -r b _; do
                        [[ -z "$b" ]] && continue; p="${INV_BASE_PATH[$b]}/$s/$dir"
                        [[ -d "$p" ]] || continue
                        interactive && printf '  measuring /%-40s\r' "${dir:0:40}" >/dev/tty
                        x="$(timeout 60 du -sb "$p" 2>/dev/null)"
                        if (( $? == 124 )); then slow=1
                        else bytes=$(( bytes + $(awk '{s+=$1} END{print s+0}' <<<"$x") )); fi
                    done <<<"${INV_LOCS[$s]}"
                    [[ -n "$slow" ]] && bytes=$SLOW
                    rows+=( "$bytes|$dir|$(printf '%s\n' "${cand[@]}" | awk -F'|' -v d="$dir" '$1==d{print $2}' | paste -sd, -)" )
                done
                interactive && printf '%-60s\r' "" >/dev/tty
                mapfile -t rows < <(printf '%s\n' "${rows[@]}" | sort -t'|' -k1,1nr | head -15)
                thead "$(printf '  %-3s %10s  %-31s %s' No Size Folder Container)"
                for line in "${rows[@]}"; do
                    k=$((k+1)); IFS='|' read -r bytes dir n <<<"$line"
                    ignored_rel "$s" "$dir" && n="$n  (already ignored)"
                    if (( bytes == SLOW )); then sz="> 60 s"; else sz="$(human "$bytes")"; fi
                    trow "$(printf '  %-3s %10s  /%-30s %s' "$k" "$sz" "$dir/" "$n")"
                done
                ask "  Numbers Kopia should ignore (empty = none)" ""
                for k in $(expand_nums "$REPLY"); do
                    line="${rows[$((k-1))]:-}"; [[ -z "$line" ]] && continue
                    IFS='|' read -r _ dir _ <<<"$line"
                    plist_add "share|$s|kopia_ignore" "/$dir/"
                done
            fi
        done
    fi
    _apply_P
    share_known_all
}

# Kopia and what is new (2.21, lib/common.sh section 11): per share that goes to Kopia its top-level folders
# are recorded (kopia_known) - all of them the first time and when the share newly goes there (that is your
# decision for the whole share), afterwards what was known (a folder gone drops out) plus what you send there.
# A folder neither known nor left out is new: it waits, only local, until you decide (NEW_LIST, the plan's
# "waiting"). Never wakes a disk: a share whose part sleeps keeps its record, or gets its first one later.
share_known_all() {
    local s n x k l b
    new_local_state_load
    for s in "${SH[@]}"; do
        k="share|$s|kopia_known"
        if [[ "$(pget "share|$s|mode")" != "kopia" ]] || ! is_yes "$KOPIA_ENABLED" || ! inv_has_share "$s" \
           || [[ "${WHY[$s]:-}" == "no longer exists"* ]]; then
            unset "P[$k]"; continue
        fi
        share_top_live "$s"
        if [[ -n "${P[$k]+x}" ]] && plist "$k" | grep -Fxq '*'; then
            :       # a collection: every folder goes, new ones too (by hand: list folders instead)
        elif [[ -n "${P[$k]+x}" ]]; then
            # recorded before: as it is; a folder that is gone drops out (only when every part is awake)
            P[$k]="$(plist "$k" | while IFS= read -r x; do
                [[ "$x" =~ ^/[^/]+/$ ]] || continue
                [[ "$SK_ASLEEP" == "yes" ]] || in_list "${x:1:${#x}-2}" "${SK_DIRS[@]}" && printf '%s\n' "$x"
            done)"
        elif [[ "$SK_ASLEEP" == "yes" ]]; then
            unset "P[$k]"
            hint "Share '$s': a disk of it sleeps - which of its folders go to Kopia is recorded at a setup when it is awake (until then every folder goes there, new ones too)"
        elif (( ${#SK_DIRS[@]} > UB_KNOWN_MAX )); then
            # very many folders at its top: a collection (films, photos) - a new folder there is the collection growing
            P[$k]="*"
            hint "Share '$s': ${#SK_DIRS[@]} folders at its top - a collection: every folder goes to Kopia, new ones too (kopia_known = *; list folders instead to have new ones wait)"
        else
            # the first record: what is there and not left out goes to Kopia (as it did so far)
            P[$k]="$(for n in "${SK_DIRS[@]}"; do share_rules_hide "$s" "$n" || printf '/%s/\n' "$n"; done)"
            hint "Share '$s': $(plist "$k" | wc -l) folder(s) recorded that go to Kopia - folders that appear later stay local until you decide"
        fi
    done
    _apply_P
    new_local_scan_live
    (( ${#NEW_LIST[@]} )) || return 0
    if interactive; then
        sub "New folders - only in the local snapshots so far"
        for l in "${NEW_LIST[@]}"; do
            IFS=$'\x1f' read -r s n b _ <<<"$l"
            ask "  '$s/$n'${b:+ ($(human "$b"))} is new. k = local + Kopia, l = only local, Enter = decide later" ""
            case "$REPLY" in
                k) plist_add "share|$s|kopia_known" "/$n/" ;;
                l) plist_add "share|$s|kopia_ignore" "/$(new_rule_name "$n")/" ;;
            esac
        done
        _apply_P
        new_local_scan_live
    fi
    for l in "${NEW_LIST[@]}"; do
        IFS=$'\x1f' read -r s n b _ <<<"$l"
        hint "Share '$s': the new folder '$n'${b:+ ($(human "$b"))} stays local until you decide - Kopia leaves it out"
    done
}

declare -A NC_GROUP=()     # Nextcloud container -> the other containers of the same instance

##############################################################################
# Step 4: containers
##############################################################################
declare -A CT_STOP=() CT_WHY=()
declare -A CT_CODE=() CT_ARG=() CT_PREV=() CT_RISK=()   # the reason as a code (for user interfaces)
ctwhy() { CT_WHY[$1]="$4"; CT_CODE[$1]="$2"; CT_ARG[$1]="$3"; }   # ctwhy <container> <code> <value> <text>

container_needs_stop() { # sets CT_WHY; 0 = stop it
    local n="$1" src r s rel touched="" ign=""
    if [[ -n "${CT_VOLUMES[$n]}" ]]; then ctwhy "$n" volumes "" "has Docker volumes"; return 0; fi
    while IFS='|' read -r src _ _; do
        [[ -z "$src" ]] && continue
        [[ "$src" == "$UB_MNT"* ]] || continue
        r="$(path_share "$src")" || continue
        s="${r%%|*}"; rel="${r#*|}"
        if [[ "$s" == "*" ]]; then ctwhy "$n" binds_root "$src" "maps all of $src"; return 0; fi
        [[ "$(pget "share|$s|mode" off)" == "off" ]] && continue
        if ignored_rel "$s" "$rel"; then ign+="$s/$rel "; continue; fi
        touched+="$s${rel:+/$rel} "
    done <<<"${CT_BINDS[$n]:-}"
    if [[ -n "$touched" ]]; then ctwhy "$n" writes "${touched% }" "writes to ${touched% }"; return 0; fi
    if [[ -n "$ign" ]]; then ctwhy "$n" ignored "${ign% }" "data only in ignored paths (${ign% })"; return 1; fi
    ctwhy "$n" no_data "" "no backed-up data"; return 1
}

# Does the container belong to the Unraid Secretary Office (maps its folder)? The office
# writes only small files atomically (tmp + rename) and shows the run - it always keeps running.
# (Only the containers of the office's old Compose stack did; the plugin has none.)
is_office_container() { # is_office_container <name>
    local src office rel
    office="$(cd "$UB_DIR/.." && pwd -P)"
    office="${office#/mnt/*/}"
    while IFS='|' read -r src _ _; do
        [[ -z "$src" ]] && continue
        rel="${src#/mnt/*/}"
        [[ "$rel" == "$office" || "$rel" == "$office/"* ]] && return 0
    done <<<"${CT_BINDS[$1]:-}"
    return 1
}

step_containers() {
    hdr "Containers"
    explain <<'TXT'
Why stop them? A running service has files half written, SQLite journals open,
caches not flushed. A snapshot of that is "crash-consistent" - like pulling the plug. Stopped,
it is clean. They are stopped only for the seconds of the snapshots, not while Kopia runs.
Proposal "keep running" when a container writes nothing that is backed up (its paths
lie in off shares or in folders Kopia ignores) - stopping it would gain nothing there.
Order: apps first, then databases, network containers last (e.g. a VPN whose
network others share); starting goes the other way round, databases only once they are "healthy".
Dumps: in addition to the snapshot a logical SQL dump - readable, restorable regardless
of the version, and checked right away before stopping (closing line, number of tables).
Nextcloud: maintenance mode before the dump, so that database and files match.
Docker volumes live in the Docker folder (system) - nobody backs that up.
TXT
    local n i t
    local -a run=()
    for n in "${CT_NAMES[@]}"; do [[ "${CT_RUNNING[$n]}" == "true" ]] && run+=( "$n" ); done
    say "  ${#CT_NAMES[@]} containers, ${#run[@]} running."

    # --- Stopping
    local had_cfg="no"; old_has "docker" && [[ "$HAVE_SETTINGS" == "yes" ]] && had_cfg="yes"
    # --apply: the decisions name all containers (known) - also at the first setup
    [[ "$MODE" == "apply" && -n "${OLD[docker|known]+x}" ]] && had_cfg="yes"
    local -a nostop=() known=()
    mapfile -t nostop < <(old_list "docker|no_stop")
    mapfile -t known  < <(old_list "docker|known")
    P[docker|no_stop]=""
    for n in "${CT_NAMES[@]}"; do
        [[ "$n" == "$KOPIA_CONTAINER" ]] && { CT_STOP[$n]="no"; ctwhy "$n" kopia "" "Kopia - always keeps running"; continue; }
        if is_office_container "$n"; then
            CT_STOP[$n]="no"; ctwhy "$n" office "" "Unraid Secretary Office - always keeps running"; continue
        fi
        if [[ "$had_cfg" == "yes" ]] && in_list "$n" "${known[@]}"; then
            if in_list "$n" "${nostop[@]}"; then CT_STOP[$n]="no"; else CT_STOP[$n]="yes"; fi
            CT_PREV[$n]=1
            if container_needs_stop "$n" && [[ "${CT_STOP[$n]}" == "no" ]]; then
                CT_RISK[$n]=1
                CT_WHY[$n]="as before; ${C_Y}CAREFUL${C_0} ${CT_WHY[$n]} - its snapshot is only crash-consistent"
            else
                CT_WHY[$n]="as before; ${CT_WHY[$n]}"
            fi
            continue
        fi
        # new since the last setup (2.21): keeps running until you decide - backup.sh doesn't stop it either
        if [[ "$had_cfg" == "yes" ]]; then
            CT_STOP[$n]="no"
            container_needs_stop "$n" && CT_RISK[$n]=1
            ctwhy "$n" new "" "new - keeps running until you decide"
            continue
        fi
        if container_needs_stop "$n"; then CT_STOP[$n]="yes"; else CT_STOP[$n]="no"; fi
        # Media servers keep running: stopping them would break running streams. Their
        # SQLite database in the snapshot is then only crash-consistent (usually enough).
        if [[ "${CT_STOP[$n]}" == "yes" ]] && is_media_server "${CT_IMAGE[$n]}"; then
            CT_STOP[$n]="no"; CT_RISK[$n]=1
            ctwhy "$n" media_server "${CT_ARG[$n]:-}" "media server - keeps running (streams); database in the snapshot only crash-consistent"
        fi
    done

    while :; do
        say ""
        thead "$(printf '  %-3s %-26s %-7s %-12s %s' No Container Running Backup Reason)"
        i=0
        for n in "${CT_NAMES[@]}"; do
            i=$((i+1))
            trow "$(printf '  %-3s %-26s %-7s ' "$i" "${n:0:26}" "$([[ ${CT_RUNNING[$n]} == true ]] && echo yes || echo no)")$([[ ${CT_STOP[$n]} == yes ]] && printf '%s' "${C_Y}stop${C_0}        " || printf '%s' "${C_G}keep running${C_0}") ${CT_WHY[$n]}"
        done
        interactive || break
        say "  ${C_D}s <no..> = stop   k <no..> = keep running   Enter = take it${C_0}"
        ask "Containers" ""
        [[ -z "$REPLY" ]] && break
        local cmd="${REPLY%% *}" rest="${REPLY#"${REPLY%% *}"}"
        for i in $(expand_nums "$rest"); do
            n="${CT_NAMES[$((i-1))]:-}"; [[ -z "$n" || "$n" == "$KOPIA_CONTAINER" ]] && continue
            case "$cmd" in s|a) CT_STOP[$n]="yes"; ctwhy "$n" user "" "set by you" ;; k|w) CT_STOP[$n]="no"; ctwhy "$n" user "" "set by you" ;; esac
        done
    done
    for n in "${CT_NAMES[@]}"; do
        [[ "$n" == "$KOPIA_CONTAINER" ]] && continue
        [[ "${CT_STOP[$n]}" == "no" ]] && plist_add "docker|no_stop" "$n"
    done
    if [[ "$MODE" == "apply" ]]; then
        # --apply: what the decisions name is known - a container that came after the plan stays new (2.21)
        P[docker|known]="$(for n in "${CT_NAMES[@]}"; do in_list "$n" "${known[@]}" && printf '%s\n' "$n"; done)"
    else
        P[docker|known]="$(printf '%s\n' "${CT_NAMES[@]}")"
    fi
    # apps the user chose not to back up (the office's apps step); only containers that still exist
    P[docker|skip]="$(old_list "docker|skip" | while IFS= read -r n; do in_list "$n" "${CT_NAMES[@]}" && printf '%s\n' "$n"; done)"

    # --- Docker volumes
    local v vn vd vtxt
    for n in "${CT_NAMES[@]}"; do
        [[ -n "${CT_VOLUMES[$n]}" && "$n" != "$KOPIA_CONTAINER" ]] || continue
        vtxt=""
        while IFS='|' read -r vn vd; do
            [[ -z "$vn" ]] && continue
            if [[ "$vn" =~ ^[0-9a-f]{64}$ ]]; then vtxt+="anonymous at $vd, "; else vtxt+="$vn at $vd, "; fi
        done <<<"${CT_VOLUMES[$n]}"
        wrn "'$n' uses Docker volumes (${vtxt%, }) - they live in the Docker folder (system) and are NOT in the backup."
        hint "         Fix: in the template, map a path into a backed-up share (e.g. /mnt/user/appdata/$n)."
    done

    _apply_P
}

##############################################################################
# Step 5b: VMs
##############################################################################
declare -A VM_WHY=()      # why the proposal: agent | channel | no_agent | shut_off | cannot | previous

# vm_share_mode <name>  -> the mode of the share holding the VM's first disk ("" if none)
vm_share_mode() {
    local t s b fs ds share
    while IFS='|' read -r t s b fs ds share; do
        [[ -n "$t" && -n "$share" ]] && { pget "share|$share|mode" off; return; }
    done <<<"${VM_DISKS[$1]:-}"
}

step_vms() {
    [[ "$VM_SERVICE" == "yes" && ${#VM_NAMES[@]} -gt 0 ]] || return 0
    hdr "VMs"
    explain <<'TXT'
A VM's disks are files in a share (usually domains) and so in that share's snapshot. What
matters is how the VM is treated while the snapshot is taken:
  freeze    the guest agent (qemu-guest-agent in the VM) flushes and freezes its file
            systems for the seconds of the snapshot - the best result, the VM keeps running
  pause     the VM stops for those seconds, no guest agent needed - like pulling the plug,
            but no write is cut in half
  shutdown  shut down cleanly before and started again after - the safest, takes minutes
  none      keeps running - its disks are only crash-consistent
A VM whose disks lie in a dataset of its own (Unraid makes one per VM folder on ZFS) can be
left out (off) and can keep its snapshots longer or shorter than its share.
TXT
    local n i mode prep why
    for n in "${VM_NAMES[@]}"; do
        if old_has "vm|$n"; then why="previous"
        elif [[ "$HAVE_SETTINGS" == "yes" ]]; then why="new"       # since the last setup (2.21): not held until you decide
        elif [[ "${VM_SNAP[$n]}" != "yes" ]]; then why="cannot"
        elif [[ "${VM_AGENT[$n]}" == "yes" ]]; then why="agent"
        elif [[ "${VM_AGENT[$n]}" == "channel" ]]; then why="channel"
        elif [[ "${VM_STATE[$n]}" != "running" ]]; then why="shut_off"
        else why="no_agent"; fi
        VM_WHY[$n]="$why"
        pinit "vm|$n|mode" "snapshot"
        case "$why" in
            agent|channel) prep="freeze" ;;
            new)           prep="none" ;;
            *)             prep="pause" ;;
        esac
        pinit "vm|$n|prepare" "$prep"
        pinit "vm|$n|retention" ""
        [[ -z "$(pget "vm|$n|retention")" || -z "${VM_OWN_DS[$n]}" ]] && unset "P[vm|$n|retention]"
    done
    _apply_P
    while :; do
        say ""
        thead "$(printf '  %-3s %-24s %-9s %-7s %-9s %-9s %s' No VM State Agent Backup Prepare Disks)"
        i=0
        for n in "${VM_NAMES[@]}"; do
            i=$((i+1))
            local where="" t s b fs ds share
            while IFS='|' read -r t s b fs ds share; do [[ -n "$t" ]] && where+="${ds:-${b:-?}} "; done <<<"${VM_DISKS[$n]}"
            [[ -z "${VM_OWN_DS[$n]}" ]] && where+="(shared dataset) "
            [[ "${VM_SNAP[$n]}" != "yes" ]] && where+="(no snapshot: ${VM_SNAP[$n]}) "
            trow "$(printf '  %-3s %-24s %-9s %-7s %-9s %-9s %s' "$i" "${n:0:24}" "${VM_STATE[$n]:0:9}" "${VM_AGENT[$n]}" \
                "$(pget "vm|$n|mode")" "$(pget "vm|$n|prepare")" "$where")"
        done
        interactive || break
        say "  ${C_D}f/p/s/n <no..> = freeze / pause / shutdown / none   o <no..> = off   b <no..> = back up   Enter = take it${C_0}"
        ask "VMs" ""
        [[ -z "$REPLY" ]] && break
        local cmd="${REPLY%% *}" rest="${REPLY#"${REPLY%% *}"}"
        for i in $(expand_nums "$rest"); do
            n="${VM_NAMES[$((i-1))]:-}"; [[ -z "$n" ]] && continue
            case "$cmd" in
                f) pset "vm|$n|prepare" freeze ;;
                p) pset "vm|$n|prepare" pause ;;
                s) pset "vm|$n|prepare" shutdown ;;
                n) pset "vm|$n|prepare" none ;;
                o) if [[ -n "${VM_OWN_DS[$n]}" ]]; then pset "vm|$n|mode" off
                   else say "  '$n' shares its dataset - it can't be left out on its own"; fi ;;
                b) pset "vm|$n|mode" snapshot ;;
            esac
        done
        _apply_P
    done
    for n in "${VM_NAMES[@]}"; do
        [[ "$(vm_share_mode "$n")" == "off" && "$(pget "vm|$n|mode")" != "off" ]] \
            && hint "VM '$n': its share is not backed up (mode=off) - the VM isn't either"
        [[ "${VM_SNAP[$n]}" == "yes" ]] || wrn "VM '$n': $(case "${VM_SNAP[$n]}" in block) echo "a whole device as a disk";; live) echo "a disk on a file system without snapshots";; missing) echo "a disk file was not found";; *) echo "no disk";; esac) - no snapshot holds it"
        [[ "$(pget "vm|$n|prepare")" == "freeze" && "${VM_AGENT[$n]}" == "no" ]] \
            && wrn "VM '$n': set to freeze, but its guest agent does not answer - a run pauses it instead"
    done
}

##############################################################################
# Step 6: databases (containers and Compose stacks) and Nextcloud
##############################################################################
# Where does a container's data lie? -> "appdata/mariadb", "volume:/data!", "in the container!"
ct_data_where() {
    local n="$1" src dst r out=""
    while IFS='|' read -r src dst _; do
        [[ -z "$src" ]] && continue
        r="$(path_share "$src")" || continue
        [[ "${r%%|*}" == "*" ]] && continue
        local rel="${r#*|}"
        out+="${r%%|*}${rel:+/$rel} "
    done <<<"${CT_BINDS[$n]:-}"
    local vn vd
    while IFS='|' read -r vn vd; do [[ -n "$vn" ]] && out+="volume:$vd! "; done <<<"${CT_VOLUMES[$n]:-}"
    [[ -z "$out" ]] && out="in the container! "
    printf '%s' "${out% }"
}

declare -a DB_ROWS=() DB_MISSING=() NC_ROWS=()   # for --plan
declare -A NC_DATA=()                            # Nextcloud container -> its datadirectory (inside the container)
step_databases() {
    hdr "Databases and Nextcloud"
    explain <<'TXT'
The search covers all containers - single ones as well as those from Compose stacks -
and also the stack files of the Compose Manager (that also finds database services
of stacks not running right now). A database is recognised by
  - environment variables the server image sets itself (PG_MAJOR, MARIADB_VERSION,
    MONGO_VERSION, REDIS_VERSION ...) - that also catches renamed or derived images,
  - the image name (mariadb, mysql, postgres, pgvecto-rs, mongo, redis ...),
  - the standard port (3306, 5432, 27017, 6379).
MariaDB/MySQL, Postgres and MongoDB get a logical dump before the snapshot:
readable, restorable regardless of the version, checked right away. Their files also lie
in the snapshot - cleanly, because the container is stopped for it.
Redis & co. are caches: no dump; whatever they keep on disk is in the snapshot.
Other databases (InfluxDB, CouchDB, ...) are backed up by the snapshot of the stopped container.
If the data lies in a Docker volume or only inside the container itself, it is gone after an
image update, or missing from the backup - that is marked.
TXT
    local n t r why where proposal i=0 svc stack img cname
    local -a rows=() dump_cands=()
    local -A dtype=()
    local -a known=(); mapfile -t known < <(old_list "docker|known")

    # Containers
    for n in "${CT_NAMES[@]}"; do
        r="$(ct_db "$n")"
        old_has "dump|$n" && r="$(old "dump|$n|type")|as before"
        [[ -z "$r" ]] && continue
        t="${r%%|*}"; why="${r#*|}"
        where="$(ct_data_where "$n")"
        case "$t" in
            mariadb|postgres|mongodb) proposal="dump"; dump_cands+=( "$n" ); dtype[$n]="$t" ;;
            cache) proposal="no dump (cache)" ;;
            *)     proposal="snapshot (container is stopped)" ;;
        esac
        [[ "${CT_RUNNING[$n]}" != "true" ]] && proposal+=", not running right now"
        in_list "$n" "${DOCKER_NO_STOP[@]}" && proposal+=", ${C_Y}keeps running during the snapshot${C_0}"
        stack="-"; [[ -n "${CT_PROJECT[$n]}" ]] && stack="${CT_PROJECT[$n]}/${CT_SERVICE[$n]}"
        rows+=( "$n|$stack|$t|$why|$where|$proposal" )
        DB_ROWS+=( "$n"$'\x1f'"$stack"$'\x1f'"$t"$'\x1f'"$why"$'\x1f'"$where" )
    done

    # Compose stacks: database services without a container
    compose_scan
    local -a missing=()
    for r in "${COMPOSE_DB[@]}"; do
        IFS='|' read -r stack svc img t why cname <<<"$r"
        compose_container "$stack" "$svc" "$cname" >/dev/null && continue
        missing+=( "$stack|$svc|$img|$t" )
        DB_MISSING+=( "$stack"$'\x1f'"$svc"$'\x1f'"$img"$'\x1f'"$t" )
    done

    if [[ ${#rows[@]} -eq 0 && ${#missing[@]} -eq 0 ]]; then
        hint "No databases found"
    else
        say ""
        thead "$(printf '  %-22s %-22s %-9s %-16s %-26s %s' Container Stack/service Kind "detected by" Data Proposal)"
        for r in "${rows[@]}"; do
            IFS='|' read -r n stack t why where proposal <<<"$r"
            [[ "$where" == *"!"* ]] && where="${C_R}${where}${C_0}"
            trow "$(printf '  %-22s %-22s %-9s %-16s ' "${n:0:22}" "${stack:0:22}" "$t" "${why:0:16}")$(printf '%-26s' "$where") $proposal"
        done
        for r in "${missing[@]}"; do
            IFS='|' read -r stack svc img t <<<"$r"
            wrn "Stack '$stack': database service '$svc' ($t, $img) has no container - start the stack and run setup.sh again, then the dump is proposed"
        done
    fi
    local e
    for e in "${COMPOSE_ERR[@]}"; do hint "Compose stack ${e%%|*} could not be read: ${e#*|}"; done

    while IFS= read -r n; do
        [[ -n "$n" ]] && ! in_list "$n" "${dump_cands[@]}" && wrn "Dump for '$n' is in settings.ini, the container no longer exists - the entry is removed"
    done < <(old_names dump)

    for n in "${dump_cands[@]}"; do
        local dflt="y"
        [[ "$HAVE_SETTINGS" == "yes" ]] && ! old_has "dump|$n" && in_list "$n" "${known[@]}" && dflt="n"
        if [[ "$MODE" == "apply" ]]; then old_has "dump|$n" && dflt="y" || dflt="n"; fi
        if ask_yn "  Dump of '$n' (${dtype[$n]})?" "$dflt"; then
            P[dump|$n|type]="${dtype[$n]}"
            ok "'$n': ${dtype[$n]} dump before every snapshot"
            [[ "${CT_RUNNING[$n]}" == "true" ]] && dump_probe "$n" "${dtype[$n]}"
        else
            unset "P[dump|$n|type]"
        fi
    done

    # --- Nextcloud
    sub "Nextcloud"
    # Several containers can serve ONE Nextcloud (app + cron with a
    # shared config.php). They are recognised by the instanceid and
    # asked about together - backup.sh switches the maintenance mode only once.
    local found=0 occ u id g ndf val names
    local -a groups=() m=()
    local -A members=() occ_of=()
    for n in "${CT_NAMES[@]}"; do
        is_nextcloud_image "${CT_IMAGE[$n]}" || old_has "nextcloud|$n" || continue
        [[ "${CT_RUNNING[$n]}" == "true" ]] || { wrn "Nextcloud '$n' is not running - occ cannot be checked"; continue; }
        occ=""
        for t in /var/www/html/occ /app/www/public/occ /config/www/nextcloud/occ /var/www/nextcloud/occ; do
            docker exec "$n" test -f "$t" 2>/dev/null && { occ="$t"; break; }
        done
        [[ -z "$occ" ]] && { hint "'$n' looks like Nextcloud but has no occ - skipped"; continue; }
        found=1
        u="$(docker exec "$n" stat -c %U "$occ" 2>/dev/null)"; [[ -z "$u" || "$u" == root || "$u" == UNKNOWN ]] && u="www-data"
        id="$(docker exec -u "$u" "$n" php "$occ" config:system:get instanceid 2>/dev/null | tr -d '\r')"
        # where it keeps the files (for the office's warning when that share is backed up less than the app)
        NC_DATA[$n]="$(docker exec -u "$u" "$n" php "$occ" config:system:get datadirectory 2>/dev/null | tr -d '\r' | head -1)"
        g="${id:-?$n}"
        [[ -z "${members[$g]:-}" ]] && groups+=( "$g" )
        members[$g]+="${members[$g]:+ }$n"
        occ_of[$n]="$occ"
    done
    for g in "${groups[@]}"; do
        read -r -a m <<<"${members[$g]}"
        names="$(printf "'%s' + " "${m[@]}")"; names="${names% + }"
        (( ${#m[@]} > 1 )) && hint "$names are the same Nextcloud (shared config.php) - one maintenance mode for all"
        # Default "n" only when none is in settings.ini but all were already
        # known (so turned down on purpose). "continue" only when all of them
        # had it - otherwise the safe "abort" applies.
        ndf="n"; [[ "$HAVE_SETTINGS" == "yes" ]] || ndf="y"
        val="continue"
        for n in "${m[@]}"; do
            { old_has "nextcloud|$n" || ! in_list "$n" "${known[@]}"; } && ndf="y"
            [[ "$(old "nextcloud|$n|preexisting_maintenance" abort)" == "continue" ]] || val="abort"
        done
        if [[ "$MODE" == "apply" ]]; then
            ndf="n"; for n in "${m[@]}"; do old_has "nextcloud|$n" && ndf="y"; done
        fi
        NC_ROWS+=( "${m[*]}"$'\x1f'"${occ_of[${m[0]}]}"$'\x1f'"$val" )
        if ask_yn "  Put Nextcloud $names (occ ${occ_of[${m[0]}]}) into maintenance mode during dump and snapshot?" "$ndf"; then
            for n in "${m[@]}"; do
                P[nextcloud|$n|preexisting_maintenance]="$val"
                NC_GROUP[$n]="$(printf '%s\n' "${m[@]}" | grep -Fxv -- "$n" | paste -sd' ' -)"
            done
            ok "$names: maintenance mode during the backup (occ ${occ_of[${m[0]}]})"
        else
            for n in "${m[@]}"; do unset "P[nextcloud|$n|preexisting_maintenance]" "NC_GROUP[$n]"; done
        fi
    done
    (( found )) || hint "No Nextcloud detected"
    _apply_P
}

dump_probe() { # a short access test, so that the first run does not fail on it
    local n="$1" t="$2" v
    case "$t" in
        mariadb)
            for v in MARIADB_ROOT_PASSWORD MYSQL_ROOT_PASSWORD MARIADB_PASSWORD MYSQL_PASSWORD; do
                docker exec "$n" sh -c "[ -n \"\${$v:-}\" ]" 2>/dev/null && { ok "'$n': access through \$$v"; return; }
            done
            wrn "'$n': no password in the environment variables - the dump will fail" ;;
        postgres)
            if docker exec "$n" sh -c 'command -v pg_dumpall' >/dev/null 2>&1; then ok "'$n': pg_dumpall present"
            else wrn "'$n': pg_dumpall is missing in the container"; fi ;;
        mongodb)
            if docker exec "$n" sh -c 'command -v mongodump' >/dev/null 2>&1; then ok "'$n': mongodump present"
            else wrn "'$n': mongodump is missing in the container - the dump will fail"; fi ;;
    esac
}

##############################################################################
# Step 5: snapshots and general settings
##############################################################################
step_general() {
    hdr "Snapshots, retention, flash"
    explain <<'TXT'
ZFS "d w m": the d newest snapshots, plus the newest one of each of the last w calendar
weeks and of the last m months. Can be overridden per share (appdata e.g. 7 0 0 - Kopia
then provides the depth in time). Snapshots only cost what has changed since.
btrfs: one snapshot per disk and run, retention in days. Emergency brake: if the free
space falls below the limit, the oldest are deleted early. Browse them under
<view_root>/<disk> (symlinks - they hold no disk, the array stop stays free).
Flash: if /boot lies on ZFS, it is snapshotted and goes to Kopia as the source "_flash";
otherwise as a tar.gz into the backup place (kernel images and plugin packages left out -
Unraid downloads those again).
TXT
    inv_flash
    local havez=0 haveb=0 b
    for b in "${INV_BASES[@]}"; do
        [[ "${INV_BASE_FS[$b]}" == "zfs" ]] && havez=1
        [[ "${INV_BASE_FS[$b]}" == "btrfs" ]] && haveb=1
    done
    say "  Bases: $(for b in "${INV_BASES[@]}"; do printf '%s(%s) ' "$b" "${INV_BASE_FS[$b]}"; done)"
    if (( havez )); then
        ask "  Keep local ZFS snapshots: daily weekly monthly" "$(pget "zfs|retention")"
        [[ "$REPLY" =~ ^[0-9]+\ [0-9]+\ [0-9]+$ ]] && pset "zfs|retention" "$REPLY"
        ask "  Prefix of the ZFS snapshots" "$(pget "general|snap_prefix")"
        snap_prefix_ok "$REPLY" && pset "general|snap_prefix" "$REPLY"
    fi
    if (( haveb )); then
        ask "  Keep btrfs snapshots (days)" "$(pget "btrfs|keep_days")"; is_uint "$REPLY" && pset "btrfs|keep_days" "$REPLY"
        ask "  btrfs: delete the oldest snapshots early below (GB free)" "$(pget "btrfs|min_free_gb")"; is_uint "$REPLY" && pset "btrfs|min_free_gb" "$REPLY"
        if ask_yn "  Snapshot all btrfs array disks (for browsing), even without backed-up shares?" \
                  "$([[ $(pget "btrfs|snapshot_all") == yes ]] && echo y || echo n)"; then
            pset "btrfs|snapshot_all" yes
        else pset "btrfs|snapshot_all" no; fi
    fi
    if (( havez || haveb )); then
        # 2.28: a pool whose disks sleep at the run's time - woken by its snapshot (as before), or left out that night
        interactive && say "  Sleeping pools at night: woken for the snapshot (as before) - or left asleep: their shares are"
        interactive && say "  then left out that night (a share on a pool that sleeps every night is never backed up until it is"
        interactive && say "  awake at the run's time; the run warns after ${UB_ASLEEP_NIGHTS} nights in a row). The backup place is always woken."
        if ask_yn "  Leave sleeping pools asleep (their shares left out that night)?" \
                  "$([[ $(pget "general|asleep_pools") == skip ]] && echo y || echo n)"; then
            pset "general|asleep_pools" skip
        else pset "general|asleep_pools" wake; fi
    fi

    sub "Backup place (the packages of apps and VMs)"
    explain <<'TXT'
Every run writes a package per app and per VM to the backup place: templates or compose files,
the database dumps, the VM configuration (XML, NVRAM, TPM state) - small files, overwritten
every night; the big data stays in the snapshots. They need a backup share of their own - never
appdata: the dumps protect the databases in appdata; if they lay next to them, a failure of that
pool would take both. They go to <share>/unraid-backup (root only), in the office's share
UnraidSecretaryOffice to its folder backup/ (one folder per desk).
The packages keep their history in the snapshots of that share: it takes at least local
snapshots (better on a ZFS or btrfs pool), and should go to Kopia so the packages are offsite too.
TXT
    local ds sugg cand
    ds="$(pget "general|dumps_share")"
    # The office's own share, newly created: that is where the desks keep their data
    if [[ "$ds" != "$UB_OFFICE_SHARE" ]] && in_list "$UB_OFFICE_SHARE" "${SH[@]}" && ! old_has "share|$UB_OFFICE_SHARE"; then
        [[ -n "$ds" ]] && hint "The office's share $UB_OFFICE_SHARE is new - proposed as the backup place instead of '$ds' (the next run moves the dumps)"
        ds="$UB_OFFICE_SHARE"
    fi
    if [[ -z "$ds" ]]; then
        # a share made for backups: "Backups"/"Backup" first, then anything with "backup" in its name
        for cand in "${SH[@]}"; do [[ "${cand,,}" =~ ^backups?$ ]] && { sugg="$cand"; break; }; done
        if [[ -z "${sugg:-}" ]]; then
            for cand in "${SH[@]}"; do
                [[ "${cand,,}" == *backup* && "${cand,,}" != *timemachine* && "${cand,,}" != *time_machine* ]] && { sugg="$cand"; break; }
            done
        fi
        ds="${sugg:-}"
    fi
    while :; do
        ask "  Share for the backup place" "$ds"
        ds="$REPLY"
        # its snapshots keep the packages' history: at least a local snapshot - proposed here; decisions
        # from the office that switch it off are refused below (settings.ini is then not written)
        if [[ -n "$ds" && "$MODE" != "apply" && "$(pget "share|$ds|mode" off)" == "off" ]] && in_list "$ds" "${SH[@]}" \
           && [[ -z "$(dumps_share_problem "$ds" snapshot)" ]]; then
            pset "share|$ds|mode" snapshot; _apply_P
            why "$ds" backup_place "" "the backup place - its snapshots keep the packages' history"
            hint "Share '$ds' holds the backup place: proposed as a local snapshot (its snapshots keep the packages' history)"
        fi
        local prob; prob="$(dumps_share_problem "$ds" "$(pget "share|$ds|mode" off)")"
        [[ -z "$prob" ]] && break
        bad "$(dumps_share_text "$prob" "$ds")"
        interactive || break
    done
    pset "general|dumps_share" "$ds"
    if [[ -z "${prob:-}" ]]; then
        ok "Backup place: $(dumps_path "$ds")"
        is_yes "$KOPIA_ENABLED" && [[ "$(pget "share|$ds|mode")" != "kopia" ]] \
            && wrn "Backup place '$ds' does not go to Kopia (mode=$(pget "share|$ds|mode")) - the packages would stay local only; set the share to kopia"
        [[ "$(share_method "$ds")" == "live" ]] \
            && wrn "Backup place '$ds' lies on a file system without snapshots - its packages would keep no history, only the newest state; choose a share on a ZFS or btrfs pool"
    fi

    sub "Flash ($UB_BOOT)"
    local fm
    if [[ -n "$FLASH_DATASET" ]]; then
        say "  $UB_BOOT lies on ZFS ($FLASH_DATASET) - snapshot possible"
        fm="$(old "flash|mode" snapshot)"
    else
        say "  $UB_BOOT is ${FLASH_FS:-?} - backed up as an archive in the backup place"
        fm="$(old "flash|mode" tar)"; [[ "$fm" == "snapshot" ]] && fm="tar"
    fi
    if is_yes "$KOPIA_ENABLED"; then
        hint "snapshot = ZFS snapshot of $UB_BOOT, goes to Kopia as the source '_flash'"
    else
        hint "snapshot = local ZFS snapshot only (Kopia is off); tar = archive in the backup place"
    fi
    ask "  Back up the flash: snapshot | tar | off" "$fm"
    case "$REPLY" in snapshot|tar|off) fm="$REPLY" ;; esac
    [[ "$fm" == "snapshot" && -z "$FLASH_DATASET" ]] && { wrn "snapshot needs ZFS - taking tar"; fm="tar"; }
    pset "flash|mode" "$fm"
    pinit "flash|kopia_ignore" "$(printf '%s\n' '/bz*' '/EFI*/' '/previous/' '/config/plugins/nvidia-driver/')"
    pinit "flash|tar_exclude"  "$(printf '%s\n' './bz*' './previous' './config/plugins/*/packages')"

    sub "VM configuration (libvirt.img)"
    if mountpoint -q /etc/libvirt; then
        say "  libvirt.img is mounted at /etc/libvirt: XML, NVRAM and TPM state of all VMs"
        hint "tar = its contents every night as a whole in the backup place (server/libvirt.tar.gz); each VM's own configuration goes to its package either way"
        pinit "libvirt|mode" tar
    else
        say "  VM service off - nothing to back up"
        pinit "libvirt|mode" off
    fi
    ask "  Back up the VM configuration: tar | off" "$(pget "libvirt|mode")"
    case "$REPLY" in tar|off) pset "libvirt|mode" "$REPLY" ;; esac
    _apply_P
}

##############################################################################
# Step 5c: partners (since 2.27)
##############################################################################
# The [partner "<id>"] sections are the Team Lead's agreement, never the setup's choice: taken from its pairs
# (data/partner/pairs.json, the pairs this office sends to - partner_pairs_load), or, without that file, as
# settings.ini (or the sections --forget kept) has them. The units - [share|vm "<n>"] partner = <id>, [general]
# partner_place = <id> - are the user's, from the decisions; kept only for a partner that exists, a unit that is one
# dataset of its own (partner_unit_dataset) and that the run snapshots (not off). Since 2.29 the pairs' send.units say
# what each partner agreed to keep: a unit no partner agreed to is partner_ok false, why not_agreed - its partners stay
# in its key (the run skips it as not_agreed until the partner agrees; then it goes along without another setup).
declare -A PARTNER_OKU=() PARTNER_WHYU=()     # unit -> yes/no, why not (the plan's partner_ok / partner_why)
PARTNER_SOURCE="none"                         # pairs | settings | none
step_partners() {
    local id k u n s line nm addr port rate keep units
    local -A pair=() agreed=()
    PARTNER_OKU=(); PARTNER_WHYU=()
    for k in "${!P[@]}"; do [[ "$k" == partner\|* ]] && unset "P[$k]"; done
    if partner_pairs_load; then
        PARTNER_SOURCE="pairs"
        for line in "${PAIRS[@]}"; do
            IFS='|' read -r id nm addr port rate units <<<"$line"
            [[ -n "${pair[$id]:-}" ]] && continue
            pair[$id]=1
            agreed[$id]=" $units "
            pset "partner|$id|name" "$nm"; pset "partner|$id|address" "$addr"
            pset "partner|$id|port" "$port"; pset "partner|$id|rate_mbit" "$rate"
        done
        while IFS= read -r id; do
            [[ -n "$id" && -z "${pair[$id]:-}" ]] && hint "Partner '$(old "partner|$id|name" "$id")' ($id) is no longer paired at the Team Lead - its section and its units go"
        done < <(old_names partner)
    else
        while IFS= read -r id; do
            partner_id_ok "$id" || continue
            pair[$id]=1
            for k in name address port rate_mbit; do [[ -n "${OLD[partner|$id|$k]+x}" ]] && P[partner|$id|$k]="${OLD[partner|$id|$k]}"; done
        done < <(old_names partner)
        (( ${#pair[@]} )) && PARTNER_SOURCE="settings"
    fi
    _apply_P
    # unit_ok <unit>: PARTNER_OKU / PARTNER_WHYU - one dataset of its own (partner_unit_dataset), and (2.29, the pairs
    # known) agreed by at least one partner (send.units) - else not_agreed
    unit_ok() {
        local v any=""
        if ! partner_unit_dataset "$1"; then PARTNER_OKU[$1]="no"; PARTNER_WHYU[$1]="$PU_WHY"; return 0; fi
        PARTNER_OKU[$1]="yes"
        [[ "$PARTNER_SOURCE" == "pairs" ]] && (( ${#agreed[@]} )) || return 0
        for v in "${!agreed[@]}"; do [[ "${agreed[$v]}" == *" $1 "* ]] && any=1; done
        [[ -n "$any" ]] || { PARTNER_OKU[$1]="no"; PARTNER_WHYU[$1]="not_agreed"; }
        return 0
    }
    # keep_ids <key> <unit> <off 1/0>: the key's partners that exist - none for a unit that can't travel or is off; one
    # the partner hasn't agreed to keep (yet) stays, said so (2.29: the run skips it until the partner agrees)
    keep_ids() {
        local key="$1" unit="$2" off="$3" v out=""
        [[ -n "${OLD[$key]+x}" || -n "${P[$key]+x}" ]] || return 0
        while IFS= read -r v; do
            [[ -n "$v" ]] || continue
            if [[ -z "${pair[$v]:-}" ]]; then continue
            elif [[ "${PARTNER_OKU[$unit]}" != "yes" && "${PARTNER_WHYU[$unit]}" != "not_agreed" ]]; then hint "${unit}: not one dataset of its own (${PARTNER_WHYU[$unit]}) - it can't go to partner $(pget "partner|$v|name" "$v")"; continue
            elif [[ "$off" == 1 ]]; then hint "${unit}: not backed up (off) - nothing of it goes to partner $(pget "partner|$v|name" "$v")"; continue; fi
            if [[ -n "${agreed[$v]+x}" && "${agreed[$v]}" != *" $unit "* ]]; then
                hint "${unit}: not agreed with $(pget "partner|$v|name" "$v") yet - ask at the Team Lead («Change what $(pget "general|server" "$(hostname -s 2>/dev/null)") sends…»); until then it doesn't go there"
            fi
            grep -Fxq -- "$v" <<<"$out" || out+="$v"$'\n'
        done < <(if [[ -n "${OLD[$key]+x}" ]]; then printf '%s\n' "${OLD[$key]}"; else printf '%s\n' "${P[$key]}"; fi)
        if [[ -n "$out" ]]; then P[$key]="${out%$'\n'}"; else unset "P[$key]"; fi
    }
    for s in "${SH[@]}"; do
        unit_ok "share:$s"
        keep_ids "share|$s|partner" "share:$s" "$([[ "$(pget "share|$s|mode" off)" == off ]] && echo 1 || echo 0)"
    done
    for n in "${VM_NAMES[@]}"; do
        unit_ok "vm:$n"
        s="$(vm_share_mode "$n")"
        keep_ids "vm|$n|partner" "vm:$n" "$([[ "$(pget "vm|$n|mode" snapshot)" == off || "${s:-off}" == off ]] && echo 1 || echo 0)"
    done
    unit_ok place
    keep_ids "general|partner_place" place 0
    unset -f keep_ids unit_ok
    _apply_P
    (( ${#pair[@]} )) || return 0
    sub "Partners"
    for id in "${!pair[@]}"; do
        local units
        units="$(partner_units "$id" | paste -sd' ')"
        say "  $(pget "partner|$id|name" "$id") ($id, $(pget "partner|$id|address"):$(pget "partner|$id|port" 22)$( (( $(pget "partner|$id|rate_mbit" 0) > 0 )) && echo ", at most $(pget "partner|$id|rate_mbit") Mbit/s")): ${units:-nothing yet}"
        [[ -f "$UB_PARTNER_DIR/$id.key" ]] || wrn "Partner $(pget "partner|$id|name" "$id"): its key is missing ($UB_PARTNER_DIR/$id.key) - pair anew at the Team Lead"
    done
    hint "What goes to a partner is ticked per share and VM in Mr. Backupsy's setup («also to <partner>»); only ZFS datasets of their own travel"
    if [[ "${PARTNER_WHYU[place]:-}" == "not_agreed" ]]; then hint "The backup place is not agreed with a partner yet - ask at the Team Lead («Change what $(pget "general|server" "$(hostname -s 2>/dev/null)") sends…»)"
    elif [[ "${PARTNER_OKU[place]}" != "yes" ]]; then hint "The backup place is not a dataset of its own (${PARTNER_WHYU[place]}) - it can't go to a partner"; fi
    return 0
}

##############################################################################
# Step 6: Kopia
##############################################################################
KOPIA_MAIN=-1          # index of the mapping that covers mount_root
KOPIA_POLICY_READY="no"
KOPIA_FAIL=""          # why the Kopia part did not get through (code, for --plan)
KOPIA_PROBE=""         # live test: 0 = ok, 1 = the container sees nothing, 2 = not possible

step_kopia() {
    hdr "Setting up Kopia"
    if ! is_yes "$KOPIA_ENABLED"; then
        KOPIA_FAIL="off"
        hint "Kopia is off - skipped. To switch it on: setup.sh again, step 3."
        return 0
    fi
    explain <<'TXT'
Mapping: Kopia only needs <mount_root>, access mode "Read Only - Slave".
- Read Only: Kopia cannot change anything at the sources.
- Slave: at its start the container gets a copy of the mount view. But backup.sh
  mounts the snapshots only at night, AFTER the container started. Without slave Kopia
  would see only empty folders there (without slave Kopia would have to restart every night).
  With slave the host passes new mounts and unmounts on into the running container -
  in this direction only.
- Bind mounts arrive WRITABLE in the container despite "remount,ro". backup.sh therefore
  creates them in a private area, makes them ro there and only then moves them.
Live test: setup.sh mounts a tiny tmpfs and checks whether the container sees it.
Identity (user@host): sources belong to it. If it changes (e.g. hostname =
container id after re-creating), new sources start from zero - thanks to deduplication
the data is not uploaded again, but the history is separate.
Policies: one on <mount_root> (retention, "manual only", ignores, compression),
every share inherits it and carries only its differences (own ignores, own retention).
"Manual only", so that the Kopia server never starts the sources itself - in the daytime
the folders would be empty. one-file-system=false, so that child datasets come along.
TXT
    plan_build
    if [[ ${#PLAN_KOPIA[@]} -eq 0 && "$PLAN_FLASH" != "snapshot" && ${#PLAN_KITEMS[@]} -eq 0 ]]; then
        KOPIA_FAIL="no_shares"; hint "No share goes to Kopia - Kopia part skipped"; return 0
    fi

    # --- Container
    local c n cands=()
    c="$(pget "kopia|container" "")"
    if [[ -z "$c" ]] || ! in_list "$c" "${CT_NAMES[@]}"; then
        for n in "${CT_NAMES[@]}"; do is_kopia_image "${CT_IMAGE[$n]}" && cands+=( "$n" ); done
        if [[ ${#cands[@]} -eq 0 ]]; then
            KOPIA_FAIL="no_container"; bad "No Kopia container found (image with 'kopia' in its name)"; return 1
        elif [[ ${#cands[@]} -gt 1 ]]; then
            say "  Several Kopia containers: ${cands[*]}"
            ask "  Which one backs up this backup" "${cands[0]}"; c="$REPLY"
        else c="${cands[0]}"; fi
    fi
    pset "kopia|container" "$c"; _apply_P
    in_list "$c" "${CT_NAMES[@]}" || { KOPIA_FAIL="no_container"; bad "Container '$c' does not exist"; return 1; }
    ok "Container '$c' (${CT_IMAGE[$c]})"
    [[ "${CT_RUNNING[$c]}" == "true" ]] || { KOPIA_FAIL="not_running"; bad "'$c' is not running - please start it and run 'setup.sh --kopia' again"; return 1; }

    # --- Mapping
    sub "Path mapping"
    kopia_mounts_load
    local i
    TROW=0
    for i in "${!KM_SRC[@]}"; do
        trow "$(printf '    %-28s -> %-22s %-3s %s' "${KM_SRC[$i]}" "${KM_DST[$i]}" "$([[ ${KM_RW[$i]} == true ]] && echo rw || echo ro)" "${KM_PROP[$i]:-rprivate}")"
    done
    local legacy="$UB_MNT/backup-snapshots"
    if [[ "$MOUNT_ROOT" != "$legacy" ]] && ! k_map "$MOUNT_ROOT" && k_map "$legacy"; then
        # The snapshots move to /mnt/addons only once Kopia sees them there; the container
        # path stays the same, so the Kopia sources (and their history) stay the same too
        wrn "The Kopia container still maps $legacy - so the snapshots stay there for now. In the Kopia template change only the Host Path to $MOUNT_ROOT (the Container Path stays), then set up again"
        pset "general|mount_root" "$legacy"; _apply_P
    fi
    if ! k_map "$MOUNT_ROOT"; then
        KOPIA_FAIL="no_mapping"; bad "No mapping covers $MOUNT_ROOT"
        mapping_help; return 1
    fi
    KOPIA_MAIN=$KMAP_IDX
    local msrc="${KM_SRC[$KOPIA_MAIN]}" mdst="${KM_DST[$KOPIA_MAIN]}"
    if [[ "$msrc" == "$MOUNT_ROOT" && "$mdst" == "$MOUNT_ROOT" ]]; then ok "$MOUNT_ROOT -> $MOUNT_ROOT (the same paths inside and outside)"
    elif [[ "$msrc" == "$MOUNT_ROOT" ]]; then ok "$MOUNT_ROOT -> $mdst (Kopia sources are called $mdst/<share>)"
    else hint "$MOUNT_ROOT is reached through the wider mapping $msrc -> $mdst - that works, only $MOUNT_ROOT is needed"; fi
    case "${KM_PROP[$KOPIA_MAIN]}" in
        slave|rslave) ok "Propagation ${KM_PROP[$KOPIA_MAIN]}" ;;
        shared|rshared) ok "Propagation ${KM_PROP[$KOPIA_MAIN]} (slave is enough)" ;;
        *) KOPIA_FAIL="propagation"; bad "Propagation '${KM_PROP[$KOPIA_MAIN]:-rprivate}' - new snapshot mounts stay invisible to Kopia"
           mapping_help; return 1 ;;
    esac
    [[ "${KM_RW[$KOPIA_MAIN]}" == "true" ]] && wrn "The mapping is writable - access mode 'Read Only - Slave' recommended" \
                                             || ok "The mapping is read-only"
    for i in "${!KM_SRC[@]}"; do
        [[ $i -eq $KOPIA_MAIN ]] && continue
        [[ "${KM_SRC[$i]}" == "$UB_MNT"/* ]] || continue
        hint "Another data mapping ${KM_SRC[$i]} -> ${KM_DST[$i]} - $UB_NAME does not need it; Kopia sources of your own may (see below)"
    done

    # --- Live test of the mount propagation
    kopia_probe_propagation; local pr=$?; KOPIA_PROBE=$pr
    case $pr in
        0) ok "Live test: a new mount under $MOUNT_ROOT appears in the container at once" ;;
        1) KOPIA_FAIL="probe"; bad "Live test: the container does NOT see new mounts under $MOUNT_ROOT - re-create the container after changing the template?"; return 1 ;;
        *) wrn "Live test not possible (the tmpfs mount under $MOUNT_ROOT failed)" ;;
    esac

    # --- Read-only for sub-mounts too?
    kopia_mountinfo_load
    local mp rwlist=""
    for mp in "${!KMI[@]}"; do
        [[ "$mp" == "$mdst" || "$mp" == "$mdst/"* ]] || continue
        [[ ",${KMI[$mp]}," == *",rw,"* ]] && rwlist+="$mp "
    done
    if [[ -n "$rwlist" && "${KM_RW[$KOPIA_MAIN]}" != "true" ]]; then
        wrn "Writable inside the container despite a read-only mapping: $rwlist"
        hint "Docker applies 'ro' to sub-mounts too only from version 25 and kernel 5.12; disks mounted later (Unassigned Devices) stay writable"
    elif [[ "${KM_RW[$KOPIA_MAIN]}" != "true" ]]; then
        ok "All sub-mounts are read-only inside the container"
    fi

    # --- Repository
    sub "Repository"
    if ! kopia_status_load; then
        KOPIA_FAIL="no_repo"; bad "Kopia is not connected to a repository (or 'kopia' does not answer inside the container)"
        hint "Connect in the KopiaUI, then 'setup.sh --kopia'. setup.sh deliberately creates no connection."
        return 1
    fi
    ok "Kopia $KOPIA_VERSION, connected as $KOPIA_ID, storage: ${KOPIA_STORAGE:-?}"
    hint "Config: $KOPIA_CONFIG_FILE"
    if [[ "$KOPIA_HOST" =~ ^[0-9a-f]{12}$ ]]; then
        wrn "The hostname in the repository is a container id ($KOPIA_HOST) - after re-creating the container all sources would be 'foreign'."
        hint "Set it in the KopiaUI or: docker exec $c kopia repository set-client --hostname=<name>"
    fi
    local old_id; old_id="$(old "kopia|identity" "")"
    [[ -n "$old_id" && "$old_id" != "$KOPIA_ID" ]] && wrn "The identity was $old_id so far - new snapshots end up in new sources"
    pset "kopia|identity" "$KOPIA_ID"
    if [[ "$KOPIA_SERVER_UID" == "0" ]]; then
        ok "The Kopia server runs as root - script and server share cache and logs without permission trouble"
    else
        KOPIA_FAIL="not_root"; bad "The Kopia server runs as UID $KOPIA_SERVER_UID - backup.sh does NOT start Kopia like this"
        explain <<'TXT'
Snapshots have to run as root, otherwise every file that belongs only to its owner
is missing (Nextcloud data, database folders, ...). Kopia writes to cache and logs
on every call; root creates folders with permissions 0700 there. The server (UID 99) can
then no longer open the repository - "permission denied" in the KopiaUI, also for
your own scheduled sources.
Fix, once: Docker > kopia > Edit:  PUID = 0,  PGID = 0  > Apply.
Then server and script run as the same user; root can read the existing files.
Until then setup.sh calls Kopia as the server's UID, so that nothing else breaks.
TXT
        local cdir n
        cdir="$(docker exec "$c" cat "$KOPIA_CONFIG_FILE" 2>/dev/null | jq -r '.caching.cacheDirectory // empty')"
        [[ -n "$cdir" && "$cdir" != /* ]] && cdir="$(dirname "$KOPIA_CONFIG_FILE")/$cdir"
        if [[ -n "$cdir" ]]; then
            n="$(docker exec "$c" find "$cdir" -uid 0 2>/dev/null | wc -l)"
            (( n > 0 )) && wrn "The server cache $cdir already holds $n entries owned by root - the server has trouble with that already; fixed by PUID=0"
        fi
    fi

    # --- Policies
    kopia_policies_load
    sub "Policies"
    say "  Policy on $(k_path "$MOUNT_ROOT") - all shares inherit it, per share only the difference is stored:"
    say "  Retention:"
    say "    latest $(pget "kopia|keep_latest")  hourly $(pget "kopia|keep_hourly")  daily $(pget "kopia|keep_daily")  weekly $(pget "kopia|keep_weekly")  monthly $(pget "kopia|keep_monthly")  annual $(pget "kopia|keep_annual")"
    say "  Compression: $(pget "kopia|compression")   (inherit = as set globally in Kopia)"
    say "  Schedule: manual only - Kopia never starts these sources itself"
    say "  Global ignores: $(plist "kopia|ignore" | paste -sd' ' -)"
    if interactive && ! ask_yn "  Leave it like this?" y; then
        local f
        for f in keep_latest keep_hourly keep_daily keep_weekly keep_monthly keep_annual; do
            ask "    $f" "$(pget "kopia|$f")"; [[ "$REPLY" =~ ^([0-9]+|inherit)$ ]] && pset "kopia|$f" "$REPLY"
        done
        ask "    compression (inherit, none, zstd, zstd-fastest, s2-default, ...)" "$(pget "kopia|compression")"
        [[ "$REPLY" =~ ^[a-z0-9-]+$ ]] && pset "kopia|compression" "$REPLY"
        ask "    Global ignores (separated by spaces)" "$(plist "kopia|ignore" | paste -sd' ' -)"
        P[kopia|ignore]="$(tr ' ' '\n' <<<"$REPLY" | sed '/^$/d')"
        hint "Compression only applies to newly uploaded data; existing blobs stay as they are."
    fi
    _apply_P; plan_build
    KOPIA_POLICY_READY="yes"
}

mapping_help() {
    say ""
    say "  ${C_B}How to set up the mapping (Unraid > Docker > $KOPIA_CONTAINER > Edit):${C_0}"
    say "    Add another Path, Port, Variable, Label or Device > Config Type: Path"
    say "      Container Path:  /uso   (any path - Kopia names its sources after it: /uso/<share>, /uso/.apps/<app>;"
    say "                       changing it later makes new sources - in the same repository nothing is uploaded"
    say "                       twice, but every file is read once more, and the old sources keep their history"
    say "                       under the old names until you remove them in Kopia)"
    say "      Host Path:       $MOUNT_ROOT"
    say "      Access Mode:     Read Only - Slave   (not just 'Read Only' - without slave"
    say "                       the running container sees no new snapshot mounts)"
    say "    Apply. Then: setup.sh --kopia"
}

##############################################################################
# Step 7: writing and applying
##############################################################################
w_kv()   { printf '%s = %s\n' "$1" "$2"; }
w_list() { local k="$1" v; while IFS= read -r v; do [[ -n "$v" ]] && printf '%s = %s\n' "$k" "$v"; done < <(plist "$2"); }
w_c()    { printf '# %s\n' "$@"; }

# settings.ini as it would be written now (to stdout) - write_settings stores it,
# settings_pending compares it with the file on disk
settings_render() {
    local s n
    {
        echo "###############################################################################"
        echo "# $UB_NAME - settings.ini for $(pget "general|server")"
        echo "# Written by setup.sh $UB_VERSION on $(date '+%Y-%m-%d %H:%M'). Can be changed by hand, then:"
        echo "#   setup.sh --check    check it"
        echo "#   setup.sh --kopia    align the Kopia policies"
        echo "# Another setup.sh run takes over all values but writes the comments anew."
        echo "#"
        echo "# Format: [section] or [type \"name\"], below it  key = value"
        echo "# Lists: the key several times. Comments only on lines of their own (# or ;)."
        echo "###############################################################################"
        echo
        echo "[general]"
        w_c "Name in the notifications"
        w_kv server "$(pget "general|server")"
        w_c "Where the snapshots are mounted during the run: <mount_root>/<share>"
        w_kv mount_root "$(pget "general|mount_root")"
        w_c "Browse btrfs snapshots: <view_root>/<disk> (symlinks, they hold no disk)"
        w_kv view_root "$(pget "general|view_root")"
        w_c "ZFS snapshots are called <prefix>YYYYMMDD-HHMM; only this prefix is cleaned up"
        w_c "(with the default uso-backup- also the older unraidbackup-... ones, by the same retention)"
        w_kv snap_prefix "$(pget "general|snap_prefix")"
        w_c "Subfolder for btrfs snapshots on every btrfs disk/btrfs pool"
        w_kv btrfs_snap_dir "$(pget "general|btrfs_snap_dir")"
        w_c "How many logs to keep (number of runs)"
        w_kv keep_logs "$(pget "general|keep_logs")"
        w_c "A backup share of its own for the packages of apps and VMs: <share>/unraid-backup (never appdata);"
        w_c "its snapshots keep their history"
        w_kv dumps_share "$(pget "general|dumps_share")"
        w_c "At least this much space (GB) must be free in the backup place"
        w_kv min_free_gb "$(pget "general|min_free_gb")"
        w_c "yes = snapshots stay mounted until the next run. Then also a"
        w_c "User Script 'At Stopping of Array' with: backup.sh --unmount"
        w_kv keep_mounts "$(pget "general|keep_mounts")"
        w_c "Notification on success too (yes/no)"
        w_kv notify_success "$(pget "general|notify_success")"
        w_c "Sleeping pools at the run's time: wake = woken for the snapshot (default); skip = left out that night"
        w_c "(their shares are not snapshotted, nothing of them goes to Kopia; a warning after ${UB_ASLEEP_NIGHTS} nights in a row)"
        if [[ "$(pget "general|asleep_pools")" == "skip" || "${ORIG_ASLEEP_SET:-}" == "yes" ]]; then
            w_kv asleep_pools "$(pget "general|asleep_pools" wake)"
        fi
        if [[ -n "$(plist "general|partner_place")" ]]; then
            w_c "The backup place's dataset goes to these partners too (the id of a [partner] section, several times)"
            w_list partner_place "general|partner_place"
        fi
        echo
        echo "[zfs]"
        w_c "Local ZFS snapshots: daily weekly monthly (can be overridden per share)"
        w_kv retention "$(pget zfs\|retention)"
        echo
        echo "[btrfs]"
        w_kv keep_days "$(pget btrfs\|keep_days)"
        w_c "Below this much free space (GB) the oldest snapshots are deleted early"
        w_kv min_free_gb "$(pget btrfs\|min_free_gb)"
        w_c "yes = all btrfs array disks get snapshots (for browsing), even without a Kopia share"
        w_kv snapshot_all "$(pget btrfs\|snapshot_all)"
        echo
        echo "[drift]"
        w_c "New shares matching one of these patterns are not reported by backup.sh"
        w_list ignore "drift|ignore"
        w_c "Report the same drift again after this many days"
        w_kv remind_days "$(pget drift\|remind_days)"
        echo
        echo "[docker]"
        w_c "all = stop all running containers for the snapshot (except no_stop and Kopia)"
        w_c "none = stop none (dumps + crash-consistent snapshots only)"
        w_kv stop "$(pget docker\|stop)"
        w_kv stop_timeout "$(pget docker\|stop_timeout)"
        w_list no_stop "docker|no_stop"
        w_c "Apps not backed up on purpose: keep running like no_stop, no dump, Kopia leaves their folders out"
        w_list skip "docker|skip"
        w_c "Containers present at the last setup.sh - backup.sh reports everything else as new"
        w_list known "docker|known"
        echo
        echo "[flash]"
        w_c "snapshot = ZFS snapshot of /boot to Kopia, tar = archive in the backup place (flash/), off = nothing"
        w_kv mode "$(pget flash\|mode)"
        w_list kopia_ignore "flash|kopia_ignore"
        w_list tar_exclude "flash|tar_exclude"
        echo
        echo "[libvirt]"
        w_c "All of libvirt.img (XML, NVRAM, TPM state, networks): tar = archive in the backup place (server/), off = nothing"
        w_c "(each VM's own configuration goes to its package either way)"
        w_kv mode "$(pget libvirt\|mode tar)"
        echo
        echo "[kopia]"
        w_c "yes = shares with mode=kopia go offsite; no = local snapshots and dumps only"
        w_kv enabled "$(pget "kopia|enabled" yes)"
        w_kv container "$(pget "kopia|container")"
        w_c "Identity as the repository said at the last setup.sh (user@host)"
        w_kv identity "$(pget "kopia|identity")"
        w_c "Retention in Kopia (number or inherit)"
        for n in keep_latest keep_hourly keep_daily keep_weekly keep_monthly keep_annual; do w_kv "$n" "$(pget "kopia|$n")"; done
        w_c "inherit = like the global Kopia policy; otherwise none, zstd, zstd-fastest, s2-default, ..."
        w_kv compression "$(pget "kopia|compression")"
        w_c "Ignore rules for all sources"
        w_list ignore "kopia|ignore"
        local first_partner="yes"
        while IFS= read -r n; do
            [[ -z "$n" ]] && continue
            if [[ "$first_partner" == "yes" ]]; then
                first_partner="no"
                echo
                echo "# --- Partner offices (the Team Lead's pairs) ----------------------------------"
                w_c "Each night the run sends its ZFS snapshots of the shares, VMs and the backup place that name a"
                w_c "partner (partner = <id>) to that office, through its door (ssh). The key and known_hosts are"
                w_c "$UB_PARTNER_DIR/<id>.key and <id>.known - never in this file."
                w_c "name, address, port: the partner office as paired; rate_mbit: at most so many Mbit/s, 0 = unlimited"
            fi
            echo
            echo "[partner \"$n\"]"
            w_kv name "$(pget "partner|$n|name" "$n")"
            w_kv address "$(pget "partner|$n|address")"
            w_kv port "$(pget "partner|$n|port" 22)"
            w_kv rate_mbit "$(pget "partner|$n|rate_mbit" 0)"
        done < <(printf '%s\n' "${!P[@]}" | sed -n 's/^partner|\([0-9a-f]\{8\}\)|address$/\1/p' | LC_ALL=C sort)
        while IFS= read -r n; do
            [[ -z "$n" ]] && continue
            echo
            echo "[nextcloud \"$n\"]"
            [[ -n "${NC_GROUP[$n]:-}" ]] && w_c "the same Nextcloud as: ${NC_GROUP[$n]} (shared config.php) - maintenance mode only once"
            w_c "abort = abort the run if maintenance mode was already on; continue = back up anyway"
            w_kv preexisting_maintenance "$(pget "nextcloud|$n|preexisting_maintenance" abort)"
        done < <(printf '%s\n' "${!P[@]}" | sed -n 's/^nextcloud|\(.*\)|preexisting_maintenance$/\1/p' | sort)
        while IFS= read -r n; do
            [[ -z "$n" ]] && continue
            echo
            echo "[dump \"$n\"]"
            w_kv type "$(pget "dump|$n|type")"
        done < <(printf '%s\n' "${!P[@]}" | sed -n 's/^dump|\(.*\)|type$/\1/p' | sort)
        local first_vm="yes"
        while IFS= read -r n; do
            [[ -z "$n" ]] && continue
            # a VM that is gone drops out - unless the VM service is off (then nobody knows)
            [[ "$VM_SERVICE" == "yes" ]] && ! in_list "$n" "${VM_NAMES[@]}" && continue
            if [[ "$first_vm" == "yes" ]]; then
                first_vm="no"
                echo
                echo "# --- VMs ----------------------------------------------------------------------"
                w_c "mode       snapshot = in its share's snapshot, off = its own dataset left out"
                w_c "prepare    freeze (guest agent) | pause | shutdown | none - for the seconds of the snapshot"
                w_c "retention  own local ZFS retention 'daily weekly monthly' (only with a dataset of its own)"
            fi
            echo
            echo "[vm \"$n\"]"
            w_kv mode "$(pget "vm|$n|mode" snapshot)"
            w_kv prepare "$(pget "vm|$n|prepare" none)"
            [[ -n "$(pget "vm|$n|retention")" ]] && w_kv retention "$(pget "vm|$n|retention")"
            if [[ "$(pget "vm|$n|kopia")" == "yes" ]]; then
                w_kv kopia yes
                w_list folder "vm|$n|folder"
                [[ -n "$(pget "vm|$n|kopia_retention")" ]] && w_kv kopia_retention "$(pget "vm|$n|kopia_retention")"
                w_list kopia_ignore "vm|$n|kopia_ignore"
            fi
            w_list partner "vm|$n|partner"
        done < <(printf '%s\n' "${!P[@]}" | sed -n 's/^vm|\(.*\)|mode$/\1/p' | sort)
        local first_app="yes"
        while IFS= read -r n; do
            [[ -z "$n" || "$(pget "app|$n|kopia")" != "yes" ]] && continue
            if [[ "$first_app" == "yes" ]]; then
                first_app="no"
                echo
                echo "# --- Apps with a Kopia source of their own -----------------------------------"
                w_c "kopia            yes = its folders and its package are a Kopia source of their own: <container path>/.apps/<name>"
                w_c "                 (the shares leave those folders out; VMs: the same keys in their [vm] section)"
                w_c "folder           <share>/<folder> it keeps data in (several times)"
                w_c "kopia_retention  its own 'latest hourly daily weekly monthly annual' (missing = as in [kopia])"
                w_c "kopia_ignore     Kopia ignore rule relative to its source: /<share>/<folder>/... (several times)"
            fi
            echo
            echo "[app \"$n\"]"
            w_kv kopia yes
            w_list folder "app|$n|folder"
            [[ -n "$(pget "app|$n|kopia_retention")" ]] && w_kv kopia_retention "$(pget "app|$n|kopia_retention")"
            w_list kopia_ignore "app|$n|kopia_ignore"
        done < <(printf '%s\n' "${!P[@]}" | sed -n 's/^app|\(.*\)|kopia$/\1/p' | LC_ALL=C sort)
        echo
        echo "# --- Shares -------------------------------------------------------------------"
        w_c "mode             kopia = snapshot + Kopia, snapshot = local snapshot only, off = nothing"
        w_c "retention        local ZFS snapshots 'daily weekly monthly'"
        w_c "kopia_retention  Kopia retention of its own 'latest hourly daily weekly monthly annual'"
        w_c "                 (missing = as in [kopia]; single values may also be 'inherit')"
        w_c "method           auto | live  (live = without a snapshot: bind /mnt/user/<share> read-only)"
        w_c "kopia_ignore     Kopia ignore rule relative to the share (several times)"
        w_c "kopia_known      a top-level folder that goes to Kopia (several times; empty = none yet) - a folder"
        w_c "                 neither known nor ignored is new: only local until you decide (* or no line: every folder goes)"
        w_c "exclude_dataset  child dataset neither snapshotted nor backed up (several times)"
        w_c "partner          its dataset goes to that partner office too (a [partner] id, several times)"
        w_c "id, locations    from setup.sh - spot renames and moves"
        for s in "${SH[@]}"; do
            [[ "${WHY[$s]:-}" == "no longer exists"* ]] && continue
            share_name_ok "$s" || continue
            echo
            echo "[share \"$s\"]"
            w_kv mode "$(pget "share|$s|mode")"
            [[ -n "$(pget "share|$s|retention")" ]] && w_kv retention "$(pget "share|$s|retention")"
            [[ -n "$(pget "share|$s|kopia_retention")" ]] && w_kv kopia_retention "$(pget "share|$s|kopia_retention")"
            # A share that by nature can only go live gets method = live - that confirms
            # the choice and backup.sh does not report it as drift every night
            if [[ "$(pget "share|$s|mode")" != "off" && "${INV_METHOD[$s]:-}" == "live" ]]; then
                w_kv method live
            elif [[ "$(pget "share|$s|method" auto)" != "auto" ]]; then
                w_kv method "$(pget "share|$s|method")"
            fi
            w_list kopia_ignore "share|$s|kopia_ignore"
            if [[ -n "${P[share|$s|kopia_known]+x}" ]]; then
                if [[ -n "$(plist "share|$s|kopia_known")" ]]; then w_list kopia_known "share|$s|kopia_known"
                else echo "kopia_known ="; fi
            fi
            w_list exclude_dataset "share|$s|exclude_dataset"
            w_list partner "share|$s|partner"
            inv_has_share "$s" && [[ "${INV_METHOD[$s]}" != "none" ]] && {
                w_kv id "${INV_ID[$s]}"
                w_kv locations "$(inv_locnames "$s")"
            }
        done
    }
}

# What Apply would really change in settings.ini: key lines only in the file
# today (-) or only in the new one (+), each with its section. JSON list.
settings_pending() {
    [[ -f "$UB_SETTINGS" ]] || { echo '[]'; return 0; }
    local new="$UB_STATE/.settings.pending.$$"
    settings_render >"$new" 2>/dev/null || { rm -f "$new"; echo '[]'; return 0; }
    local keyed='/^[[:space:]]*([#;]|$)/ {next}
                 /^\[/ {sec=$0; next}
                 {gsub(/^[[:space:]]+|[[:space:]]+$/, ""); print sec "\t" $0}'
    comm -3 <(awk "$keyed" "$UB_SETTINGS" | LC_ALL=C sort -u) <(awk "$keyed" "$new" | LC_ALL=C sort -u) \
        | awk -F'\t' '{ if ($1 == "") print "+\t" $2 "\t" $3; else print "-\t" $1 "\t" $2 }' \
        | jq -Rn '[inputs | split("\t") | {op: .[0], section: (.[1] | ltrimstr("[") | rtrimstr("]")), line: .[2]}]'
    rm -f "$new"
}

write_settings() {
    local tmp="$UB_SETTINGS.tmp.$$" prob
    prob="$(dumps_share_problem "$(pget "general|dumps_share")" "$(pget "share|$(pget "general|dumps_share")|mode" off)")"
    if [[ -n "$prob" ]]; then
        bad "$(dumps_share_text "$prob" "$(pget "general|dumps_share")") - settings.ini is not written"
        return 1
    fi
    settings_render >"$tmp" || { bad "Cannot write $tmp"; return 1; }

    # Cross-check: the new file must load without errors
    local keep_cfg; keep_cfg="$(declare -p CFG)"; keep_cfg="${keep_cfg/declare -A/declare -gA}"
    if ! UB_SETTINGS="$tmp" cfg_load "$tmp" || ! cfg_validate; then
        bad "The settings.ini written has errors:"; local e; for e in "${CFG_ERRORS[@]}"; do say "    $e"; done
        mv "$tmp" "$UB_STATE/settings.ini.broken.$TS"; eval "$keep_cfg"; return 1
    fi
    if [[ -f "$UB_SETTINGS" ]]; then
        cp -a "$UB_SETTINGS" "$UB_STATE/settings.ini.$TS"
        ls -1t "$UB_STATE"/settings.ini.[0-9]* 2>/dev/null | tail -n +11 | xargs -r rm -f
    fi
    mv "$tmp" "$UB_SETTINGS"
    WRITTEN="yes"
    rm -f "$UB_STATE/partners-kept.ini"            # the partner sections --forget kept are in settings.ini again
    # the backup place changed: the next run moves the dumps so far (state/dumps-previous)
    local was now
    was="$(dumps_path "$ORIG_DUMPS_SHARE")"; now="$(dumps_path "$(pget "general|dumps_share")")"
    if [[ -n "$was" && "$was" != "$now" && -d "$was" ]]; then
        echo "$was" >"$UB_STATE/dumps-previous"
        hint "The next run moves the dumps from $was to $now"
    fi
    ok "settings.ini written ($UB_SETTINGS)"
    [[ -f "$UB_STATE/settings.ini.$TS" ]] && hint "Previous version: state/settings.ini.$TS"
    return 0
}

apply_kopia_policies() {
    [[ "$KOPIA_POLICY_READY" == "yes" ]] || { hint "Kopia policies not set (Kopia check incomplete)"; return 0; }
    load_settings >/dev/null
    plan_build
    new_local_state_load; new_local_scan_live      # folders still new stay left out (2.21)
    kopia_mounts_load; kopia_status_load || return 1; kopia_policies_load
    local kind hpath share cpath changes=0
    local -a todo=()
    sub "Kopia policies (wanted, from settings.ini)"
    while IFS='|' read -r kind hpath share; do
        [[ -z "$kind" ]] && continue
        cpath="$(k_path "$hpath")" || { wrn "$hpath is not mapped into the container"; continue; }
        if kopia_policy_eval "$cpath" "$kind" "$(kopia_want_ignores "$kind" "$share")" "$(kopia_want_retention "$kind" "$share")"; then
            ok "$cpath"
        else
            say "  ${C_Y}change${C_0} $cpath"; printf '%s' "$KP_DIFF" | while IFS= read -r l; do say "    $l"; done
            todo+=( "$cpath" ); changes=$((changes+1))
            KPA["$cpath"]="$(printf '%q ' "${KP_ARGS[@]}")"
        fi
    done < <(kopia_targets)
    (( changes == 0 )) && { ok "All policies match"; return 0; }
    [[ "$MODE" == "check" ]] && return 0
    ask_yn "  Write $changes policy change(s) to Kopia?" y || { wrn "Policies not written - backup.sh will report it"; return 0; }
    for cpath in "${todo[@]}"; do
        eval "local -a args=( ${KPA[$cpath]} )"
        # shellcheck disable=SC2154
        if kopia_x policy set "$KOPIA_ID:$cpath" "${args[@]}" >>"$LOG_FILE" 2>&1; then ok "set: $cpath"
        else bad "policy set $cpath failed (see $LOG_FILE)"; fi
    done
}
declare -A KPA=()
declare -a KOPIA_SOURCES=()   # "state<US>source" - active foreign orphan own gone
DECIDE_RETIRE="no"

# Old and orphaned sources
step_kopia_sources() {
    [[ "$KOPIA_POLICY_READY" == "yes" ]] || return 0
    sub "Sources in the repository"
    local src u h p want_paths="" kind hpath share cpath
    while IFS='|' read -r kind hpath share; do
        [[ "$kind" == "root" || -z "$kind" ]] && continue
        cpath="$(k_path "$hpath")" && want_paths+="$cpath"$'\n'
    done < <(kopia_targets)
    local croot; croot="$(k_path "$MOUNT_ROOT")"
    local -a retire=()
    KOPIA_SOURCES=()
    while IFS= read -r src; do
        [[ -z "$src" ]] && continue
        u="${src%%@*}"; h="${src#*@}"; h="${h%%:*}"; p="${src#*:}"
        if [[ "$u@$h" != "$KOPIA_ID" ]]; then hint "foreign   $src (another identity)"; KOPIA_SOURCES+=( "foreign"$'\x1f'"$src" ); continue; fi
        if grep -Fxq -- "$p" <<<"$want_paths"; then ok "active    $p"; KOPIA_SOURCES+=( "active"$'\x1f'"$src" ); continue; fi
        if [[ "$p" == "$croot" || "$p" == "$croot/"* ]]; then
            wrn "orphaned  $p (under $croot, but no longer a share)"; retire+=( "$src" ); KOPIA_SOURCES+=( "orphan"$'\x1f'"$src" ); continue
        fi
        if docker exec "$KOPIA_CONTAINER" test -e "$p" 2>/dev/null; then
            hint "your own $p (not managed by $UB_NAME - needs its own mapping)"
            KOPIA_SOURCES+=( "own"$'\x1f'"$src" )
        else
            KOPIA_SOURCES+=( "gone"$'\x1f'"$src" )
            wrn "no path $p - no longer exists inside the container (e.g. from an earlier backup script)."
            hint "          If that was a backup of your own with a schedule in the KopiaUI, its mapping is missing -"
            hint "          add it again or take the share in here with mode=kopia."
            retire+=( "$src" )
        fi
    done < <(kopia_sources)

    [[ "$MODE" == "check" || "$MODE" == "plan" ]] && return 0
    [[ ${#retire[@]} -eq 0 ]] && return 0
    # --apply: only when expressly wanted (_retire_sources = yes)
    [[ "$MODE" == "apply" && "$DECIDE_RETIRE" != "yes" ]] && return 0
    local -a tomanual=()
    for src in "${retire[@]}"; do
        u="${src%%@*}"; h="${src#*@}"; h="${h%%:*}"; p="${src#*:}"
        if [[ "$(jq -r --arg p "$p" --arg u "$u" --arg h "$h" \
                 'first(.[] | select(.target.path==$p and .target.userName==$u and .target.host==$h) | .scheduling.manual) // false' \
                 <<<"$KP_JSON")" == "true" ]]; then
            hint "          $p is already set to manual"
        else
            tomanual+=( "$src" )
        fi
    done
    if [[ ${#tomanual[@]} -gt 0 ]] && \
       ask_yn "  Set ${#tomanual[@]} old/orphaned source(s) to 'manual' (Kopia no longer schedules them, the snapshots stay)?" y; then
        for src in "${tomanual[@]}"; do
            if kopia_x policy set "$src" --manual >>"$LOG_FILE" 2>&1; then ok "manual: $src"; else wrn "could not switch $src"; fi
        done
    fi
    interactive || return 0
    say "  Deleting removes ALL snapshots of a source for good (the space is freed at the next"
    say "  Kopia maintenance). Do it only once the new run has succeeded."
    for src in "${retire[@]}"; do
        ask "  Delete '$src'? To delete, type DELETE" "no"
        if [[ "$REPLY" == "DELETE" ]]; then
            kopia_x snapshot delete --all-snapshots-for-source "$src" --delete >>"$LOG_FILE" 2>&1 \
                && ok "deleted: $src" || bad "Deleting $src failed"
        fi
    done
}



##############################################################################
# Step 8: User Scripts, cleaning up, finishing
##############################################################################
step_finish() {
    hdr "Schedule and cleaning up"
    explain <<'TXT'
Installed as a plugin, the office keeps the nightly run in its own cron file and you
set the time at Mr. Backupsy's desk. Otherwise User Scripts needs its script under
/boot/config/plugins/user.scripts/scripts/. Only a call of backup.sh in the script folder
lies there (#arrayStarted=true: runs only with the array started). You set the schedule
in the web interface (Custom, e.g. 0 3 * * *).
TXT
    local us_root="$UB_BOOT/config/plugins/user.scripts/scripts"
    local us_dir="$us_root/$UB_USER_SCRIPT" upath
    upath="$(ub_user_path)"
    if ub_is_plugin; then
        # the plugin keeps the schedule in its own cron file; the office sets it
        hint "Set the time at Mr. Backupsy's desk in the office: Schedule..."
    elif [[ -d "$us_root" ]]; then
        # the old name: normally the office has moved it already (with its schedule)
        if [[ -d "$us_root/$UB_NAME" && ! -e "$us_dir" ]]; then
            mv "$us_root/$UB_NAME" "$us_dir" && echo "$UB_USER_SCRIPT" >"$us_dir/name" \
                && hint "User Script '$UB_NAME' is now called '$UB_USER_SCRIPT' - check the schedule there"
        fi
        if [[ -f "$us_dir/script" ]] && grep -q "$upath/backup.sh" "$us_dir/script"; then
            ok "User Script '$UB_USER_SCRIPT' present"
        elif ask_yn "  Create the User Script '$UB_USER_SCRIPT'? (only a 3-line call on the flash, everything else stays in $upath)" y; then
            mkdir -p "$us_dir"
            cat >"$us_dir/script" <<EOF
#!/bin/bash
#description=Unraid Secretary Office - Mr. Backupsy's nightly backup: snapshots, database dumps, Kopia offsite. Set up and scheduled in the office. Code: $upath, data: $(dirname "$upath")/data/$UB_NAME
#arrayStarted=true
exec "$upath/backup.sh" "\$@"
EOF
            echo "$UB_USER_SCRIPT" >"$us_dir/name"
            ok "Created: $us_dir/script"
        fi
        hint "Set the schedule in Settings > User Scripts: Custom, e.g. 0 3 * * *"
    fi
    # Other User Scripts that also make snapshots or Kopia runs?
    local f
    for f in "$us_root"/*/script; do
        [[ -f "$f" && "$f" != "$us_dir/script" ]] || continue
        if grep -qE 'kopia[^|]* snapshot create|zfs snapshot|btrfs subvolume snapshot' "$f" 2>/dev/null; then
            wrn "User Script '$(basename "$(dirname "$f")")' also makes snapshots/Kopia runs - check whether it has been replaced (otherwise switch off its schedule there)"
        fi
    done
}

summary() {
    say ""
    band "Result" "== Result =="
    plan_build
    if is_yes "$KOPIA_ENABLED"; then
        say "  Shares to Kopia:      ${PLAN_KOPIA[*]:-none}"
        (( ${#PLAN_KITEMS[@]} )) && say "  Own Kopia sources:    $(for it in "${PLAN_KITEMS[@]}"; do IFS='|' read -r t n _ <<<"$it"; printf '%s:%s ' "$t" "$n"; done)"
    else say "  Kopia:                off (local snapshots and dumps only)"; fi
    say "  Local only (snapshot): $(for s in "${PLAN_SNAP[@]}"; do in_list "$s" "${PLAN_KOPIA[@]}" || printf '%s ' "$s"; done)"
    say "  ZFS datasets:         ${#PLAN_ZFS[@]}   btrfs bases: ${#PLAN_BTRFS[@]}   flash: $PLAN_FLASH"
    say "  Errors: $ERRORS   warnings: $WARNINGS   log: $LOG_FILE"
    say ""
    say "  Next steps:"
    say "    1. Dry run:       UB_DRY_RUN=1 $(ub_user_path)/backup.sh"
    if ub_is_plugin; then
        say "    2. First run:     at Mr. Backupsy's desk in the office, or wait for the schedule"
    else
        say "    2. First run:     'Run in Background' in User Scripts, or wait for the schedule"
    fi
    say "    3. Check:         $UB_LOGS/latest.log  (or at Mr. Backupsy's desk in the office)"
}

##############################################################################
# Check mode
##############################################################################
run_check() {
    hdr "Check against settings.ini"
    load_settings || { bad "settings.ini is missing - run setup.sh without --check first"; return 1; }
    inv_scan; docker_load; vm_load; plan_build
    drift_check_settings
    drift_check_shares
    drift_check_containers
    drift_check_vms
    drift_check_items
    drift_check_new_local
    drift_check_kopia
    if [[ ${#DRIFT[@]} -eq 0 ]]; then ok "No drift"
    else drift_text | while IFS= read -r l; do say "  $l"; done; fi
    if [[ "$KOPIA_OK" == "yes" ]]; then
        kopia_probe_propagation && ok "Live test of the mount propagation into '$KOPIA_CONTAINER' passed" \
                                || bad "Live test: '$KOPIA_CONTAINER' does not see new mounts under $MOUNT_ROOT"
    fi
    say ""
    say "  Plan: ${#PLAN_KOPIA[@]} shares to Kopia, ${#PLAN_ZFS[@]} ZFS datasets, ${#PLAN_BTRFS[@]} btrfs bases, flash $PLAN_FLASH"
}

##############################################################################
# For user interfaces: plan, decisions, status (--plan / --apply)
##############################################################################
# Everything with fixed English keys (interface UB_INTERFACE as with
# backup.sh). Texts in "text"/"why_text" are for people only; whoever
# translates takes the codes.
SETUP_STARTED=0
WRITTEN="no"

# Lines "a<US>b<US>c" -> JSON array of objects with the field names $*
us_json() { jq -Rn --arg f "$*" '($f | split(" ")) as $k
    | [inputs | split("\u001f") as $v | [range(0; $k | length) | {key: $k[.], value: ($v[.] // "")}] | from_entries]'; }

setup_status_write() { # setup_status_write <result>
    local tmp="$UB_STATE/.setup-status.json.$$"
    if printf '%s\n' "${MSGS[@]}" | us_json level step text | jq -c \
        --arg mode "$MODE" --arg result "$1" --arg version "$UB_VERSION" --argjson interface "$UB_INTERFACE" \
        --argjson pid "$$" --argjson started "$SETUP_STARTED" --argjson now "$(date +%s)" \
        --arg written "$WRITTEN" --argjson errors "$ERRORS" --argjson warnings "$WARNINGS" --arg log "$(basename "$LOG_FILE")" \
        '{interface: $interface, version: $version, mode: $mode, pid: $pid, started: $started, updated: $now,
          finished: (if $result == "running" then 0 else $now end), result: $result, written: ($written == "yes"),
          errors: $errors, warnings: $warnings, log: $log, messages: map(select(.text != ""))}' >"$tmp" 2>/dev/null; then
        mv -f "$tmp" "$UB_STATE/setup-status.json"
    else
        rm -f "$tmp"
    fi
}

# Start anew: what the setup decided goes aside (state/reset-<time>/), so the
# next plan proposes everything as on a new server. Nothing backed up is touched;
# without settings.ini backup.sh refuses to run until the next apply.
step_forget() {
    local dir="$UB_STATE/reset-$TS" f n=0
    [[ -e "$dir" ]] && dir="$dir-$$"
    if [[ ! -e "$UB_SETTINGS" && ! -e "$UB_STATE/setup-decisions.json" && ! -e "$UB_STATE/setup-plan.json" ]]; then
        ok "Nothing to forget - there are no settings yet"; return 0
    fi
    if interactive && ! ask_yn "Forget the settings and start the setup anew (backups stop until you apply again)?" n; then
        say "Nothing changed."; return 0
    fi
    mkdir -m 700 -p "$dir" || { bad "Cannot create $dir"; return 1; }
    # the [partner] sections are the Team Lead's agreement, not the setup's: they stay for the next setup (2.27)
    if [[ -f "$UB_SETTINGS" ]] && grep -q '^\[partner "' "$UB_SETTINGS"; then
        awk '/^\[/ { keep = ($0 ~ /^\[partner "[0-9a-f]{8}"\]$/) } keep' "$UB_SETTINGS" >"$UB_STATE/.partners-kept.ini.$$" \
            && chmod 600 "$UB_STATE/.partners-kept.ini.$$" && mv -f "$UB_STATE/.partners-kept.ini.$$" "$UB_STATE/partners-kept.ini" \
            && hint "The partner offices stay (state/partners-kept.ini) - the next setup takes them up again; only the units ticked for them start anew"
        rm -f "$UB_STATE/.partners-kept.ini.$$"
    fi
    for f in "$UB_SETTINGS" "$UB_STATE/setup-decisions.json" "$UB_STATE/setup-plan.json"; do
        [[ -e "$f" ]] || continue
        mv -f "$f" "$dir/" || { bad "Cannot move $f to $dir"; return 1; }
        n=$((n+1))
    done
    ok "$n file(s) put aside in $dir - the next setup starts as on a new server"
    hint "Back up again only after the next apply; snapshots, dumps and Kopia stay as they are"
    [[ -e "$dir/settings.ini" ]] && hint "To go back: mv $dir/settings.ini $UB_SETTINGS"
    return 0
}

setup_end() {
    local rc=$?
    if (( rc == 0 )); then setup_status_write ok; else setup_status_write failed; fi
    ub_holder_clear
}

# Lay decisions (JSON object key -> value or list) over the values so far:
# they become the defaults, the rest runs like --yes.
# Dumps and Nextclouds apply only if they are in the decisions.
decisions_load() {
    local f="${UB_DECISIONS:-}" k v sec n=0
    [[ -n "$f" && -r "$f" ]] || { bad "Decisions are missing: ${f:-?}"; exit 1; }
    jq -e 'type == "object"' "$f" >/dev/null 2>&1 || { bad "The decisions are not a JSON object: $f"; exit 1; }
    # dumps, Nextclouds, apps and the VMs' own Kopia sources count only when they are in the decisions
    for k in "${!OLD[@]}"; do
        [[ "$k" == dump\|* || "$k" == nextcloud\|* || "$k" == app\|* || "$k" =~ ^vm\|.+\|(kopia|folder|kopia_retention|kopia_ignore)$ ]] && unset "OLD[$k]"
    done
    local -a keep=(); for sec in "${OLD_SECTIONS[@]}"; do [[ "$sec" == dump\|* || "$sec" == nextcloud\|* || "$sec" == app\|* ]] || keep+=( "$sec" ); done
    OLD_SECTIONS=( "${keep[@]}" )
    while IFS= read -r -d '' k && IFS= read -r -d '' v; do
        if [[ "$k" == "_retire_sources" ]]; then DECIDE_RETIRE="$v"; continue; fi
        if [[ ! "$k" =~ ^[a-z_]+(\|[^|]+)?\|[a-z_]+$ || "$v" == *$'\n'* ]]; then wrn "Decision '$k' ignored (invalid)"; continue; fi
        OLD[$k]="${v//$'\x1f'/$'\n'}"
        sec="${k%|*}"; old_has "$sec" || OLD_SECTIONS+=( "$sec" )
        n=$((n+1))
    done < <(jq --raw-output0 'to_entries[] | .key, (if (.value | type) == "array" then (.value | map(tostring) | join("\u001f")) else (.value | tostring) end)' "$f")
    ok "$n decisions taken over from the office"
}

# The shares a container binds: "share|path in it|rw" joined by <RS> (for the office's apps step)
ct_share_binds() { # ct_share_binds <container>
    local src rw r
    while IFS='|' read -r src _ rw; do
        [[ -z "$src" ]] && continue
        r="$(path_share "$src")" || continue
        [[ "${r%%|*}" == "*" ]] && continue
        printf '%s|%s\x1e' "$r" "$rw"
    done <<<"${CT_BINDS[$1]:-}"
}

# Kopia rules the office offers per app (never set unasked): folders that are rebuilt by the app itself -
# caches, transcodes, logs, updates, and Immich's thumbnails and re-encoded videos (rebuilding those takes
# hours). Paths inside the container; only what exists (on awake disks) and lies in a share is offered.
offer_candidates() { # offer_candidates <container>  -> lines "kind|path inside the container"
    local c="$1" P="/config/Library/Application Support/Plex Media Server" x
    case "$(media_kind "${CT_IMAGE[$c]}")" in
        emby)     printf '%s\n' "cache|/config/cache" "transcode|/config/transcoding-temp" "log|/config/logs" ;;
        jellyfin) printf '%s\n' "cache|/config/cache" "cache|/cache" "transcode|/config/transcodes" "transcode|/config/data/transcodes" \
                                 "transcode|/cache/transcodes" "log|/config/log" ;;
        plex)     printf '%s\n' "cache|$P/Cache" "codec|$P/Codecs" "crash|$P/Crash Reports" "log|$P/Logs" "update|$P/Updates" "transcode|/transcode" ;;
    esac
    if is_immich_server "${CT_IMAGE[$c]}"; then
        for x in /data /usr/src/app/upload; do printf '%s\n' "thumbs|$x/thumbs" "video|$x/encoded-video"; done
    fi
    return 0
}
# container_offers <container>  -> "share|path in the share|kind" joined by <RS>; a folder below one already offered is left out
container_offers() {
    local c="$1" k p hp where r e out="" below
    local -a seen=()
    while IFS='|' read -r k p; do
        [[ -z "$k" ]] && continue
        hp="$(ct_host_path "$c" "$p")" || continue
        r="$(path_share "$hp")" || continue
        [[ "${r%%|*}" == "*" || -z "${r#*|}" ]] && continue
        below=0; for e in "${seen[@]}"; do [[ "$r/" == "$e/"* ]] && below=1; done
        (( below )) && continue
        where="$(ub_path_where "$hp")"; [[ -n "$where" && -d "$where" ]] || continue
        seen+=( "$r" )
        out+="$r|$k"$'\x1e'
    done < <(offer_candidates "$c")
    printf '%s' "$out"
}
# container_data <container>  -> "kind|share|path in the share" for Nextcloud's and Immich's files (empty otherwise)
container_data() {
    local c="$1" hp="" r x kind=""
    if [[ -n "${NC_DATA[$c]:-}" ]]; then kind="nextcloud"; hp="$(ct_host_path "$c" "${NC_DATA[$c]}")" || hp=""
    elif is_immich_server "${CT_IMAGE[$c]}"; then
        kind="immich"
        for x in /data /usr/src/app/upload; do hp="$(ct_host_path "$c" "$x")" && break; done
    fi
    [[ -n "$hp" ]] || return 0
    r="$(path_share "$hp")" || return 0
    [[ "${r%%|*}" == "*" ]] && return 0
    printf '%s|%s' "$kind" "$r"
}

# key<US>value lines -> JSON object; lists (ignore, no_stop, ...) as arrays
plan_kv() {
    jq -Rn '[inputs | select(length > 0) | index("\u001f") as $i | {key: .[0:$i], value: .[$i + 1:]}]
            | map(if (.key | test("\\|(ignore|no_stop|known|skip|kopia_ignore|kopia_known|exclude_dataset|tar_exclude|folder|partner|partner_place)$"))
                  then .value |= (split("\u001e") | map(select(length > 0))) else . end) | from_entries'
}

# Everything a user interface needs to decide -> state/setup-plan.json
plan_write() {
    local k s n b tmp="$UB_STATE/.setup-plan.json.$$"
    local p shares cts dbs miss ncs bases srcs maps
    p="$(for k in "${!P[@]}"; do printf '%s\x1f%s\n' "$k" "${P[$k]//$'\n'/$'\x1e'}"; done | plan_kv)"
    # what settings.ini says today, in the same form - so a page can show what Apply really changes
    local o="{}"
    [[ "$HAVE_SETTINGS" == "yes" ]] && o="$(for k in "${!OLD[@]}"; do printf '%s\x1f%s\n' "$k" "${OLD[$k]//$'\n'/$'\x1e'}"; done | plan_kv)"
    local pending="[]"
    [[ "$HAVE_SETTINGS" == "yes" ]] && pending="$(settings_pending)"
    shares="$(for s in "${SH[@]}"; do
        local kids="" sug=""
        while IFS='|' read -r b n _; do [[ -n "$n" ]] && kids+="$n"$'\x1e'; done <<<"${INV_CHILDREN[$s]:-}"
        sug="$(appdata_suggestions "$s" | tr '\n' $'\x1e')"
        printf '%s\x1f' "$s" "$(pget "share|$s|mode")" "${WHY_CODE[$s]:-}" "${WHY_ARG[$s]:-}" "${WHY[$s]:-}" \
            "$(inv_locnames "$s" 2>/dev/null)" "$(inv_locnames_short "$s" 2>/dev/null)" "${INV_METHOD[$s]:-}" "${INV_LAYOUT[$s]:-}" \
            "${SH_GB[$s]:-}" "$(printf '%s' "${INV_NOTE[$s]:-}" | tr '\n' $'\x1e')" "$(inv_has_share "$s" && echo 1)" "$kids" "$sug"
        echo
    done | us_json name mode why why_arg why_text locations where method layout gb notes exists children folders \
         | jq 'map(.exists = (.exists == "1") | .gb = (if .gb == "" then null else (.gb | tonumber) end)
                   | .notes = (.notes | split("\u001e") | map(select(length > 0)))
                   | .children = (.children | split("\u001e") | map(select(length > 0)))
                   | .folders = (.folders | split("\u001e") | map(select(length > 0) | split("|") | {dir: .[0], container: .[1]})))')"
    # the new folders of each share going to Kopia: waiting for a decision, only local so far (2.21)
    shares="$(jq -c --argjson w "$(new_local_json)" 'map(.name as $n
        | .waiting = [$w[] | select(.share == $n) | {dir: .folder, bytes, first_seen: (if .first_seen > 0 then .first_seen else null end)}])' <<<"$shares")" \
        || shares="[]"
    cts="$(for n in "${CT_NAMES[@]}"; do
        printf '%s\x1f' "$n" "${CT_IMAGE[$n]}" "${CT_RUNNING[$n]}" "${CT_STOP[$n]:-}" "${CT_CODE[$n]:-}" "${CT_ARG[$n]:-}" \
            "${CT_PREV[$n]:-}" "${CT_RISK[$n]:-}" "$(printf '%s' "${CT_VOLUMES[$n]:-}" | cut -d'|' -f2 | tr '\n' $'\x1e')" \
            "${CT_PROJECT[$n]:-}" "${CT_SERVICE[$n]:-}" "$([[ "$n" == "$KOPIA_CONTAINER" ]] && echo 1)" "$(ct_share_binds "$n")" \
            "$(media_kind "${CT_IMAGE[$n]}")" "$(container_offers "$n")" "$(container_data "$n")"
        echo
    done | us_json name image running stop why why_arg previous risk volumes project service kopia binds media offers data \
         | jq 'map(.running = (.running == "true") | .stop = (.stop == "yes") | .previous = (.previous == "1")
                   | .risk = (.risk == "1") | .kopia = (.kopia == "1")
                   | .volumes = (.volumes | split("\u001e") | map(select(length > 0)))
                   | .binds = (.binds | split("\u001e") | map(select(length > 0) | split("|") | {share: .[0], path: .[1], rw: (.[2] == "true")}))
                   | .offers = (.offers | split("\u001e") | map(select(length > 0) | split("|") | {share: .[0], path: .[1], kind: .[2]}))
                   | .data = (if .data == "" then null else (.data | split("|") | {kind: .[0], share: .[1], path: .[2]}) end))')"
    local vms
    vms="$(for n in "${VM_NAMES[@]}"; do
        printf '%s\x1f' "$n" "${VM_STATE[$n]:-}" "${VM_AUTOSTART[$n]:-}" "${VM_AGENT[$n]:-}" "${VM_HOSTDEV[$n]:-0}" "${VM_TPM[$n]:-}" \
            "${VM_SNAP[$n]:-}" "$(printf '%s' "${VM_OWN_DS[$n]:-}" | tr '\n' $'\x1e')" "$(printf '%s' "${VM_DISKS[$n]:-}" | tr '\n' $'\x1e')" \
            "${VM_WHY[$n]:-}" "$(vm_share_mode "$n")" "$(old_has "vm|$n" && echo 1)" "${VM_BYTES[$n]:-}" "${VM_APPARENT[$n]:-}"
        echo
    done | us_json name state autostart agent hostdev tpm snap own disks why share_mode previous bytes apparent \
         | jq 'map(select(.name != "") | .hostdev = ((.hostdev // "0") | tonumber) | .tpm = (.tpm == "yes") | .autostart = (.autostart == "yes")
                   | .previous = (.previous == "1")
                   # since 2.26: what its disk files take (allocated) and what they are (apparent - what Kopia reads at a first upload)
                   | .bytes = (if (.bytes // "") == "" then null else (.bytes | tonumber) end)
                   | .apparent = (if (.apparent // "") == "" then null else (.apparent | tonumber) end)
                   | .own = (.own | split("\u001e") | map(select(length > 0)))
                   | .disks = (.disks | split("\u001e") | map(select(length > 0) | split("|")
                        | {target: .[0], source: .[1], base: .[2], fs: .[3], dataset: .[4], share: .[5]})))')" || vms='[]'
    # partners (2.27): who this office sends to (from the agreement, never asked over the network: reachable stays null)
    # and per unit whether it can travel - one dataset of its own (PARTNER_OKU / PARTNER_WHYU from step_partners)
    local partners pu
    partners="$(for k in "${!P[@]}"; do if [[ "$k" =~ ^partner\|([0-9a-f]{8})\|address$ ]]; then printf '%s\n' "${BASH_REMATCH[1]}"; fi; done | LC_ALL=C sort \
        | while IFS= read -r n; do
            printf '%s\x1f' "$n" "$(pget "partner|$n|name" "$n")" "$(pget "partner|$n|address")" "$(pget "partner|$n|port" 22)" \
                "$(pget "partner|$n|rate_mbit" 0)" "$([[ -f "$UB_PARTNER_DIR/$n.key" && -f "$UB_PARTNER_DIR/$n.known" ]] && echo 1)"
            echo
        done | us_json id name address port rate_mbit key \
        | jq --arg src "$PARTNER_SOURCE" 'map(select(.id != "") | .port = (.port | tonumber? // 22) | .rate_mbit = (.rate_mbit | tonumber? // 0)
                | .key = (.key == "1") | .reachable = null | .source = $src)')" || partners='[]'
    pu="$(for k in "${!PARTNER_OKU[@]}"; do printf '%s\x1f%s\x1f%s\n' "$k" "${PARTNER_OKU[$k]}" "${PARTNER_WHYU[$k]:-}"; done | us_json unit ok why \
        | jq 'map(select(.unit != "") | {key: .unit, value: {ok: (.ok == "yes"), why: (if .why == "" then null else .why end)}}) | from_entries')" || pu='{}'
    local plist_json
    plist_json() { plist "$1" | jq -R . | jq -sc .; }
    shares="$(jq -c --argjson pu "$pu" --arg place "$(pget "general|dumps_share")" \
        --argjson ids "$(for s in "${SH[@]}"; do printf '%s\x1f%s\n' "$s" "$(plist "share|$s|partner" | paste -sd' ')"; done | us_json name ids | jq -c 'map({key: .name, value: (.ids | split(" ") | map(select(length > 0)))}) | from_entries')" \
        'map(.name as $n | .partner = ($ids[$n] // []) | .partner_ok = ($pu["share:" + $n].ok // false)
             | .partner_why = (if ($pu["share:" + $n].ok // false) then null else ($pu["share:" + $n].why // "no_dataset") end)
             | .place = ($n == $place))' <<<"$shares")" || shares="[]"
    vms="$(jq -c --argjson pu "$pu" \
        --argjson ids "$(for n in "${VM_NAMES[@]}"; do printf '%s\x1f%s\n' "$n" "$(plist "vm|$n|partner" | paste -sd' ')"; done | us_json name ids | jq -c 'map(select(.name != "") | {key: .name, value: (.ids | split(" ") | map(select(length > 0)))}) | from_entries')" \
        'map(.name as $n | .partner = ($ids[$n] // []) | .partner_ok = ($pu["vm:" + $n].ok // false)
             | .partner_why = (if ($pu["vm:" + $n].ok // false) then null else ($pu["vm:" + $n].why // "no_dataset") end))' <<<"${vms:-[]}")" || vms="[]"
    # what sleeps right now (2.28: disks.ini, read once - nothing woken): per share and VM its pools and disks asleep,
    # so the office can say «hive sleeps now» beside the choice asleep_pools
    ub_asleep_load
    shares="$(jq -c --argjson a "$(for s in "${SH[@]}"; do printf '%s\x1f%s\n' "$s" "$(share_asleep_now "$s" | paste -sd' ')"; done | us_json name bases \
            | jq -c 'map(select(.name != "") | {key: .name, value: (.bases | split(" ") | map(select(length > 0)))}) | from_entries')" \
        'map(.name as $n | .asleep_bases = ($a[$n] // []) | .pool_asleep = ((.asleep_bases | length) > 0))' <<<"$shares")" || shares="[]"
    vms="$(jq -c --argjson a "$(for n in "${VM_NAMES[@]}"; do printf '%s\x1f%s\n' "$n" "$(vm_asleep_now "$n" | paste -sd' ')"; done | us_json name bases \
            | jq -c 'map(select(.name != "") | {key: .name, value: (.bases | split(" ") | map(select(length > 0)))}) | from_entries')" \
        'map(.name as $n | .asleep_bases = ($a[$n] // []) | .pool_asleep = ((.asleep_bases | length) > 0))' <<<"${vms:-[]}")" || vms="[]"
    local place_partner
    place_partner="$(jq -nc --arg share "$(pget "general|dumps_share")" --argjson ids "$(plist_json "general|partner_place")" --argjson pu "$pu" \
        '{share: $share, partner: $ids, partner_ok: ($pu.place.ok // false), partner_why: (if ($pu.place.ok // false) then null else ($pu.place.why // "no_dataset") end)}')" \
        || place_partner="null"
    unset -f plist_json
    dbs="$(printf '%s\n' "${DB_ROWS[@]}" | us_json container stack type detected where \
         | jq 'map(select(.container != "") | .dumpable = (.type | test("^(mariadb|postgres|mongodb)$")))')"
    miss="$(printf '%s\n' "${DB_MISSING[@]}" | us_json stack service image type | jq 'map(select(.stack != ""))')"
    ncs="$(printf '%s\n' "${NC_ROWS[@]}" | us_json members occ preexisting \
         | jq 'map(select(.members != "") | .members = (.members | split(" ")))')"
    bases="$(for b in "${INV_BASES[@]}"; do printf '%s\x1f%s\x1f%s\n' "$b" "${INV_BASE_FS[$b]:-}" "${INV_BASE_KIND[$b]:-}"; done | us_json name fs kind)"
    srcs="$(printf '%s\n' "${KOPIA_SOURCES[@]}" | us_json state source | jq 'map(select(.source != ""))')"
    maps="$(for k in "${!KM_SRC[@]}"; do printf '%s\x1f' "${KM_SRC[$k]}" "${KM_DST[$k]}" "${KM_RW[$k]}" "${KM_PROP[$k]:-}" "$([[ $k -eq $KOPIA_MAIN ]] && echo 1)"; echo; done \
         | us_json source target rw propagation main | jq 'map(select(.source != "") | .rw = (.rw == "true") | .main = (.main == "1"))')"
    local kc="${KOPIA_CONTAINER:-}" cands=""
    for n in "${CT_NAMES[@]}"; do is_kopia_image "${CT_IMAGE[$n]}" && cands+="$n"$'\x1e'; done
    printf '%s\n' "${MSGS[@]}" | us_json level step text | jq -c \
        --argjson interface "$UB_INTERFACE" --arg version "$UB_VERSION" --argjson time "$(date +%s)" \
        --arg have "$HAVE_SETTINGS" --argjson P "$p" --argjson O "$o" --argjson pending "$pending" --argjson shares "$shares" --argjson containers "$cts" \
        --argjson databases "$dbs" --argjson missing "$miss" --argjson nextcloud "$ncs" --argjson bases "$bases" \
        --argjson vms "${vms:-[]}" --arg vm_service "$VM_SERVICE" \
        --arg flash_ds "${FLASH_DATASET:-}" --arg flash_fs "${FLASH_FS:-}" --arg size_timeout "$SIZE_TIMEOUT" \
        --arg k_enabled "$(pget "kopia|enabled" no)" --arg k_container "$kc" --arg k_cands "$cands" \
        --arg k_running "${CT_RUNNING[$kc]:-}" --arg k_image "${CT_IMAGE[$kc]:-}" --arg k_ready "$KOPIA_POLICY_READY" \
        --arg k_fail "$KOPIA_FAIL" --arg k_probe "$KOPIA_PROBE" --argjson k_maps "$maps" \
        --arg k_connected "${KOPIA_CONNECTED:-no}" --arg k_id "${KOPIA_ID:-}" --arg k_version "${KOPIA_VERSION:-}" \
        --arg k_storage "${KOPIA_STORAGE:-}" --arg k_host "${KOPIA_HOST:-}" --arg k_uid "${KOPIA_SERVER_UID:-}" \
        --argjson k_sources "$srcs" --arg mount_root "$MOUNT_ROOT" \
        --argjson partners "${partners:-[]}" --argjson place_partner "${place_partner:-null}" \
        --arg asleep_pools "$(pget "general|asleep_pools" wake)" --argjson asleep_nights "$UB_ASLEEP_NIGHTS" \
        '{interface: $interface, version: $version, time: $time, have_settings: ($have == "yes"),
          asleep_pools: $asleep_pools, asleep_nights: $asleep_nights,
          sizes_measured: ($size_timeout != "0"), P: $P, O: $O, pending: $pending, shares: $shares, containers: $containers,
          databases: $databases, missing_databases: $missing, nextcloud: $nextcloud,
          vms: $vms, vm_service: ($vm_service == "yes"), partners: $partners, place_partner: $place_partner,
          bases: $bases, flash: {dataset: $flash_ds, fs: $flash_fs}, mount_root: $mount_root,
          kopia: {enabled: ($k_enabled == "yes"), container: $k_container,
                  candidates: ($k_cands | split("\u001e") | map(select(length > 0))),
                  running: ($k_running == "true"), image: $k_image, ready: ($k_ready == "yes"),
                  problem: (if $k_fail == "" then null else $k_fail end),
                  probe: (if $k_probe == "" then null else ($k_probe | tonumber) end), mappings: $k_maps,
                  connected: ($k_connected == "yes"), identity: $k_id, version: $k_version, storage: $k_storage,
                  host_is_container_id: ($k_host | test("^[0-9a-f]{12}$")), server_uid: $k_uid, sources: $k_sources},
          messages: map(select(.text != ""))}' >"$tmp" \
        && mv -f "$tmp" "$UB_STATE/setup-plan.json" && ok "Plan written: $UB_STATE/setup-plan.json" \
        || { rm -f "$tmp"; bad "The plan could not be written"; return 1; }
}

##############################################################################
# Sequence
##############################################################################
# The engine's lock (lib/common.sh, "Who holds the lock"): opened without truncating it - only the one
# that gets it touches it and notes itself in state/lock-holder.json. Busy: say who has it, exit 75.
exec 9>>"$UB_STATE/lock"
if ! flock -n 9; then
    ub_holder_read
    case "$HOLDER_KIND" in
        backup|check|dryrun) echo "backup.sh is running right now ($HOLDER_KIND$( (( HOLDER_STARTED > 0 )) && date -d "@$HOLDER_STARTED" '+, started %Y-%m-%d %H:%M')) - start setup.sh later." ;;
        setup)   echo "Another setup.sh is running right now${HOLDER_MODE:+ ($HOLDER_MODE)} - start setup.sh later." ;;
        restore) echo "A restore${HOLDER_WHAT:+ of $HOLDER_WHAT} is going on right now - start setup.sh later." ;;
        *)       echo "Another program holds the engine's lock ($UB_STATE/lock) - start setup.sh later." ;;
    esac
    exit 75
fi
touch "$UB_STATE/lock"
ub_holder_write setup "$MODE" "$TS" ""
trap ub_holder_clear EXIT
if [[ "$MODE" == "plan" || "$MODE" == "apply" || "$MODE" == "forget" ]]; then
    SETUP_STARTED="$(date +%s)"
    setup_status_write running
    trap setup_end EXIT
fi

say "${C_B}$UB_NAME $UB_VERSION - setup ($MODE) on $(hostname -s)${C_0}"
[[ "$MODE" != "check" && "$MODE" != "plan" && "$MODE" != "forget" ]] && recover_interrupted_run
case "$MODE" in
    check)
        STEPS=1; run_check ;;
    forget)
        STEP_ID=forget; step_forget || exit 1 ;;
    kopia)
        STEPS=1
        load_settings || { echo "settings.ini is missing - run setup.sh without --kopia first"; exit 1; }
        is_yes "$KOPIA_ENABLED" || { echo "Kopia is switched off in settings.ini - to switch it on, run setup.sh without an option."; exit 1; }
        old_keep
        for k in "${!CFG[@]}"; do P[$k]="${CFG[$k]}"; done
        HAVE_SETTINGS="yes"
        mapfile -t SH < <(cfg_names share)
        inv_scan; docker_load; vm_load
        step_kopia && { write_settings; apply_kopia_policies; step_kopia_sources; } ;;
    plan)
        STEP_ID=environment; step_environment
        STEP_ID=basis;       step_basis
        STEP_ID=offsite;     step_offsite
        STEP_ID=shares;      step_shares
        STEP_ID=containers;  step_containers
        STEP_ID=vms;         step_vms
        STEP_ID=databases;   step_databases
        STEP_ID=general;     step_general
        STEP_ID=partners;    step_partners
        STEP_ID=kopia;       step_kopia || true
        STEP_ID=sources;     step_kopia_sources
        STEP_ID=plan;        plan_write ;;
    interactive|auto|apply)
        STEP_ID=environment; step_environment
        STEP_ID=basis;       step_basis
        STEP_ID=offsite;     step_offsite
        STEP_ID=shares;      step_shares
        STEP_ID=containers;  step_containers
        STEP_ID=vms;         step_vms
        STEP_ID=databases;   step_databases
        STEP_ID=general;     step_general
        STEP_ID=partners;    step_partners
        STEP_ID=kopia;       step_kopia || true
        STEP_ID=write
        hdr "Writing"
        explain <<'TXT'
settings.ini is first written to a temporary file, loaded again and checked; only if
it has no errors does it replace the old one (the old one goes to state/). Then
setup.sh compares every Kopia policy with what is wanted and changes only the differences
(adding/removing ignores one by one instead of setting everything anew).
TXT
        if interactive && ! ask_yn "Write settings.ini now?" y; then
            say "Nothing written."; exit 0
        fi
        if ! write_settings; then
            say "settings.ini was NOT written - aborting."; exit 1
        fi
        STEP_ID=policies; apply_kopia_policies
        STEP_ID=sources;  step_kopia_sources
        STEP_ID=finish;   step_finish
        summary ;;
esac
exit 0
}
