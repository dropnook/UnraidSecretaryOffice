#!/bin/bash
###############################################################################
# unraid-backup - backup.sh                       Version 2.12 - 4.10.2026
#   2.12 Dumps und Archive in einem eigenen Backup-Share (general|dumps_share), nie in appdata -
#        ohne gueltige Ablage kein Lauf; bisherige Dumps ziehen beim ersten Lauf um.
#   2.12 Nextcloud-Rechtefehler klar benannt (Code nc_datadir_readable); Manifest liest btrfs nur
#        aus dem Kernel (--mounted, Zeitlimit) statt jedes Geraet roh
#   2.11 User-Scripts-Eintrag heisst unraid-secretary-office_backup (Beschreibung englisch)
#   2.10 VM-Konfiguration aus libvirt.img (XML, NVRAM, TPM-Zustand) als
#        Archiv zu den Dumps - [libvirt] mode = tar (Vorgabe) | off
#   2.9  (nur setup.sh: unbekannte Groesse -> nur lokal vorgeschlagen)
#   2.8  Apps vor den Dumps anhalten: Dumps passen so zu den Dateien im
#        Snapshot, auch bei Apps ohne Wartungsmodus (Immich & Co.)
#   2.7  (nur setup.sh: --plan / --apply)
#   2.6  Teil des Unraid Secretary Office: Code in <office>/backup, Daten in
#        <office>/data/unraid-backup (UB_DATA); --about nennt beide Ordner
#   2.5  Status fuer andere Programme: state/status.json (laufend), last-run.json,
#        history.jsonl, drift.json mit festen englischen Schluesseln; --about.
#        Kopia laeuft im Hintergrund, damit Abbrechen (SIGTERM) sofort greift -
#        der Kopia-Snapshot im Container wird dabei sauber beendet
#   2.4  Nextcloud aus mehreren Containern (App + Cron, gemeinsame config.php)
#        wird als EINE Instanz erkannt - der zweite Container meldete den
#        eben gesetzten Wartungsmodus als "schon an" und brach den Lauf ab;
#        Wartungsmodus mit 3 Versuchen, Meldungen von occ ins Protokoll
#   2.3  Shares ohne Daten (nur Unraid-Config) gelten nicht mehr als geloescht;
#        gemeldet wird, wenn Daten verschwinden oder neu dazukommen
#   2.2  Allgemeine Fassung ohne serverspezifische Uebernahmen
#   2.1  Kopia optional ([kopia] enabled), Datenbank-Erkennung ueber Image,
#        Umgebung und Port, MongoDB-Dumps, Compose-Stacks
#   2.0  Erstfassung
#
# Der naechtliche Backup-Lauf fuer Unraid-Server. Alles Serverspezifische
# steht in settings.ini, die setup.sh erzeugt. Dieses Script liest
# settings.ini nur - es aendert sie nie.
#
# ABLAUF
#    1. settings.ini laden, Inventar aufnehmen (Pools, Disks, Shares, Container)
#    2. Abweichungen pruefen und melden: neue / umbenannte / geloeschte Shares,
#       neue Container, Kopia-Mapping und -Policies. Nichts davon wird selbst
#       "repariert" - neue Shares werden erst gesichert, wenn setup.sh lief.
#    3. Nextcloud in den Wartungsmodus (bricht ab, wenn er schon an war)
#    4. Manifest: Versionen, Images, Templates, Share-Configs, settings.ini
#    5. Apps anhalten, dann Datenbank-Dumps (MariaDB/MySQL, Postgres, MongoDB),
#       sofort geprueft - so passen Dumps und Dateien zusammen, auch bei Apps
#       ohne Wartungsmodus (z.B. Immich)
#    6. Datenbanken und Netzwerk-Container anhalten
#    7. ZFS-Snapshots (je Pool atomar) und btrfs-Snapshots
#    8. Container starten, Wartungsmodus aus  -> Unterbrechung endet hier
#    9. Snapshots je Share unter <mount_root>/<share> einhaengen (read-only)
#   10. Kopia sichert jeden Share aus <mount_root>/<share>
#       (nur mit [kopia] enabled = yes - ohne Kopia enden 9/10 hier: lokale
#       Snapshots und Dumps sind dann das ganze Backup)
#   11. Aushaengen, aufraeumen (ZFS, btrfs, Dumps, Logs), Mitteilung
#
# KOPIA-CONTAINER (einmalig) - nur dieses eine Daten-Mapping ist noetig:
#   Host /mnt/backup-snapshots -> Container /mnt/backup-snapshots
#   Access Mode: Read Only - Slave
#   "Slave" ist entscheidend: nur dann sieht der laufende Container die
#   Mounts, die dieses Script nach seinem Start anlegt. Kopia wird daher nie
#   angehalten. setup.sh prueft das mit einem Live-Test.
#
# AUFRUF
#   User Scripts: Custom Cron, z.B. 0 3 * * *
#   Terminal:     /mnt/user/appdata/UnraidSecretaryOffice/backup/backup.sh [Option]
#
# VARIANTEN (Umgebungsvariable - die Option dahinter ist eine Abkuerzung)
#   UB_MODE=backup                 voller Lauf (Vorgabe)
#   UB_MODE=check     --check      nur pruefen und Abweichungen melden
#   UB_MODE=unmount   --unmount    alle Snapshot-Mounts loesen
#                                  (fuer "At Stopping of Array", wenn
#                                  keep_mounts = yes gesetzt ist)
#   UB_DRY_RUN=1      --dry-run    Plan anzeigen, nichts veraendern
#   UB_SKIP_KOPIA=1   --no-kopia   Dumps und Snapshots ja, Kopia nein
#   UB_NC_PREEXISTING=abort|continue
#                     Nextcloud stand schon im Wartungsmodus:
#                     abort = Lauf abbrechen, continue = trotzdem sichern und
#                     den Wartungsmodus danach AN lassen (gilt fuer alle
#                     Nextclouds und ueberschreibt settings.ini)
#   UB_NO_NOTIFY=1                 keine Unraid-Mitteilungen
#                     --about      Name, Version und Schnittstelle als JSON
#   UB_DATA=/pfad                  anderer Datenordner (Vorgabe <office>/data/unraid-backup)
#   UB_SETTINGS=/pfad/settings.ini andere Einstellungsdatei
#   Beispiel: UB_DRY_RUN=1 /mnt/user/appdata/UnraidSecretaryOffice/backup/backup.sh
#
# DATEIEN (im Datenordner <office>/data/unraid-backup, nichts auf /boot)
#   settings.ini        Einstellungen (von setup.sh)
#   logs/run-*.log      ein Protokoll pro Lauf
#   dumps/<zeit>/       DB-Dumps, Manifest, ggf. Flash-Archiv
#   state/              Sperrdatei, letzter Lauf, gemeldete Abweichungen,
#                       status.json & Co. fuer andere Programme (lib/common.sh, 7.)
###############################################################################

set -uo pipefail

# shellcheck source=lib/common.sh
source "$(dirname "$(readlink -f "$0")")/lib/common.sh" \
    || { echo "lib/common.sh fehlt neben backup.sh"; exit 1; }

usage() { awk 'NR>1 && /^#+$/ {next} NR>1 && /^#/ {sub(/^# ?/,""); print; next} NR>1 {exit}' "$0"; }

for a in "$@"; do
    case "$a" in
        --check)    UB_MODE="check" ;;
        --unmount)  UB_MODE="unmount" ;;
        --dry-run)  UB_DRY_RUN=1 ;;
        --no-kopia) UB_SKIP_KOPIA=1 ;;
        --about)    jq -nc --arg n "$UB_NAME" --arg v "$UB_VERSION" --argjson i "$UB_INTERFACE" --arg c "$UB_DIR" --arg d "$UB_DATA" \
                        '{name: $n, version: $v, interface: $i, code: $c, data: $d}'; exit 0 ;;
        -h|--help)  usage; exit 0 ;;
        *) echo "Unbekannte Option: $a  (siehe --help)"; exit 2 ;;
    esac
done
UB_MODE="${UB_MODE:-backup}"
DRY="${UB_DRY_RUN:-0}"
SKIPK="${UB_SKIP_KOPIA:-0}"
case "$UB_MODE" in backup|check|unmount) ;; *) echo "UB_MODE=$UB_MODE ist unbekannt"; exit 2 ;; esac

[[ $EUID -eq 0 ]] || { echo "Bitte als root ausfuehren."; exit 1; }
ub_data_dirs || { echo "Kann Ordner in $UB_DATA nicht anlegen"; exit 1; }

TS="$(date +%Y%m%d-%H%M)"
STARTED_AT="$(date +%s)"
case "$UB_MODE" in
    backup)  LOG_FILE="$UB_LOGS/run-$TS.log" ;;
    check)   LOG_FILE="$UB_LOGS/check-$TS.log" ;;
    unmount) LOG_FILE="$UB_LOGS/unmount.log" ;;
esac
[[ "$DRY" == "1" && "$UB_MODE" == "backup" ]] && LOG_FILE="$UB_LOGS/dryrun-$TS.log"
ln -sfn "$(basename "$LOG_FILE")" "$UB_LOGS/latest.log" 2>/dev/null

STOPPED=()                  # tatsaechlich angehaltene Container
declare -A NC_ON=()         # Nextclouds, deren Wartungsmodus WIR eingeschaltet haben
declare -A NC_OCC=() NC_USER=()
declare -A NC_SAME=()       # Container -> erster Container derselben Nextcloud-Instanz
declare -A BTRFS_OK=()     # btrfs-Basis -> Snapshot-Pfad
declare -A LAYER_MNT=()     # ZFS-Dataset -> Mountpunkt unter .layers
declare -A SHARE_MOUNTED=() # Share -> single|overlay|split
declare -A ZFS_FAILED=()    # Pool -> 1
MOUNTED="no"
CLEANUP_DONE="no"
DOWNTIME=0
SNAP_NAME=""
RUN_DIR=""
KOPIA_PID=""                # laufender Kopia-Snapshot (Hintergrund, siehe kopia_one)
KOPIA_CP=""

die() {
    err "$*"
    status_finish failed "$*"
    ub_notify "Backup FEHLGESCHLAGEN" "$*" "alert" "Protokoll: $LOG_FILE"
    exit 1
}
# die_code <code> <text>: like die, but status.json carries the code - the office translates it
die_code() {
    local code="$1"; shift
    err "$*"
    status_finish failed "$code"
    ub_notify "Backup FEHLGESCHLAGEN" "$*" "alert" "Protokoll: $LOG_FILE"
    exit 1
}

##############################################################################
# Container anhalten und wieder starten
##############################################################################
T_APP=(); T_DB=(); T_NET=()

build_stop_tiers() {
    local n prov
    local -A provider=() isdb=()
    T_APP=(); T_DB=(); T_NET=()
    [[ "$DOCKER_STOP" == "none" ]] && return 0
    for n in "${CT_NAMES[@]}"; do
        [[ "${CT_NET[$n]}" == container:* ]] || continue
        prov="$(ct_resolve "${CT_NET[$n]#container:}")" && provider[$prov]=1
    done
    for n in $(cfg_names dump); do isdb[$n]=1; done
    for n in "${CT_NAMES[@]}"; do
        [[ "${CT_RUNNING[$n]}" == "true" ]] || continue
        [[ "$n" == "$KOPIA_CONTAINER" ]] && continue
        in_list "$n" "${DOCKER_NO_STOP[@]}" && continue
        if [[ -n "${provider[$n]:-}" ]]; then T_NET+=( "$n" )
        elif [[ -n "${isdb[$n]:-}" || -n "$(ct_db_type "$n")" ]]; then T_DB+=( "$n" )
        else T_APP+=( "$n" ); fi
    done
}

# Merkzettel in state/: wird ein Lauf hart abgebrochen (kill -9, Absturz),
# bringt der naechste Start von backup.sh/setup.sh die Dienste zurueck.
save_restore_state() {
    if [[ ${#STOPPED[@]} -gt 0 ]]; then printf '%s\n' "${STOPPED[@]}" >"$UB_STATE/stopped"; else rm -f "$UB_STATE/stopped"; fi
    if [[ ${#NC_ON[@]} -gt 0 ]]; then printf '%s\n' "${!NC_ON[@]}" >"$UB_STATE/maintenance"; else rm -f "$UB_STATE/maintenance"; fi
}

stop_tier() { # stop_tier <name...>
    [[ $# -eq 0 ]] && return 0
    local n
    # VOR dem Stoppen eintragen: bricht der Lauf mitten im Stoppen ab (Signal,
    # kill -9), startet der trap bzw. der naechste Lauf genau diese Container.
    # "docker start" auf einen laufenden Container schadet nicht.
    STOPPED+=( "$@" ); save_restore_state
    docker stop -t "$DOCKER_STOP_TIMEOUT" "$@" >/dev/null 2>>"$LOG_FILE"
    for n in "$@"; do
        if [[ "$(docker inspect -f '{{.State.Running}}' "$n" 2>/dev/null)" != "false" ]]; then
            warn "Container '$n' liess sich nicht anhalten - laeuft waehrend des Snapshots weiter"
            local -a remaining=(); local x
            for x in "${STOPPED[@]}"; do [[ "$x" != "$n" ]] && remaining+=( "$x" ); done
            STOPPED=( "${remaining[@]}" )
        fi
    done
    save_restore_state
}

wait_ready() { # wait_ready <sekunden> <name...>
    local limit="$1" t=0 n st all; shift
    [[ $# -eq 0 ]] && return 0
    while (( t < limit )); do
        all=1
        for n in "$@"; do
            st="$(docker inspect -f '{{.State.Running}} {{if .State.Health}}{{.State.Health.Status}}{{end}}' "$n" 2>/dev/null)"
            [[ "$st" == "true " || "$st" == "true healthy" ]] || { all=0; break; }
        done
        (( all )) && return 0
        sleep 2; t=$((t+2))
    done
    return 1
}

restore_service() {
    local tier n started c i out
    if [[ ${#STOPPED[@]} -gt 0 ]]; then
        for tier in NET DB APP; do
            local -n T="T_$tier"
            started=()
            for n in "${T[@]}"; do
                in_list "$n" "${STOPPED[@]}" || continue
                if docker start "$n" >/dev/null 2>>"$LOG_FILE"; then
                    started+=( "$n" )
                else
                    warn "Container '$n' liess sich NICHT starten"
                    ub_notify "Container nicht gestartet" "'$n' konnte nach dem Snapshot nicht gestartet werden." "alert"
                fi
            done
            unset -n T
            [[ "$tier" != "APP" && ${#started[@]} -gt 0 ]] && \
                { wait_ready 120 "${started[@]}" || warn "Nicht alle $tier-Container sind nach 120 s bereit"; }
        done
        log "  Container gestartet: ${#STOPPED[@]}"
        STOPPED=()
        save_restore_state
    fi

    for c in "${!NC_ON[@]}"; do
        for i in $(seq 1 60); do
            nc_occ "$c" status >/dev/null 2>&1 && break
            sleep 2
        done
        if out="$(nc_occ "$c" maintenance:mode --off 2>&1)"; then
            log "  Nextcloud '$c': Wartungsmodus aus"
            unset "NC_ON[$c]"
            save_restore_state
        else
            nc_log_output "$out"
            warn "Nextcloud '$c': Wartungsmodus liess sich NICHT ausschalten"
            ub_notify "Wartungsmodus haengt" "Nextcloud '$c' steht noch im Wartungsmodus: occ maintenance:mode --off" "alert"
        fi
    done
}

##############################################################################
# Mounts
##############################################################################
umount_tree() { # umount_tree <wurzel>  - tiefste zuerst; 1 wenn etwas haengen blieb
    local root="$1" mp rc=0
    while IFS= read -r mp; do
        [[ -z "$mp" ]] && continue
        if ! umount "$mp" 2>/dev/null; then
            sleep 2
            umount "$mp" 2>>"$LOG_FILE" || { warn "Konnte $mp nicht aushaengen (belegt?)"; rc=1; continue; }
        fi
    done < <(mounts_below "$root")
    [[ -d "$root" ]] && find "$root" -xdev -mindepth 1 -depth -type d -empty -delete 2>/dev/null
    return $rc
}

unmount_all() {
    local rc=0
    if [[ -n "$(mounts_below "$MOUNT_ROOT")" ]]; then
        log "Haenge Snapshots unter $MOUNT_ROOT aus ..."
        umount_tree "$MOUNT_ROOT" || rc=1
    fi
    # In <view_root> gehoeren nur Symlinks - Mounts dort (z.B. von einem
    # frueheren Script) halten sonst Disks fest und werden geloest
    if [[ -n "$(mounts_below "$VIEW_ROOT")" ]]; then
        log "Loese alte Bind-Mounts unter $VIEW_ROOT ..."
        umount_tree "$VIEW_ROOT" || rc=1
    fi
    if [[ -n "$(mounts_below "$UB_STAGE")" ]]; then
        umount_tree "$UB_STAGE" || rc=1
    fi
    MOUNTED="no"; LAYER_MNT=(); SHARE_MOUNTED=()
    return $rc
}

# Privater Zwischenbereich (einmal pro Systemstart angelegt, bleibt bestehen).
# Was hier gemountet wird, wandert nicht in andere Mount-Namespaces.
stage_ready() {
    if ! mountpoint -q "$UB_STAGE"; then
        mkdir -p "$UB_STAGE" || return 1
        mount -t tmpfs -o size=1m,mode=0700 "$UB_NAME-stage" "$UB_STAGE" || return 1
    fi
    mount --make-private "$UB_STAGE" || return 1
    mkdir -p "$UB_STAGE/layers" "$UB_STAGE/bind"
}

# Read-only-Bind, der auch im Kopia-Container read-only ankommt.
# Ein direkt unter <mount_root> angelegter Bind wird beschreibbar an den
# Container weitergereicht - das spaetere "remount,ro" erreicht ihn nicht.
# Darum: im privaten Zwischenbereich binden, dort auf ro stellen und erst
# dann nach <ziel> verschieben. Die Kopie im Container entsteht so bereits ro.
ro_bind() { # ro_bind <quelle> <ziel>
    local st="$UB_STAGE/bind/b$$-$RANDOM"
    mkdir -p "$st" "$2" || return 1
    mount --bind "$1" "$st"                 || { rmdir "$st"; return 1; }
    mount --make-private "$st"              || { umount "$st"; rmdir "$st"; return 1; }
    mount -o remount,ro,bind "$st"          || { umount "$st"; rmdir "$st"; return 1; }
    mount --move "$st" "$2"                 || { umount "$st"; rmdir "$st"; return 1; }
    rmdir "$st" 2>/dev/null
    return 0
}

zfs_layer_mount() { # haengt <dataset>@SNAP im Zwischenbereich ein, gibt den Pfad aus
    local ds="$1" mp
    if [[ -n "${LAYER_MNT[$ds]:-}" ]]; then printf '%s' "${LAYER_MNT[$ds]}"; return 0; fi
    mp="$UB_STAGE/layers/${ds//\//_}"
    mkdir -p "$mp" || return 1
    mount -t zfs -o ro "$ds@$SNAP_NAME" "$mp" 2>>"$LOG_FILE" || return 1
    LAYER_MNT[$ds]="$mp"
    printf '%s' "$mp"
}

# Pfad einer Share-Lage im Snapshot (fuer overlay)
loc_snap_path() { # loc_snap_path <share> <lage-zeile>
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

# Eine Lage eines Shares samt Kind-Datasets an <ziel> einhaengen
mount_location() { # mount_location <share> <lage-zeile> <ziel>
    local s="$1" line="$2" target="$3" b m layer sub src
    IFS='|' read -r b m layer sub <<<"$line"
    if [[ "$m" == "zfs" && -z "$sub" && -z "${LAYER_MNT[$layer]:-}" ]]; then
        [[ -n "${ZFS_FAILED[${layer%%/*}]:-}" ]] && return 1
        mkdir -p "$target" && mount -t zfs -o ro "$layer@$SNAP_NAME" "$target" 2>>"$LOG_FILE" || return 1
    else
        # Schon im Zwischenbereich eingehaengt (Overlay-Versuch) oder Unterordner
        # eines Pool-Wurzel-Datasets -> von dort binden. Der Snapshot ist
        # selbst unveraenderlich, das Binden schwaecht nichts ab.
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
                || warn "Kind-Dataset $cds liess sich nicht einhaengen"
        else
            warn "Kind-Dataset $cds: Mountpunkt '$rel' fehlt im Snapshot von $s"
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
        # Kein Snapshot moeglich: der laufende Share, read-only eingebunden
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
        warn "Share '$s': Overlay nicht moeglich - Kopia sieht je Basis einen Unterordner"
        layout="split"
    fi

    if [[ "$layout" == "single" ]]; then
        mount_location "$s" "${locs[0]}" "$target" && { SHARE_MOUNTED[$s]="single"; return 0; }
        return 1
    fi

    # split: <mount_root>/<share>/<basis>
    ok=0
    for line in "${locs[@]}"; do
        mount_location "$s" "$line" "$target/${line%%|*}" && ok=$((ok+1)) \
            || warn "Share '$s': Lage ${line%%|*} liess sich nicht einhaengen"
    done
    (( ok == ${#locs[@]} )) && { SHARE_MOUNTED[$s]="split"; return 0; }
    return 1
}

share_mount_points() { # Mountpunkte, die fuer einen Share vorhanden sein muessen
    local s="$1" line
    case "${SHARE_MOUNTED[$s]:-}" in
        single|overlay|live) printf '%s\n' "$MOUNT_ROOT/$s" ;;
        split) while IFS= read -r line; do [[ -n "$line" ]] && printf '%s\n' "$MOUNT_ROOT/$s/${line%%|*}"; done <<<"${INV_LOCS[$s]}" ;;
    esac
}

##############################################################################
# Nextcloud
##############################################################################
nc_find_occ() { # setzt NC_OCC[c], NC_USER[c]
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
# Ausgabe eines fehlgeschlagenen occ-Aufrufs ins Protokoll (hoechstens 20 Zeilen)
nc_log_output() {
    local l
    while IFS= read -r l; do
        l="${l%$'\r'}"
        [[ -n "${l//[[:space:]]/}" ]] && log "    occ: $l"
    done < <(head -20 <<<"$1")
}

nextcloud_maintenance_on() {
    local c was pre id out try on
    local -A inst=()           # instanceid -> erster Container dieser Instanz
    while IFS= read -r c; do
        [[ -z "$c" ]] && continue
        if [[ "$(docker inspect -f '{{.State.Running}}' "$c" 2>/dev/null)" != "true" ]]; then
            warn "Nextcloud '$c' laeuft nicht - kein Wartungsmodus"; continue
        fi
        nc_find_occ "$c" || { warn "Nextcloud '$c': occ nicht gefunden - kein Wartungsmodus"; continue; }
        # Mehrere Container koennen EINE Nextcloud bedienen (z.B. App und Cron
        # mit gemeinsamer config.php). Der Wartungsmodus steht in dieser
        # config.php - nach dem ersten Container faende der zweite ihn "schon
        # an" vor. Die instanceid aus config.php erkennt solche Geschwister;
        # es gilt die Einstellung des ersten.
        id="$(nc_occ "$c" config:system:get instanceid 2>/dev/null | tr -d '\r')"
        if [[ -n "$id" && -n "${inst[$id]:-}" ]]; then
            NC_SAME[$c]="${inst[$id]}"
            log "  Nextcloud '$c': dieselbe Instanz wie '${inst[$id]}' - dort erledigt"
            continue
        fi
        [[ -n "$id" ]] && inst[$id]="$c"
        # 'maintenance:mode --on' meldet einen schon aktiven Modus nur mit
        # "already enabled" und Exitcode 0 - der Zustand muss vorher gelesen werden.
        was="$(nc_occ "$c" config:system:get maintenance 2>/dev/null | tr -d '\r')"
        pre="${UB_NC_PREEXISTING:-$(cfg "nextcloud|$c|preexisting_maintenance" abort)}"
        if [[ "$was" == "true" ]]; then
            [[ "$pre" == "abort" ]] && die "Nextcloud '$c' steht bereits im Wartungsmodus - Backup abgebrochen (UB_NC_PREEXISTING=continue erzwingt den Lauf)"
            warn "Nextcloud '$c' stand schon im Wartungsmodus - er bleibt danach AN"
            ub_notify "Wartungsmodus war schon an" "Nextcloud '$c' wurde trotzdem gesichert und bleibt im Wartungsmodus." "warning"
            continue
        fi
        # Vor dem Einschalten vormerken: setzt occ den Modus und scheitert erst
        # danach, schaltet der Abbruch ihn trotzdem wieder aus. Ein weiterer
        # Versuch nach so einem halben Erfolg meldet "already enabled" mit 0.
        NC_ON[$c]=1; save_restore_state
        on=0
        for try in 1 2 3; do
            if out="$(nc_occ "$c" maintenance:mode --on 2>&1)"; then on=1; break; fi
            nc_log_output "$out"
            (( try < 3 )) && { warn "Nextcloud '$c': Wartungsmodus nicht eingeschaltet (Versuch $try/3) - neuer Versuch in 5 s"; sleep 5; }
        done
        if (( on )); then
            log "  Nextcloud '$c': Wartungsmodus an"
        else
            # Nur vorgemerkt lassen, was wirklich an ist - sonst meldet der
            # Abbruch einen haengenden Wartungsmodus, den es nicht gibt.
            [[ "$(nc_occ "$c" config:system:get maintenance 2>/dev/null | tr -d '\r')" == "true" ]] \
                || { unset "NC_ON[$c]"; save_restore_state; }
            # the most common cause: Unraid reset the data folder (a share root) to 0777 when share settings were saved
            if grep -q "readable by other people" <<<"$out"; then
                die_code nc_datadir_readable "Nextcloud '$c': das Datenverzeichnis ist fuer andere lesbar, occ verweigert den Dienst. Unraid setzt die Wurzel eines Shares beim Speichern der Share-Einstellungen auf 0777. Abhilfe: chown 33:33 und chmod 0770 auf das Datenverzeichnis, dann neu starten."
            fi
            die "Nextcloud '$c': Wartungsmodus liess sich nicht einschalten (occ-Meldung im Protokoll)"
        fi
    done < <(cfg_names nextcloud)
    [[ ${#NC_ON[@]} -gt 0 ]] && sleep 5      # laufende Requests auslaufen lassen
    return 0
}

##############################################################################
# Datenbank-Dumps
##############################################################################
# Passwort per MYSQL_PWD statt -p: es taucht so nicht in der Prozessliste auf.
my_exec() { # my_exec <container> <bin> <login> <pw-variable> <args...>
    local c="$1" bin="$2" login="$3" pwv="$4"; shift 4
    docker exec "$c" sh -c 'eval "MYSQL_PWD=\${$3:-}"; export MYSQL_PWD; b="$1"; u="$2"; shift 3; exec "$b" -u"$u" "$@"' \
        sh "$bin" "$login" "$pwv" "$@"
}

dump_mariadb() { # dump_mariadb <container>
    local c="$1" login="" pwv="" single="" v uv pv dv pair dump_bin sql_bin dbs db out size tbl_dump tbl_live
    local -a extra
    for v in MARIADB_ROOT_PASSWORD MYSQL_ROOT_PASSWORD; do
        if docker exec "$c" sh -c "[ -n \"\${$v:-}\" ]" 2>/dev/null; then login="root"; pwv="$v"; break; fi
    done
    if [[ -z "$pwv" ]]; then
        # Kein Root-Passwort -> Anwendungsbenutzer, nur dessen Datenbank
        for pair in MARIADB_USER:MARIADB_PASSWORD:MARIADB_DATABASE MYSQL_USER:MYSQL_PASSWORD:MYSQL_DATABASE; do
            IFS=: read -r uv pv dv <<<"$pair"
            if docker exec "$c" sh -c "[ -n \"\${$pv:-}\" ]" 2>/dev/null; then
                login="$(docker exec "$c" sh -c "printf %s \"\${$uv:-}\"")"; pwv="$pv"
                single="$(docker exec "$c" sh -c "printf %s \"\${$dv:-}\"")"
                break
            fi
        done
    fi
    [[ -n "$pwv" && -n "$login" ]] || { err "MariaDB '$c': kein Zugang in den Umgebungsvariablen gefunden"; return 1; }

    if docker exec "$c" sh -c 'command -v mariadb-dump' >/dev/null 2>&1; then dump_bin="mariadb-dump"; sql_bin="mariadb"
    else dump_bin="mysqldump"; sql_bin="mysql"; fi
    # Ohne Root fehlen die Rechte fuer Routinen und Events
    if [[ "$login" == "root" ]]; then extra=( --routines --triggers --events ); else extra=( --triggers ); fi

    if [[ -n "$single" ]]; then dbs="$single"
    else
        dbs="$(my_exec "$c" "$sql_bin" "$login" "$pwv" -N -B -e \
            "SELECT schema_name FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema','performance_schema','sys','mysql')" 2>/dev/null)"
    fi
    [[ -n "$dbs" ]] || { err "MariaDB '$c': keine Datenbank gefunden"; return 1; }

    local rc=0
    for db in $dbs; do
        out="$RUN_DIR/db/mariadb_${c}_${db}.sql.gz"
        log "  MariaDB '$c' / $db ..."
        my_exec "$c" "$dump_bin" "$login" "$pwv" --single-transaction --quick --hex-blob "${extra[@]}" \
            --default-character-set=utf8mb4 --add-drop-database --databases "$db" \
            2>"$RUN_DIR/db/${c}_${db}.stderr" | gzip -6 >"$out"
        if [[ ${PIPESTATUS[0]} -ne 0 ]]; then err "Dump $c/$db fehlgeschlagen - siehe ${c}_${db}.stderr"; rc=1; continue; fi
        gzip -t "$out" 2>/dev/null                                   || { err "Dump $c/$db: gzip defekt"; rc=1; continue; }
        zcat "$out" | tail -5 | grep -q 'Dump completed'              || { err "Dump $c/$db unvollstaendig (Abschlusszeile fehlt)"; rc=1; continue; }
        size=$(stat -c %s "$out")
        tbl_dump=$(zcat "$out" | grep -c '^CREATE TABLE')
        tbl_live=$(my_exec "$c" "$sql_bin" "$login" "$pwv" -N -B -e \
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$db' AND table_type='BASE TABLE'" 2>/dev/null)
        if [[ "$tbl_dump" != "$tbl_live" ]]; then err "Dump $c/$db: $tbl_dump Tabellen im Dump, $tbl_live in der Datenbank"; rc=1; continue; fi
        rm -f "$RUN_DIR/db/${c}_${db}.stderr"
        log "    OK: $(human "$size"), $tbl_dump Tabellen"
    done
    return $rc
}

dump_postgres() { # dump_postgres <container>
    local c="$1" user out size
    user="$(docker exec "$c" sh -c 'printf %s "${POSTGRES_USER:-postgres}"')"
    out="$RUN_DIR/db/postgres_${c}.sql.gz"
    log "  Postgres '$c' (Benutzer $user) ..."
    # Ohne -t: mit TTY landet stderr im Dump und Zeilenenden werden zu CRLF
    docker exec "$c" sh -c 'PGPASSWORD="${POSTGRES_PASSWORD:-}" exec pg_dumpall --clean --if-exists --username="$1"' sh "$user" \
        2>"$RUN_DIR/db/${c}.stderr" | gzip -6 >"$out"
    if [[ ${PIPESTATUS[0]} -ne 0 ]]; then err "Dump $c fehlgeschlagen - siehe ${c}.stderr"; return 1; fi
    gzip -t "$out" 2>/dev/null || { err "Dump $c: gzip defekt"; return 1; }
    zcat "$out" | tail -5 | grep -q 'PostgreSQL database cluster dump complete' \
        || { err "Dump $c unvollstaendig (Abschlusszeile fehlt)"; return 1; }
    size=$(stat -c %s "$out")
    rm -f "$RUN_DIR/db/${c}.stderr"
    log "    OK: $(human "$size"), $(zcat "$out" | grep -c '^CREATE TABLE') Tabellen"
}

dump_mongodb() { # dump_mongodb <container>
    local c="$1" out size
    out="$RUN_DIR/db/mongodb_${c}.archive.gz"
    log "  MongoDB '$c' ..."
    docker exec "$c" sh -c 'command -v mongodump' >/dev/null 2>&1 || { err "MongoDB '$c': mongodump fehlt im Container"; return 1; }
    # Zugang aus den Umgebungsvariablen des Containers (offizielles Image)
    if ! docker exec "$c" sh -c 'if [ -n "${MONGO_INITDB_ROOT_USERNAME:-}" ]; then
            exec mongodump --quiet --archive --gzip --username "$MONGO_INITDB_ROOT_USERNAME" \
                 --password "$MONGO_INITDB_ROOT_PASSWORD" --authenticationDatabase admin
        else exec mongodump --quiet --archive --gzip; fi' >"$out" 2>"$RUN_DIR/db/${c}.stderr"; then
        err "Dump $c fehlgeschlagen - siehe ${c}.stderr"; return 1
    fi
    size=$(stat -c %s "$out")
    [[ "$size" -gt 0 ]] || { err "Dump $c ist leer"; return 1; }
    # Probe: das Archiv muss sich vollstaendig lesen lassen (nichts wird eingespielt)
    if docker exec -i "$c" sh -c 'if [ -n "${MONGO_INITDB_ROOT_USERNAME:-}" ]; then
            exec mongorestore --dryRun --quiet --archive --gzip --username "$MONGO_INITDB_ROOT_USERNAME" \
                 --password "$MONGO_INITDB_ROOT_PASSWORD" --authenticationDatabase admin
        else exec mongorestore --dryRun --quiet --archive --gzip; fi' <"$out" >/dev/null 2>>"$RUN_DIR/db/${c}.stderr"; then
        rm -f "$RUN_DIR/db/${c}.stderr"
        log "    OK: $(human "$size") (Archiv vollstaendig lesbar)"
    else
        err "Dump $c: Archiv laesst sich nicht vollstaendig lesen - siehe ${c}.stderr"; return 1
    fi
}

run_dumps() {
    local c t
    while IFS= read -r c; do
        [[ -z "$c" ]] && continue
        t="$(cfg "dump|$c|type")"
        if [[ "$(docker inspect -f '{{.State.Running}}' "$c" 2>/dev/null)" != "true" ]]; then
            warn "Datenbank-Container '$c' laeuft nicht - kein Dump"; continue
        fi
        case "$t" in
            mariadb)  dump_mariadb "$c" ;;
            postgres) dump_postgres "$c" ;;
            mongodb)  dump_mongodb "$c" ;;
        esac
    done < <(cfg_names dump)
    return 0
}

##############################################################################
# Manifest und Flash-Archiv
##############################################################################
write_manifest() {
    local M="$RUN_DIR/manifest" c f
    mkdir -p "$M/docker-templates" "$M/compose" "$M/shares" "$M/nextcloud"
    {
        echo "# Backup-Manifest $TS  ($UB_NAME $UB_VERSION)"
        echo "Host:        $(hostname)"
        echo "Unraid:      $(cat /etc/unraid-version 2>/dev/null)"
        echo "Kernel:      $(uname -r)"
        echo "Snapshot:    $SNAP_NAME"
        echo "Mounts:      $MOUNT_ROOT/<share>"
        echo "Kopia:       ${KOPIA_ID:-?} (${KOPIA_CONTAINER:-kein Container})"
        echo
        echo "## Shares an Kopia"
        printf '%s\n' "${PLAN_KOPIA[@]:-(keine)}"
        echo
        echo "## ZFS-Datasets im Snapshot"
        printf '%s\n' "${PLAN_ZFS[@]:-(keine)}"
        echo
        echo "## btrfs-Snapshots"
        printf '%s\n' "${PLAN_BTRFS[@]:-(keine)}"
        echo
        echo "## Container (Name, Image)"
        docker ps -a --format '{{.Names}}\t{{.Image}}' | sort
    } >"$M/manifest.txt"
    local -a ids=(); mapfile -t ids < <(docker ps -aq 2>/dev/null)
    if (( ${#ids[@]} )); then
        docker inspect --format '{{.Name}}  {{.Config.Image}}  {{.Image}}' "${ids[@]}" >"$M/docker-images.txt" 2>&1
    else
        : >"$M/docker-images.txt"
    fi
    docker ps -a --format '{{.Names}}\t{{.Image}}\t{{.Status}}'      >"$M/docker-ps.txt" 2>&1
    command -v zfs   >/dev/null && { zfs list -o name,used,refer,mountpoint >"$M/zfs-list.txt" 2>&1; zpool status >"$M/zpool-status.txt" 2>&1; }
    # --mounted: what the kernel knows - no raw reads of every device (a busy or sleeping disk held this up for minutes)
    command -v btrfs >/dev/null && timeout 30 btrfs filesystem show --mounted >"$M/btrfs-show.txt" 2>&1
    cp -a "$UB_SETTINGS" "$M/settings.ini" 2>/dev/null
    drift_text >"$M/abweichungen.txt"
    cp -a "$UB_BOOT"/config/plugins/dockerMan/templates-user/*.xml "$M/docker-templates/" 2>/dev/null
    cp -a "$UB_BOOT"/config/plugins/compose.manager/projects/.      "$M/compose/"          2>/dev/null
    cp -a "$UB_BOOT"/config/shares/*.cfg                             "$M/shares/"           2>/dev/null
    for c in "${!NC_OCC[@]}"; do
        [[ -n "${NC_SAME[$c]:-}" ]] && continue     # gleiche Instanz schon im Manifest
        docker exec "$c" cat "$(dirname "${NC_OCC[$c]}")/config/config.php" >"$M/nextcloud/${c}_config.php" 2>/dev/null \
            || warn "config.php von '$c' nicht lesbar"
        for f in status app:list "config:list system" files_external:list user:list; do
            # shellcheck disable=SC2086
            nc_occ "$c" $f >"$M/nextcloud/${c}_occ-$(tr ' :' '--' <<<"$f").txt" 2>&1
        done
    done
}

# Inhalt von libvirt.img (unter /etc/libvirt eingehaengt): XML, NVRAM,
# TPM-Zustaende, Snapshot-Liste, Netzwerke - als Archiv zu den Dumps. Nicht das
# Image selbst: das ist ein eingehaengtes btrfs-Dateisystem, eine Kopie
# waehrend laufender VMs koennte inkonsistent sein. Laufen VMs, ist ihr
# TPM-/NVRAM-Stand im Archiv nur absturzkonsistent (wie beim Ausschalten).
libvirt_tar() {
    local out="$RUN_DIR/libvirt.tar.gz"
    if ! mountpoint -q /etc/libvirt; then
        log "VM-Dienst aus - kein libvirt-Archiv"
        return 0
    fi
    log "Sichere die VM-Konfiguration (/etc/libvirt) als Archiv ..."
    if tar -C /etc -czf "$out" libvirt 2>>"$LOG_FILE" && gzip -t "$out" 2>/dev/null; then
        log "  OK: $(human "$(stat -c %s "$out")")"
    else
        err "libvirt-Archiv fehlgeschlagen"
    fi
}

flash_tar() {
    local out="$RUN_DIR/flash.tar.gz" ex=() e
    for e in "${FLASH_TAR_EXCLUDE[@]}"; do ex+=( "--exclude=$e" ); done
    log "Sichere $UB_BOOT als Archiv ..."
    if tar -C "$UB_BOOT" "${ex[@]}" -czf "$out" . 2>>"$LOG_FILE" && gzip -t "$out" 2>/dev/null; then
        log "  OK: $(human "$(stat -c %s "$out")")"
    else
        err "Flash-Archiv fehlgeschlagen"
    fi
}

##############################################################################
# Aufraeumen
##############################################################################
prune_zfs() { # prune_zfs <dataset> <"t w m">
    local ds="$1" keep_d keep_w keep_m s i n stamp day week month
    read -r keep_d keep_w keep_m <<<"$2"
    local -a snaps
    mapfile -t snaps < <(zfs list -H -t snapshot -o name -s creation -d 1 "$ds" 2>/dev/null \
                         | grep -E "@${SNAP_PREFIX}[0-9]{8}-[0-9]{4}$")
    [[ ${#snaps[@]} -eq 0 ]] && return 0
    local -A keep=() wk=() mo=()
    for (( i=${#snaps[@]}-1, n=0; i>=0 && n<keep_d; i--, n++ )); do keep[${snaps[$i]}]=1; done
    for (( i=${#snaps[@]}-1; i>=0; i-- )); do
        stamp="${snaps[$i]##*@"${SNAP_PREFIX}"}"
        day="${stamp:0:4}-${stamp:4:2}-${stamp:6:2}"
        week="$(date -d "$day" +%G-%V 2>/dev/null)"
        month="${stamp:0:6}"
        if (( keep_w > 0 )) && [[ -n "$week" && -z "${wk[$week]:-}" ]] && (( ${#wk[@]} < keep_w )); then
            wk[$week]=1; keep[${snaps[$i]}]=1
        fi
        if (( keep_m > 0 )) && [[ -z "${mo[$month]:-}" ]] && (( ${#mo[@]} < keep_m )); then
            mo[$month]=1; keep[${snaps[$i]}]=1
        fi
    done
    mounts_load
    for s in "${snaps[@]}"; do
        [[ -n "${keep[$s]:-}" ]] && continue
        if in_list "$s" "${MT_SOURCE[@]}"; then log "  bleibt (gemountet): $s"; continue; fi
        zfs destroy "$s" 2>>"$LOG_FILE" && log "  entfernt: $s"
    done
}

prune_btrfs() {
    local b base sdir s name cutoff free_gb oldest
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
                btrfs subvolume delete "${s%/}" >/dev/null 2>>"$LOG_FILE" && log "  entfernt: ${s%/}"
            fi
        done
        # Notbremse: bei knappem Platz den jeweils aeltesten Snapshot loesen
        while :; do
            free_gb=$(( $(df -Pm "$base" | awk 'NR==2{print $4}') / 1024 ))
            (( free_gb >= BTRFS_MIN_FREE_GB )) && break
            oldest="$(find "$sdir" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null \
                      | grep -E '^[0-9]{8}-[0-9]{4}$' | sort | head -1)"
            if [[ -z "$oldest" || "$oldest" == "$TS" ]]; then
                warn "$b: nur ${free_gb} GB frei, kein Snapshot mehr loeschbar"
                ub_notify "Platz wird knapp" "$b hat nur noch ${free_gb} GB frei." "warning"
                break
            fi
            btrfs subvolume delete "$sdir/$oldest" >/dev/null 2>>"$LOG_FILE" || break
            warn "$b: nur ${free_gb} GB frei - Snapshot $oldest vorzeitig geloescht"
        done
    done
}

refresh_view() { # Durchstoeber-Ansicht: Symlinks statt Bind-Mounts (halten keine Disk fest)
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
    local d
    [[ -n "$UB_DUMPS" && "$UB_DUMPS" == "$UB_MNT"/user/?*/"$UB_NAME" ]] || return 0     # never with an empty or odd path
    ls -1d "$UB_DUMPS"/[0-9]*-[0-9]* 2>/dev/null | sort | head -n -"$KEEP_RUNS" \
        | while read -r d; do rm -rf "$d" && log "  entfernt: $d"; done
    ls -1 "$UB_LOGS"/run-*.log "$UB_LOGS"/check-*.log "$UB_LOGS"/dryrun-*.log 2>/dev/null | sort -t- -k2 | head -n -"$KEEP_LOGS" \
        | while read -r d; do rm -f "$d"; done
    if [[ -f "$UB_LOGS/unmount.log" && $(stat -c %s "$UB_LOGS/unmount.log") -gt 1048576 ]]; then
        mv "$UB_LOGS/unmount.log" "$UB_LOGS/unmount.log.1"
    fi
}

##############################################################################
# Abweichungen melden
##############################################################################
report_drift() {
    local fp last_fp="" last_t=0 now nw ne lvl
    drift_text >"$UB_STATE/drift.txt"
    drift_json_write
    status_write
    if [[ ${#DRIFT[@]} -eq 0 ]]; then
        log "Keine Abweichungen zu settings.ini."
        rm -f "$UB_STATE/drift.fp" "$UB_STATE/drift.ts"
        return 0
    fi
    log "Abweichungen zu settings.ini:"
    while IFS= read -r l; do log "  $l"; done <"$UB_STATE/drift.txt"
    nw=$(drift_count warn); ne=$(drift_count error)
    (( nw + ne == 0 )) && return 0
    fp="$(drift_fingerprint)"
    [[ -f "$UB_STATE/drift.fp" ]] && last_fp="$(cat "$UB_STATE/drift.fp")"
    [[ -f "$UB_STATE/drift.ts" ]] && last_t="$(cat "$UB_STATE/drift.ts")"
    now="$(date +%s)"
    if [[ "$fp" != "$last_fp" || "$UB_MODE" == "check" ]] || (( now - last_t >= DRIFT_REMIND_DAYS * 86400 )); then
        lvl="warning"; (( ne > 0 )) && lvl="alert"
        ub_notify "settings.ini nicht mehr aktuell" \
            "${ne} Fehler, ${nw} Warnungen - bitte setup.sh ausfuehren. Details: $UB_STATE/drift.txt" \
            "$lvl" "$(grep -v '^INFO' "$UB_STATE/drift.txt")"
        echo "$fp" >"$UB_STATE/drift.fp"; echo "$now" >"$UB_STATE/drift.ts"
    fi
}

##############################################################################
# Aufraeumen bei jedem Ende
##############################################################################
# Abbrechen mitten in Kopia: den Snapshot im Container beenden (SIGINT -
# Kopia schliesst dann sauber ab). Nur den docker-exec-Client zu beenden
# reicht nicht, der Prozess im Container liefe weiter und hielte die Mounts.
kopia_stop() {
    [[ -n "$KOPIA_PID" ]] || return 0
    local pid args t
    log "Beende den laufenden Kopia-Snapshot ..."
    while read -r pid args; do
        [[ "$pid" =~ ^[0-9]+$ && "$args" == *"snapshot create"* && "$args" == *"$KOPIA_CP"* ]] && kill -INT "$pid" 2>/dev/null
    done < <(docker top "$KOPIA_CONTAINER" -eo pid,args 2>/dev/null | tail -n +2)
    for t in $(seq 1 60); do
        kill -0 "$KOPIA_PID" 2>/dev/null || break
        sleep 1
    done
    if kill -0 "$KOPIA_PID" 2>/dev/null; then
        pkill -TERM -P "$KOPIA_PID" 2>/dev/null; kill -TERM "$KOPIA_PID" 2>/dev/null
        warn "Kopia hat sich nach 60 s nicht beendet"
    fi
    KOPIA_PID=""
}

cleanup() {
    local rc=$?
    [[ "$CLEANUP_DONE" == "yes" ]] && exit "$rc"
    CLEANUP_DONE="yes"
    if [[ "$ST_ABORTED" == "yes" ]]; then
        warn "Lauf abgebrochen (Signal)"
        status_phase "aborting"
    fi
    kopia_stop
    if [[ ${#STOPPED[@]} -gt 0 || ${#NC_ON[@]} -gt 0 ]]; then
        log "Stelle den Normalbetrieb wieder her ..."
        restore_service
        # a stop mid-run: the interruption lasted until now (otherwise the status says 0 s)
        [[ -n "${STOP_AT:-}" && "${DOWNTIME:-0}" == 0 ]] && DOWNTIME=$(( $(date +%s) - STOP_AT ))
    fi
    if [[ "$MOUNTED" == "yes" && "$KEEP_MOUNTS" != "yes" ]]; then
        unmount_all
    fi
    if [[ "$ST_ABORTED" == "yes" ]]; then status_finish aborted "signal"
    elif (( rc != 0 )); then status_finish failed "exit $rc"
    fi
    exit "$rc"
}
on_signal() { ST_ABORTED="yes"; exit 143; }

##############################################################################
# Start
##############################################################################
exec 9>"$UB_STATE/lock"
if [[ "$UB_MODE" == "unmount" ]]; then
    flock -w 10 9 || echo "$(_ts)  Ein Lauf haelt die Sperre - haenge trotzdem aus" >>"$LOG_FILE"
else
    flock -n 9 || { echo "Ein anderer Lauf (backup.sh oder setup.sh) ist aktiv."; exit 1; }
    if [[ "$UB_MODE" == "check" ]]; then status_init check
    elif [[ "$DRY" == "1" ]]; then status_init dryrun
    else status_init backup; fi
fi

SETTINGS_OK="yes"
load_settings || { SETTINGS_OK="no"; apply_settings; }

if [[ "$UB_MODE" == "unmount" ]]; then
    # Muss auch mit fehlender oder kaputter settings.ini funktionieren
    log "Aushaengen angefordert."
    if unmount_all; then log "Alles ausgehaengt."; else log "Nicht alles liess sich aushaengen."; fi
    exit 0
fi

[[ "$SETTINGS_OK" == "yes" ]] || die "settings.ini fehlt ($UB_SETTINGS) - zuerst setup.sh ausfuehren"
if [[ ${#CFG_ERRORS[@]} -gt 0 ]]; then
    for e in "${CFG_ERRORS[@]}"; do err "settings.ini: $e"; done
    die "settings.ini ist fehlerhaft (${#CFG_ERRORS[@]} Fehler) - Lauf abgebrochen"
fi

trap cleanup EXIT
trap on_signal INT TERM

log "===================== $UB_NAME $UB_VERSION - $UB_MODE $TS ====================="
recover_interrupted_run
[[ "$DRY" == "1" ]] && log "TROCKENLAUF - es wird nichts veraendert."

for t in docker jq flock timeout gzip numfmt mountpoint awk; do
    command -v "$t" >/dev/null 2>&1 || die "Werkzeug '$t' fehlt"
done
docker info >/dev/null 2>&1 || die "Docker antwortet nicht"
mountpoint -q "$UB_MNT/user" || die "$UB_MNT/user ist nicht gemountet - Array/Pools nicht gestartet?"

SNAP_NAME="${SNAP_PREFIX}${TS}"
RUN_DIR="$UB_DUMPS/$TS"

# --- Inventar, Plan, Abweichungen ----------------------------------------
status_phase "inventory"
inv_scan
docker_load
plan_build
[[ ${#PLAN_ZFS[@]}   -gt 0 ]] && ! command -v zfs   >/dev/null && die "zfs fehlt"
[[ ${#PLAN_BTRFS[@]} -gt 0 ]] && ! command -v btrfs >/dev/null && die "btrfs fehlt"

drift_check_shares
drift_check_containers
if [[ "$SKIPK" != "1" ]]; then drift_check_kopia; else KOPIA_OK="skip"; fi

# Die Backup-Ablage: ein eigener Share, gesichert - sonst kein Lauf (nichts ist bis hier angehalten)
DUMPS_PROBLEM="$(dumps_share_problem "$DUMPS_SHARE")"
if [[ -n "$DUMPS_PROBLEM" ]]; then
    if [[ "$UB_MODE" == "check" ]]; then
        drift_add error "$(dumps_share_text "$DUMPS_PROBLEM" "$DUMPS_SHARE")"
    else
        die_code "dumps_$DUMPS_PROBLEM" "$(dumps_share_text "$DUMPS_PROBLEM" "$DUMPS_SHARE") - Ablage bei Herrn Backupsi unter Einrichten waehlen"
    fi
elif is_yes "$KOPIA_ENABLED" && [[ "$(share_mode "$DUMPS_SHARE")" != "kopia" ]]; then
    drift_add warn "Die Backup-Ablage '$DUMPS_SHARE' geht nicht an Kopia (mode=$(share_mode "$DUMPS_SHARE")) - Dumps und Archive bleiben nur lokal"
fi
if [[ -z "$DUMPS_PROBLEM" && "$DRY" != "1" && "$UB_MODE" != "check" ]]; then
    # nur root: Dumps enthalten Datenbankinhalte
    mkdir -p "$UB_DUMPS" && chmod 700 "$UB_DUMPS" || die "Backup-Ablage $UB_DUMPS laesst sich nicht anlegen"
    # bisherige Dumps aus dem Datenordner (appdata) einmal mitnehmen
    if [[ -d "$UB_DATA/dumps" ]]; then
        for d in "$UB_DATA"/dumps/[0-9]*-[0-9]*; do
            [[ -d "$d" && ! -e "$UB_DUMPS/$(basename "$d")" ]] || continue
            mv "$d" "$UB_DUMPS/" 2>>"$LOG_FILE" && log "  Dumps $(basename "$d") nach $UB_DUMPS verschoben"
        done
        rmdir "$UB_DATA/dumps" 2>/dev/null && log "  Alter Dump-Ordner in appdata entfernt"
    fi
fi

report_drift

if [[ "$UB_MODE" == "check" ]]; then
    log "Pruefung beendet: $(drift_count error) Fehler, $(drift_count warn) Warnungen, $(drift_count info) Hinweise."
    if (( $(drift_count error) > 0 || ERRORS > 0 )); then status_finish errors
    elif (( $(drift_count warn) > 0 || WARNINGS > 0 )); then status_finish warnings
    else status_finish ok; fi
    trap - EXIT
    exit 0
fi

case "$KOPIA_OK" in
    no)  err "Kopia wird in diesem Lauf uebersprungen (siehe Abweichungen oben)" ;;
    off) log "Kopia ist ausgeschaltet ([kopia] enabled = no) - nur lokale Snapshots und Dumps." ;;
esac

# --- Plan ausgeben -------------------------------------------------------
build_stop_tiers
log "Plan:"
log "  ZFS-Snapshots:    ${PLAN_ZFS[*]:-keine}"
log "  btrfs-Snapshots:  ${PLAN_BTRFS[*]:-keine}"
log "  Flash:            $PLAN_FLASH${FLASH_DATASET:+ ($FLASH_DATASET)}"
log "  VM-Konfiguration: $LIBVIRT_MODE$(mountpoint -q /etc/libvirt || echo ' (VM-Dienst aus)')"
log "  Dumps:            $(cfg_names dump | paste -sd' ' -)"
log "  Nextcloud:        $(cfg_names nextcloud | paste -sd' ' -)"
log "  Anhalten:         ${T_APP[*]:-} | DB: ${T_DB[*]:-} | Netz: ${T_NET[*]:-}"
log "  Nicht anhalten:   ${KOPIA_CONTAINER:-} ${DOCKER_NO_STOP[*]:-}"
is_yes "$KOPIA_ENABLED" || log "  Kopia:            aus"
for s in "${PLAN_KOPIA[@]}"; do
    hp="$(share_kopia_hostpath "$s")"
    cp="$(k_path "$hp" 2>/dev/null)" || cp="?"
    log "  Kopia:            $s  [$(share_method "$s")/${INV_LAYOUT[$s]}]  $hp -> $cp${SKIP_KOPIA[$s]:+  (UEBERSPRUNGEN: ${SKIP_KOPIA[$s]})}"
done

if [[ "$KOPIA_OK" == "yes" && "$SKIPK" != "1" ]]; then
    ST_KOPIA_PLAN=( "${PLAN_KOPIA[@]}" )
    [[ "$PLAN_FLASH" == "snapshot" ]] && ST_KOPIA_PLAN+=( "flash" )
fi
status_write

if [[ "$DRY" == "1" ]]; then
    log "Trockenlauf beendet."
    if (( ERRORS > 0 )); then status_finish errors
    elif (( WARNINGS > 0 )); then status_finish warnings
    else status_finish ok; fi
    trap - EXIT
    exit 0
fi

FREE_MB=$(df -Pm "$UB_DUMPS" 2>/dev/null | awk 'NR==2{print $4}')
(( ${FREE_MB:-0} >= MIN_FREE_GB * 1024 )) || die "Zu wenig Platz fuer Dumps in $UB_DUMPS (${FREE_MB} MB frei)"
mkdir -p "$RUN_DIR/db" || die "Kann $RUN_DIR nicht anlegen"

# Reste eines frueheren Laufs loesen
unmount_all || die "Alte Mounts unter $MOUNT_ROOT lassen sich nicht loesen"

# --- Wartungsmodus, Manifest ----------------------------------------------
status_phase "maintenance"
log "Nextcloud ..."
nextcloud_maintenance_on
status_phase "manifest"
log "Manifest ..."
write_manifest                       # noch mit allen Containern im laufenden Zustand

# --- Anhalten, Dumps, Snapshots, Starten ----------------------------------
# Erst die Apps anhalten, dann dumpen: Apps ohne Wartungsmodus (Immich & Co.)
# schreiben sonst zwischen Dump und Snapshot weiter - der Dump passte dann
# nicht mehr ganz zu den Dateien. Die Datenbanken laufen fuer den Dump noch.
STOP_AT="$(date +%s)"
status_phase "stopping_apps"
log "Halte Apps an: ${#T_APP[@]} (vor den Dumps, damit Dumps und Dateien zusammenpassen)"
stop_tier "${T_APP[@]}"
status_phase "dumps"
log "Datenbank-Dumps ..."
run_dumps
[[ "$PLAN_FLASH" == "tar" ]] && flash_tar
[[ "$LIBVIRT_MODE" == "tar" ]] && libvirt_tar
status_phase "stopping"
log "Halte an: ${#T_DB[@]} Datenbanken, ${#T_NET[@]} Netzwerk"
stop_tier "${T_DB[@]}"
stop_tier "${T_NET[@]}"

status_phase "snapshots"
log "Erzeuge Snapshots $SNAP_NAME ..."
declare -A BY_POOL=()
for ds in "${PLAN_ZFS[@]}"; do BY_POOL[${ds%%/*}]+="$ds@$SNAP_NAME"$'\n'; done
for pool in "${!BY_POOL[@]}"; do
    mapfile -t args < <(printf '%s' "${BY_POOL[$pool]}" | sed '/^$/d')
    if zfs snapshot "${args[@]}" 2>>"$LOG_FILE"; then
        log "  ZFS $pool: ${#args[@]} Datasets"
    else
        err "ZFS-Snapshot auf Pool '$pool' fehlgeschlagen"; ZFS_FAILED[$pool]=1
    fi
done
if [[ "$PLAN_FLASH" == "snapshot" ]]; then
    zfs snapshot "$FLASH_DATASET@$SNAP_NAME" 2>>"$LOG_FILE" \
        || { err "Flash-Snapshot fehlgeschlagen"; PLAN_FLASH="failed"; }
fi
for base in "${PLAN_BTRFS[@]}"; do
    mkdir -p "$base/$BTRFS_SNAP_DIR"
    if btrfs subvolume snapshot -r "$base" "$base/$BTRFS_SNAP_DIR/$TS" >/dev/null 2>>"$LOG_FILE"; then
        BTRFS_OK[$base]="$base/$BTRFS_SNAP_DIR/$TS"
        log "  btrfs $base"
    else
        err "btrfs-Snapshot von $base fehlgeschlagen"
    fi
done

status_phase "starting"
log "Starte Container ..."
restore_service
DOWNTIME=$(( $(date +%s) - STOP_AT ))
status_write
log "Normalbetrieb wiederhergestellt - Unterbrechung ${DOWNTIME} s"

# --- Einhaengen (nur, wenn Kopia diesmal wirklich laeuft) ------------------
if [[ "$KOPIA_OK" == "yes" && "$SKIPK" != "1" ]]; then
    status_phase "mounting"
    log "Haenge Snapshots unter $MOUNT_ROOT ein ..."
    mkdir -p "$MOUNT_ROOT"
    stage_ready || die "Privater Zwischenbereich $UB_STAGE laesst sich nicht anlegen"
    MOUNTED="yes"
    for s in "${PLAN_KOPIA[@]}"; do
        if mount_share "$s"; then log "  $s (${SHARE_MOUNTED[$s]})"
        else err "Share '$s' liess sich nicht einhaengen - wird nicht gesichert"; fi
    done
    if [[ "$PLAN_FLASH" == "snapshot" ]]; then
        if mkdir -p "$MOUNT_ROOT/$FLASH_SOURCE_NAME" && \
           mount -t zfs -o ro "$FLASH_DATASET@$SNAP_NAME" "$MOUNT_ROOT/$FLASH_SOURCE_NAME" 2>>"$LOG_FILE"; then :
        else err "Flash-Snapshot liess sich nicht einhaengen"; PLAN_FLASH="failed"; fi
    fi
fi
refresh_view

# --- Kopia ----------------------------------------------------------------
KOPIA_DONE=0; KOPIA_FAILED=0
kopia_one() { # kopia_one <name> <container-pfad>
    local name="$1" cp="$2" t0 rc secs
    t0="$(date +%s)"
    log "Kopia: $name  ($cp)"
    ST_KOPIA_CUR="$name"; ST_KOPIA_CUR_T="$t0"; status_write
    # Im Hintergrund + wait: ein Signal (Abbrechen) unterbricht wait sofort,
    # waehrend bash auf einen Vordergrund-Befehl bis zu dessen Ende wartet -
    # bei Kopia koennen das Stunden sein. kopia_stop beendet ihn dann.
    KOPIA_CP="$cp"
    kopia_x snapshot create "$cp" --description "$UB_NAME $TS" >>"$LOG_FILE" 2>&1 &
    KOPIA_PID=$!
    wait "$KOPIA_PID"; rc=$?
    KOPIA_PID=""; KOPIA_CP=""
    secs=$(( $(date +%s) - t0 ))
    if [[ $rc -eq 0 ]]; then KOPIA_DONE=$((KOPIA_DONE+1)); log "  ok ($secs s)"
    else KOPIA_FAILED=$((KOPIA_FAILED+1)); err "Kopia-Snapshot von '$name' fehlgeschlagen (Exitcode $rc)"; fi
    ST_KOPIA_DONE+=( "$name|$([[ $rc -eq 0 ]] && echo 1 || echo 0)|$secs|$(date +%s)" )
    ST_KOPIA_CUR=""; status_write
}

kopia_skip() { # Quelle nicht gesichert, ohne dass Kopia lief
    KOPIA_FAILED=$((KOPIA_FAILED+1))
    ST_KOPIA_DONE+=( "$1|0|0|$(date +%s)" ); status_write
}

if [[ "$SKIPK" == "1" ]]; then
    log "Kopia uebersprungen (UB_SKIP_KOPIA=1)."
elif [[ "$KOPIA_OK" == "off" ]]; then
    log "Kopia ausgeschaltet - lokale Snapshots und Dumps sind fertig."
elif [[ "$KOPIA_OK" == "yes" ]]; then
    status_phase "kopia"
    kopia_mountinfo_load
    for s in "${PLAN_KOPIA[@]}"; do
        if [[ -n "${SKIP_KOPIA[$s]:-}" ]]; then err "Kopia: '$s' uebersprungen - ${SKIP_KOPIA[$s]}"; kopia_skip "$s"; continue; fi
        hp="$(share_kopia_hostpath "$s")"
        cp="$(k_path "$hp")" || { err "Kopia: '$s' ist im Container nicht abgebildet"; kopia_skip "$s"; continue; }
        if [[ -z "${SHARE_MOUNTED[$s]:-}" ]]; then kopia_skip "$s"; continue; fi
        # Nie ein leeres Verzeichnis sichern: der Mount muss im Container da sein
        missing=""
        while IFS= read -r mp; do
            [[ -z "$mp" ]] && continue
            c_mp="$(k_path "$mp")"
            [[ -n "${KMI[$c_mp]+x}" ]] || missing+="$c_mp "
        done < <(share_mount_points "$s")
        if [[ -n "$missing" ]]; then
            err "Kopia sieht den Mount nicht: $missing- Mapping auf 'Read Only - Slave' pruefen"
            kopia_skip "$s"; continue
        fi
        [[ "${SHARE_MOUNTED[$s]}" == "live" ]] && log "  (live: '$s' wird ohne Snapshot gelesen)"
        kopia_one "$s" "$cp"
    done
    if [[ "$PLAN_FLASH" == "snapshot" ]]; then
        cp="$(k_path "$MOUNT_ROOT/$FLASH_SOURCE_NAME")"
        if [[ -n "${KMI[$cp]+x}" ]]; then kopia_one "flash" "$cp"
        else err "Kopia sieht den Flash-Snapshot nicht ($cp)"; kopia_skip "flash"; fi
    fi
    [[ $KOPIA_FAILED -gt 0 ]] && ub_notify "Kopia unvollstaendig" \
        "$KOPIA_FAILED Quelle(n) nicht gesichert, $KOPIA_DONE ok. Protokoll: $LOG_FILE" "warning"
fi

# --- Aushaengen und Aufraeumen ---------------------------------------------
if [[ "$KEEP_MOUNTS" != "yes" ]]; then
    status_phase "unmounting"
    unmount_all || warn "Nicht alle Snapshot-Mounts liessen sich loesen"
fi

status_phase "cleanup"
log "Raeume auf ..."
if command -v zfs >/dev/null 2>&1; then
    mapfile -t OWNERS < <(zfs list -H -t snapshot -o name 2>/dev/null \
        | grep -E "@${SNAP_PREFIX}[0-9]{8}-[0-9]{4}$" | sed 's/@.*//' | sort -u)
    for ds in "${OWNERS[@]}"; do
        prune_zfs "$ds" "${PLAN_ZFS_RET[$ds]:-$ZFS_RETENTION}"
    done
fi
command -v btrfs >/dev/null 2>&1 && prune_btrfs
prune_files

# --- Abschluss -----------------------------------------------------------
TOTAL=$(( $(date +%s) - STARTED_AT ))
RUN_SIZE="$(du -sh "$RUN_DIR" 2>/dev/null | cut -f1)"
ST_DUMP_BYTES="$(du -sb "$RUN_DIR" 2>/dev/null | cut -f1)"; is_uint "$ST_DUMP_BYTES" || ST_DUMP_BYTES=0
{
    echo "ts=$TS"
    echo "dauer_s=$TOTAL"
    echo "unterbrechung_s=$DOWNTIME"
    echo "fehler=$ERRORS"
    echo "warnungen=$WARNINGS"
    echo "kopia_ok=$KOPIA_DONE"
    echo "kopia_fehler=$KOPIA_FAILED"
    echo "abweichungen=$(drift_count warn)/$(drift_count error)"
    echo "log=$LOG_FILE"
} >"$UB_STATE/last-run"

if is_yes "$KOPIA_ENABLED"; then KSUM="Kopia ${KOPIA_DONE} ok/${KOPIA_FAILED} Fehler"; else KSUM="Kopia aus"; fi
SUMMARY="Dauer ${TOTAL}s, Unterbrechung ${DOWNTIME}s, ${KSUM}, Dumps ${RUN_SIZE}"
if (( ERRORS > 0 )); then status_finish errors
elif (( WARNINGS > 0 )); then status_finish warnings
else status_finish ok; fi
if (( ERRORS > 0 )); then
    log "Backup mit ${ERRORS} Fehler(n) und ${WARNINGS} Warnung(en) beendet. $SUMMARY"
    ub_notify "Backup mit Fehlern" "${ERRORS} Fehler, ${WARNINGS} Warnungen. $SUMMARY" "alert" "Protokoll: $LOG_FILE"
    exit 1
elif (( WARNINGS > 0 )); then
    log "Backup mit ${WARNINGS} Warnung(en) beendet. $SUMMARY"
    ub_notify "Backup mit Warnungen" "${WARNINGS} Warnungen. $SUMMARY" "warning" "Protokoll: $LOG_FILE"
else
    log "Backup erfolgreich. $SUMMARY"
    is_yes "$NOTIFY_SUCCESS" && ub_notify "Backup erfolgreich" "$SUMMARY" "normal"
fi
exit 0
