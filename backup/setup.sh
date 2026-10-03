#!/bin/bash
###############################################################################
# unraid-backup - setup.sh                        Version 2.11 - 3.10.2026
#   2.11 User-Scripts-Eintrag unraid-secretary-office_backup (der alte Name wird umgezogen)
#   2.10 VM-Konfiguration (libvirt.img) wird als Archiv vorgeschlagen
#   2.9  Neue Shares mit unbekannter Groesse (nicht gemessen, kein ZFS) werden
#        nur lokal vorgeschlagen, nicht mehr ungefragt fuer Kopia; Snapshot-
#        Praefix-Vorgabe "unraidbackup-"; Medienserver (Emby, Jellyfin, Plex)
#        werden zum Weiterlaufen vorgeschlagen
#   2.8  (nur backup.sh: Apps vor den Dumps anhalten)
#   2.7  --plan / --apply fuer den Setup-Assistenten von Herrn Backup: Vorschlaege
#        mit Begruendungs-Codes als JSON, Entscheidungen als JSON zurueck,
#        Fortschritt in state/setup-status.json
#   2.6  Teil des Unraid Secretary Office: Daten in <office>/data/unraid-backup
#   2.5  (nur backup.sh: Status-Dateien fuer andere Programme)
#   2.4  Lesbarere Ausgabe: Schritte als farbiger Balken, Tabellen mit
#        Kopfzeile und leicht hinterlegter jeder zweiter Zeile (UB_STRIPES),
#        Fragen hervorgehoben; Nextcloud aus mehreren Containern (App + Cron)
#        wird als eine Instanz erkannt und nur einmal gefragt
#   2.3  Fix "gone_by_id: bad array subscript" bei Shares ohne Daten; Time
#        Machine auch per Name und Container erkannt; Container-Daten (appdata)
#        werden nicht wegen der Groesse abgeschaltet; Spalte "Lage" kuerzer
#   2.2  Allgemeine Fassung ohne serverspezifische Uebernahmen; Time-Machine-
#        Shares werden erkannt; eigener Snapshot-Praefix "ub-"
#   2.1  Kopia optional (Schritt 3, mit Erklaerung), mount_root wird angelegt,
#        Datenbanken in Containern und Compose-Stacks, MongoDB
#   2.0  Erstfassung
#
# Prueft den Server, macht Vorschlaege und schreibt sie nach Rueckfrage in
# settings.ini. Kopia (verschluesseltes Offsite-Backup) ist optional; wenn
# gewuenscht, richtet setup.sh es ein: prueft Container-Mapping,
# Mount-Weitergabe und Repository, setzt die Policies und stellt alte
# Quellen still. Laeuft im Terminal (SSH oder Unraid-Web-Terminal), nicht
# ueber User Scripts - dort gibt es keine Eingabe.
#
# Erneut aufrufen, wann immer backup.sh Abweichungen meldet: Bestehende
# Entscheidungen aus settings.ini sind dann die Vorgaben, neu ist nur, was
# sich geaendert hat.
#
# AUFRUF
#   /mnt/user/appdata/UnraidSecretaryOffice/backup/setup.sh [Option]
#
# VARIANTEN (Umgebungsvariable - die Option dahinter ist eine Abkuerzung)
#   UB_SETUP=interactive          alles pruefen, fragen, schreiben (Vorgabe)
#   UB_SETUP=check     --check    nur pruefen und berichten, nichts schreiben
#   UB_SETUP=kopia     --kopia    nur den Kopia-Teil mit der bestehenden
#                                 settings.ini (Policies angleichen)
#   UB_SETUP=plan      --plan     wie --yes, schreibt aber nichts: alle Vorschlaege,
#                                 Begruendungen und Pruefergebnisse nach
#                                 state/setup-plan.json (fuer Herrn Backup)
#   UB_SETUP=apply     --apply=<datei>  Entscheidungen (JSON: settings.ini-Schluessel
#                                 wie in setup-plan.json -> Wert) ueber die bisherigen
#                                 Werte legen, dann wie --yes pruefen und schreiben
#                                 Beide schreiben ihren Fortschritt nach
#                                 state/setup-status.json
#   UB_YES=1           --yes      alle Vorschlaege ohne Rueckfrage uebernehmen
#                                 (auch zusammen mit --kopia; loescht nie Kopia-Quellen)
#   UB_EXPLAIN=0                  Erklaerungen zu jedem Schritt weglassen
#                                 (Vorgabe 1 = alles erlaeutern)
#   UB_SIZE_TIMEOUT=120           Sekunden je Share fuer die Groessenmessung
#                                 (du); 0 = nicht messen
#   UB_SETTINGS=/pfad/settings.ini  andere Einstellungsdatei
#   UB_STRIPES=auto               Tabellenzeilen abwechselnd leicht hinterlegt;
#                                 auto fragt das Terminal nach seinem Hinter-
#                                 grund, dark / light legen ihn fest, off = aus
#   Beispiel: UB_SETUP=check /mnt/user/appdata/UnraidSecretaryOffice/backup/setup.sh
#
# Was setup.sh NIE tut: Container-Templates aendern, Kopia mit einem
# Repository verbinden, Snapshots oder Daten loeschen (ausser du bestaetigst
# das Loeschen einer alten Kopia-Quelle ausdruecklich mit LOESCHEN).
###############################################################################

set -uo pipefail

# shellcheck source=lib/common.sh
source "$(dirname "$(readlink -f "$0")")/lib/common.sh" \
    || { echo "lib/common.sh fehlt neben setup.sh"; exit 1; }

usage() { awk 'NR>1 && /^#+$/ {next} NR>1 && /^#/ {sub(/^# ?/,""); print; next} NR>1 {exit}' "$0"; }

for a in "$@"; do
    case "$a" in
        --check) UB_SETUP="check" ;;
        --kopia) UB_SETUP="kopia" ;;
        --plan)  UB_SETUP="plan" ;;
        --apply=*) UB_SETUP="apply"; UB_DECISIONS="${a#--apply=}" ;;
        --yes)   UB_YES=1 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unbekannte Option: $a  (siehe --help)"; exit 2 ;;
    esac
done
MODE="${UB_SETUP:-interactive}"
YES="${UB_YES:-0}"
[[ "$MODE" == "auto" || "$MODE" == "plan" || "$MODE" == "apply" ]] && YES=1
[[ "$MODE" == "interactive" && "$YES" == "1" ]] && MODE="auto"
case "$MODE" in interactive|check|kopia|auto|plan|apply) ;; *) echo "UB_SETUP=$MODE ist unbekannt"; exit 2 ;; esac
SIZE_TIMEOUT="${UB_SIZE_TIMEOUT:-120}"
EXPLAIN="${UB_EXPLAIN:-1}"

if [[ "$YES" != "1" && ( "$MODE" == "interactive" || "$MODE" == "kopia" ) ]] && ! [[ -r /dev/tty && -t 1 ]]; then
    echo "Kein Terminal - setup.sh braucht Eingaben. Ohne Rueckfragen: --yes, nur pruefen: --check"
    exit 2
fi
[[ $EUID -eq 0 ]] || { echo "Bitte als root ausfuehren."; exit 1; }
ub_data_dirs || { echo "Kann Ordner in $UB_DATA nicht anlegen"; exit 1; }
TS="$(date +%Y%m%d-%H%M)"
LOG_FILE="$UB_LOGS/setup-$TS.log"

##############################################################################
# Ausgabe und Eingabe
##############################################################################
# Hintergrundfarbe des Terminals erfragen (OSC 11) -> light | dark | leer,
# wenn das Terminal nicht antwortet. Damit bleiben die Tabellenstreifen auf
# hellem wie dunklem Hintergrund gleich dezent.
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
    C_H=$'\e[1;37;44m'      # Schritt: weiss auf blau, ueber die ganze Breite
    C_TH=$'\e[1;4m'         # Tabellenkopf: fett, unterstrichen
    C_Q=$'\e[1;36m'         # Fragen
    C_EL=$'\e[K'            # Zeile in der aktuellen Hintergrundfarbe fuellen
    # Streifen brauchen 256 Farben; ohne Antwort des Terminals gilt dunkel
    # (Unraid-Web-Terminal, die meisten SSH-Terminals)
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
# say2 <bildschirm> <protokoll> - wo beide verschieden aussehen sollen
say2() { printf '%s\n' "$1"; printf '%s\n' "$2" >>"$LOG_FILE"; }
ok()   { say "  ${C_G}OK${C_0}     $*"; msg ok "$*"; }
hint() { say "  ${C_D}..${C_0}     $*"; msg hint "$*"; }
wrn()  { WARNINGS=$((WARNINGS+1)); say "  ${C_Y}WARNUNG${C_0} $*"; msg warn "$*"; }
bad()  { ERRORS=$((ERRORS+1));     say "  ${C_R}FEHLER${C_0}  $*"; msg error "$*"; }
# Meldungen fuer --plan/--apply sammeln: "stufe<US>schritt<US>text" (ohne Farben)
declare -a MSGS=()
STEP_ID=""
msg() {
    [[ "$MODE" == "plan" || "$MODE" == "apply" ]] || return 0
    local t
    t="$(sed 's/\x1b\[[0-9;]*[mK]//g' <<<"$2")"
    MSGS+=( "$1"$'\x1f'"$STEP_ID"$'\x1f'"$t" )
}
# explain <<'TXT' ... TXT  - Hintergrund zu einem Schritt (UB_EXPLAIN=0 schaltet ab)
explain() {
    local l
    if [[ "$EXPLAIN" == "0" || "$MODE" == "check" ]]; then cat >/dev/null; return 0; fi
    while IFS= read -r l; do say "  ${C_D}|${C_0} $l"; done
    say ""
}
STEP=0; STEPS=10
# Schritt als farbiger Balken, Unterabschnitt als leicht hinterlegte Zeile;
# im Protokoll bleibt es bei "== ... ==" und "-- ... --"
band() { # band <titel> <protokollzeile>
    if [[ -n "$C_H" ]]; then say2 "${C_H}${C_EL} $1${C_0}"$'\n' "$2"; else say "$2"; fi
}
hdr() { STEP=$((STEP+1)); say ""; band "Schritt $STEP/$STEPS   $*" "== Schritt $STEP/$STEPS: $* =="; }
sub() {
    say ""
    if [[ -n "$C_S" ]]; then say2 "${C_S}${C_EL} $*${C_0}" "-- $* --"
    else say "${C_B}-- $* --${C_0}"; fi
}
# Tabellen: Kopf unterstrichen, jede zweite Zeile leicht hinterlegt (nur am
# Bildschirm). Farbwechsel in der Zeile setzen den Hintergrund wieder auf.
TROW=0
thead() { local h="$*" ind; ind="${h%%[! ]*}"; TROW=0; say "$ind${C_TH}${h#"$ind"}${C_0}"; }
trow() {
    local l="$*"
    TROW=$((TROW+1))
    if [[ -n "$C_Z" ]] && (( TROW % 2 == 0 )); then say "${C_Z}${C_EL}${l//"$C_0"/"$C_0$C_Z"}${C_0}"
    else say "$l"; fi
}

# Wird wirklich gefragt? (nicht bei --yes und nicht im Pruefmodus)
interactive() { [[ "$YES" != "1" && "$MODE" != "check" ]]; }

REPLY=""
ask() { # ask <frage> [vorgabe] -> REPLY
    local q="$1" d="${2-}"
    if [[ "$YES" == "1" || "$MODE" == "check" ]]; then REPLY="$d"; return 0; fi
    printf '%s%s%s%s: ' "$C_Q" "$q" "$C_0" "${d:+ [$d]}" >/dev/tty
    IFS= read -r REPLY </dev/tty || REPLY=""
    [[ -z "$REPLY" ]] && REPLY="$d"
    printf '%s > %s\n' "$q" "$REPLY" >>"$LOG_FILE"
    return 0
}
ask_yn() { # ask_yn <frage> <j|n>  -> 0 = ja
    ask "$1 (j/n)" "$2"
    [[ "${REPLY,,}" == j* || "${REPLY,,}" == y* ]]
}

##############################################################################
# Vorschlaege (P) - gleiche Schluessel wie in settings.ini
##############################################################################
declare -A P=()
declare -A WHY=()          # Begruendung je Share
declare -A WHY_CODE=() WHY_ARG=()   # dieselbe als Code + Wert (fuer Oberflaechen)
why() { WHY[$1]="$4"; WHY_CODE[$1]="$2"; WHY_ARG[$1]="$3"; }   # why <share> <code> <wert> <text>

declare -A OLD=()          # settings.ini, wie sie beim Start war
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
# Vorgabe: bestehende settings.ini, sonst Heuristik
pinit() { local k="$1" d="$2"; if [[ -n "${OLD[$k]+x}" ]]; then P[$k]="${OLD[$k]}"; else P[$k]="$d"; fi; }

##############################################################################
# Hilfen
##############################################################################
# Physischer Script-Pfad -> /mnt/user/<share>/... (bleibt gueltig, wenn der Share umzieht)
ub_user_path() {
    local p="$UB_DIR" b
    for b in "${INV_BASES[@]}"; do
        if [[ "$p" == "${INV_BASE_PATH[$b]}/"* ]]; then printf '%s' "$UB_MNT/user/${p#"${INV_BASE_PATH[$b]}"/}"; return; fi
    done
    printf '%s' "$p"
}

# host-Pfad -> "share|relativ" (relativ ohne fuehrenden /), "*|" fuer ganze Wurzeln
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

# Wird <relativ> im Share von einer einfachen, verankerten Ignore-Regel erfasst?
ignored_rel() { # ignored_rel <share> <relativ>
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

gb_fmt() { local g="$1"; [[ -z "$g" ]] && { printf '?'; return; }; [[ "$g" == "-1" ]] && { printf '>Zeit'; return; }
           if (( g >= 1024 )); then printf '%d.%d TB' $((g/1024)) $(( (g%1024)*10/1024 )); else printf '%d GB' "$g"; fi; }

# Nummernliste "1 3 5-7" -> Zahlen
expand_nums() { local t a b; local -a toks; read -r -a toks <<<"$*"; for t in "${toks[@]}"; do if [[ "$t" =~ ^([0-9]+)-([0-9]+)$ ]]; then a=${BASH_REMATCH[1]}; b=${BASH_REMATCH[2]}; seq "$a" "$b"; elif is_uint "$t"; then echo "$t"; fi; done; }

##############################################################################
# Schritt 1: Umgebung
##############################################################################
step_environment() {
    hdr "Umgebung"
    explain <<'TXT'
Was hier passiert: setup.sh prueft, ob alles da ist, was der naechtliche Lauf braucht.
- zfs / btrfs: damit entstehen die Snapshots. Ein Snapshot friert den Zustand eines
  Datasets bzw. einer Disk in Sekunden ein; Kopia liest danach in Ruhe aus diesem
  eingefrorenen Stand, waehrend die Dienste laengst wieder laufen.
- overlayfs: verbindet die Snapshot-Teile eines Shares, der auf mehreren Basen liegt
  (z.B. Cache-Pool + Array), zu EINER Ansicht - so wie /mnt/user das live tut.
- jq: liest die JSON-Ausgaben von docker und kopia.
- notify: die Unraid-Mitteilungen (Glocke, Mail, Push - je nach deinen Unraid-Einstellungen).
- User Scripts: startet backup.sh nach Zeitplan. Auf dem Flash liegt dafuer nur ein
  3-Zeilen-Aufruf; Script, Einstellungen, Dumps und Protokolle bleiben im Office-Ordner.
TXT
    say "  Script-Ordner: $UB_DIR"
    say "  Datenordner:   $UB_DATA"
    if [[ "$UB_DIR" == "$UB_BOOT"* ]]; then
        wrn "Der Script-Ordner liegt auf dem Flash ($UB_BOOT) - besser unter $UB_MNT/user/appdata"
    fi
    local t miss=0
    for t in docker jq flock timeout gzip numfmt mountpoint awk du; do
        command -v "$t" >/dev/null 2>&1 || { bad "Werkzeug '$t' fehlt"; miss=1; }
    done
    (( miss )) && { say "Ohne diese Werkzeuge geht es nicht weiter."; exit 1; }
    [[ -f /etc/unraid-version ]] && ok "Unraid $(sed -n 's/version="\(.*\)"/\1/p' /etc/unraid-version)" \
                                 || wrn "/etc/unraid-version fehlt - ist das ein Unraid-Server?"
    docker info >/dev/null 2>&1 && ok "Docker $(docker version -f '{{.Server.Version}}' 2>/dev/null)" \
                                || { bad "Docker antwortet nicht"; exit 1; }
    mountpoint -q "$UB_MNT/user" && ok "$UB_MNT/user ist gemountet" \
                                 || { bad "$UB_MNT/user fehlt - Array/Pools zuerst starten"; exit 1; }
    command -v zfs   >/dev/null 2>&1 && ok "zfs vorhanden"   || hint "zfs nicht vorhanden"
    command -v btrfs >/dev/null 2>&1 && ok "btrfs vorhanden" || hint "btrfs nicht vorhanden"
    grep -qw overlay /proc/filesystems 2>/dev/null || modprobe overlay 2>/dev/null
    grep -qw overlay /proc/filesystems 2>/dev/null && ok "overlayfs vorhanden (Shares ueber mehrere Basen)" \
        || wrn "overlayfs fehlt - Shares ueber mehrere Basen erscheinen je Basis als Unterordner"
    [[ -x "$UB_NOTIFY_BIN" ]] && ok "Unraid-Mitteilungen verfuegbar" || wrn "Unraid-notify fehlt - keine Mitteilungen"
    [[ -d "$UB_BOOT/config/plugins/user.scripts" ]] && ok "User Scripts installiert" \
        || wrn "Plugin 'User Scripts' nicht gefunden - fuer den naechtlichen Lauf noetig"
}

##############################################################################
# Schritt 2: Grundlage (bestehende settings.ini)
##############################################################################
HAVE_SETTINGS="no"
step_basis() {
    hdr "Bestehende Einstellungen"
    explain <<'TXT'
Gibt es schon eine settings.ini, sind ihre Werte die Vorgaben - neu vorgeschlagen wird
nur, was sich seither geaendert hat. Sonst kommen die Vorschlaege aus dem System selbst.
Du bestaetigst oder aenderst; geschrieben wird erst am Ende.
TXT
    if load_settings; then
        HAVE_SETTINGS="yes"
        ok "settings.ini gelesen - bisherige Entscheidungen sind die Vorgaben"
        local e; for e in "${CFG_ERRORS[@]}"; do wrn "settings.ini: $e"; done
    else
        CFG=(); CFG_SECTIONS=(); CFG_ERRORS=()
        apply_settings
        hint "Noch keine settings.ini - Vorschlaege kommen aus dem System"
    fi
    old_keep
    [[ "$MODE" == "apply" ]] && decisions_load

    # Allgemeine Vorgaben
    pinit "general|server"         "$(hostname -s 2>/dev/null)"
    pinit "general|mount_root"     "$UB_MNT/backup-snapshots"
    pinit "general|view_root"      "$UB_MNT/btrfs-snap"
    pinit "general|btrfs_snap_dir" ".btrfs-snap"
    pinit "general|keep_runs"      "14"
    pinit "general|keep_logs"      "60"
    pinit "general|min_free_gb"    "8"
    pinit "general|keep_mounts"    "no"
    pinit "general|notify_success" "yes"
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

    # Snapshot-Praefix: eigener Praefix "unraidbackup-". Snapshots anderer
    # Werkzeuge (andere Praefixe) fasst backup.sh nie an - auch nicht beim Aufraeumen.
    pinit "general|snap_prefix" "unraidbackup-"
    if command -v zfs >/dev/null 2>&1 && [[ -z "${OLD[general|snap_prefix]+x}" ]]; then
        local others
        others="$(zfs list -H -t snapshot -o name 2>/dev/null | sed -n 's/.*@\([a-zA-Z0-9_]*[-_]\).*/\1/p' \
                  | grep -vx "$(pget "general|snap_prefix")" | sort -u | head -5 | paste -sd' ' -)"
        [[ -n "$others" ]] && hint "Vorhandene ZFS-Snapshots anderer Werkzeuge (Praefix $others) - bleiben unangetastet"
    fi
    pinit "kopia|container" ""
    pinit "kopia|identity"  ""
    pinit "flash|mode"      ""
    [[ -z "$(pget "flash|mode")" ]] && unset "P[flash|mode]"

    # Ab hier gelten die Vorschlaege fuer Inventar und Plan
    _apply_P
    inv_scan
    docker_load
    if [[ -z "$(pget "kopia|container")" ]] || ! in_list "$(pget "kopia|container")" "${CT_NAMES[@]}"; then
        pset "kopia|container" "$(kopia_find_container)"
        _apply_P
    fi
}

# P -> CFG -> Variablen (damit Inventar/Plan/Kopia mit den Vorschlaegen rechnen)
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
# Schritt 3: Offsite-Backup mit Kopia - ja oder nein?
##############################################################################
step_offsite() {
    hdr "Offsite-Backup mit Kopia?"
    explain <<'TXT'
Ohne Kopia bleibt alles auf diesem Server: lokale ZFS-/btrfs-Snapshots und Datenbank-
Dumps. Das hilft gegen versehentliches Loeschen und kaputte Updates - nicht gegen Brand,
Diebstahl, Ueberspannung oder einen Totalausfall des Servers.

Was Kopia hier tun wuerde:
- Jede Nacht, direkt nach den Snapshots, liest Kopia die eingefrorenen Staende unter
  /mnt/backup-snapshots/<share> und laedt nur Neues hoch. Dateien werden in Bloecke
  zerlegt und dedupliziert (auch ueber Shares hinweg), auf Wunsch komprimiert.
- Alles wird VOR dem Hochladen hier auf dem Server verschluesselt (AES-256-GCM oder
  ChaCha20-Poly1305, Schluessel aus dem Repository-Passwort). Der Speicheranbieter sieht
  nur unlesbare Bloecke - weder Inhalte noch Dateinamen.
- Ziel ist ein S3-kompatibler Speicher (z.B. Backblaze B2, Wasabi, MEGA S4, Hetzner,
  eigener MinIO/Garage); Kopia kann auch SFTP, WebDAV, B2, Azure, GCS oder lokal.
- Kopia behaelt Versionen nach der Aufbewahrung (z.B. 7 taeglich, 4 woechentlich,
  12 monatlich, 3 jaehrlich) und raeumt den Rest selbst weg.

Die Verbindung zum Repository richtest du EINMAL selbst in der KopiaUI ein (Repository >
S3: Endpoint, Bucket, Access Key, Secret Key, Repository-Passwort). setup.sh fragt diese
Daten aus Sicherheitsgruenden bewusst nicht ab: Zugangsschluessel und Passwort sollen nie
in Scripts, Protokollen oder settings.ini landen - Kopia verwahrt sie in seiner eigenen
Config im Container.
WICHTIG: Ohne Repository-Passwort ist das Backup nicht wiederherstellbar, auch nicht vom
Anbieter. Getrennt aufbewahren (Passwortmanager und auf Papier).

Was setup.sh danach prueft und einrichtet: Kopia-Container (z.B. imagegenius/kopia aus den
Community Apps, PUID=0/PGID=0), das Mapping /mnt/backup-snapshots (Read Only - Slave),
die Verbindung, die Policies je Share und alte Quellen.
Ohne Kopia jetzt: jederzeit spaeter mit einem neuen setup.sh-Lauf einschaltbar.
TXT
    local n has_ct="" dflt
    for n in "${CT_NAMES[@]}"; do is_kopia_image "${CT_IMAGE[$n]}" && has_ct="$n"; done
    if [[ -n "${OLD[kopia|enabled]+x}" ]]; then dflt="$([[ $(old "kopia|enabled") == yes ]] && echo j || echo n)"
    elif [[ -n "$has_ct" ]]; then dflt="j"
    else dflt="n"; fi
    [[ -n "$has_ct" ]] && hint "Kopia-Container gefunden: $has_ct" || hint "Kein Kopia-Container gefunden (Image mit 'kopia' im Namen)"
    if ask_yn "Offsite-Backup mit Kopia einrichten?" "$dflt"; then
        pset "kopia|enabled" yes
        if [[ ! -d "$MOUNT_ROOT" ]]; then
            if mkdir -p "$MOUNT_ROOT"; then ok "$MOUNT_ROOT angelegt (Ziel fuer das Kopia-Mapping)"
            else bad "$MOUNT_ROOT liess sich nicht anlegen"; fi
        else
            ok "$MOUNT_ROOT ist vorhanden"
        fi
        hint "Unraid haelt /mnt im RAM: nach einem Neustart legt Docker den Ordner beim Start des Kopia-Containers wieder an."
    else
        pset "kopia|enabled" no
        ok "Ohne Kopia: lokale Snapshots und Dumps. Shares koennen 'snapshot' oder 'off' sein."
    fi
    _apply_P
}

##############################################################################
# Schritt 4: Shares
##############################################################################
declare -a SH=()     # angezeigte Reihenfolge
declare -A SH_GB=()

# Vorschlag fuer einen Share, der noch nicht in settings.ini steht -> PROP_MODE, WHY[s]
# (kein $(...): die Begruendung in WHY muss in dieser Shell ankommen)
PROP_MODE=""
share_propose() {
    local s="$1" gb n img b src r
    if ! share_name_ok "$s"; then why "$s" name_bad "" "Name mit @ : \" oder | wird nicht unterstuetzt"; PROP_MODE=off; return; fi
    case "$s" in
        system)  why "$s" system "" "Docker-Image/libvirt - wird neu erzeugt"; PROP_MODE=off; return ;;
        domains) why "$s" domains "" "VM-vDisks - laufende VMs sind nicht konsistent, VM-Backup separat"; PROP_MODE=off; return ;;
    esac
    # Time-Machine-Ziel: enthaelt schon Sicherungen anderer Rechner und aendert
    # sich staendig in grossen Bloecken
    local tm
    if tm="$(timemachine_reason "$s")"; then
        why "$s" timemachine "$tm" "Time Machine ($tm) - Sicherungen anderer Rechner"; PROP_MODE=off; return
    fi
    if matches_any "$s" "${DRIFT_IGNORE[@]}"; then why "$s" drift_ignore "" "passt auf drift.ignore"; PROP_MODE=off; return; fi
    # Config, Cache, Logs oder lokales Repository des Kopia-Containers nie sichern
    # (Daten-Mappings fuer eigene Kopia-Quellen zaehlen nicht dazu)
    if [[ -n "$KOPIA_CONTAINER" ]]; then
        local dst
        while IFS='|' read -r src dst _; do
            [[ -z "$src" ]] && continue
            kopia_workdir "$dst" || continue
            r="$(path_share "$src")" || continue
            [[ "${r%%|*}" == "$s" && -z "${r#*|}" ]] && { why "$s" kopia_workdir "" "Config/Cache/Repository von Kopia"; PROP_MODE=off; return; }
        done <<<"${CT_BINDS[$KOPIA_CONTAINER]:-}"
    fi
    gb="${SH_GB[$s]:-}"
    if [[ "$gb" == "-1" ]] || { [[ -n "$gb" ]] && (( gb > 500 )); }; then
        # Container-Daten (appdata & Co.) nie wegen der Groesse abschalten - dort
        # sind die grossen Ordner (Caches, Blockchains ...) auszunehmen, nicht der Share
        if is_container_share "$s"; then
            why "$s" big_container "$gb" "Container-Daten, gross ($(gb_fmt "$gb")) - grosse Ordner gleich danach ausnehmen"; PROP_MODE=kopia; return
        fi
        why "$s" big "$gb" "gross ($(gb_fmt "$gb")) - bewusst entscheiden"; PROP_MODE=off; return
    fi
    [[ "${INV_METHOD[$s]}" == "none" ]] && { why "$s" empty "" "noch leer"; PROP_MODE=kopia; return; }
    # Groesse unbekannt (kein ZFS, nicht gemessen): nichts ungefragt offsite schicken
    if [[ -z "$gb" ]] && ! is_container_share "$s"; then
        why "$s" size_unknown "" "Groesse unbekannt - messen oder bewusst entscheiden"; PROP_MODE=snapshot; return
    fi
    why "$s" new "" "neu"; PROP_MODE=kopia
}

share_table() {
    local i=0 s mode meth gb note
    say ""
    thead "$(printf '  %-3s %-24s %-20s %-8s %-9s %-9s %s' Nr Share Lage Methode Groesse Modus Hinweis)"
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

share_details() { # Detailbearbeitung eines Shares
    local s="$1" c x
    while :; do
        sub "Share '$s'"
        say "  Lage:        $(inv_locnames "$s")  ($(share_method "$s"), ${INV_LAYOUT[$s]:-})"
        say "  Modus:       $(pget "share|$s|mode")"
        say "  Methode:     $(pget "share|$s|method" auto)"
        say "  Aufbewahrung ZFS:   $(pget "share|$s|retention" "$(pget "zfs|retention")")  (taeglich woechentlich monatlich)"
        say "  Aufbewahrung Kopia: $(pget "share|$s|kopia_retention" "wie alle Shares")  (latest hourly daily weekly monthly annual)"
        say "  Kopia-Ignores:"; plist "share|$s|kopia_ignore" | sed 's/^/                 /' | while IFS= read -r x; do say "$x"; done
        if [[ -n "${INV_CHILDREN[$s]:-}" ]]; then
            say "  Kind-Datasets:"
            while IFS='|' read -r _ c _; do
                [[ -z "$c" ]] && continue
                if plist "share|$s|exclude_dataset" | grep -Fxq -- "$c"; then say "                 $c  (ausgeschlossen)"
                else say "                 $c"; fi
            done <<<"${INV_CHILDREN[$s]}"
        fi
        [[ -n "${INV_NOTE[$s]:-}" ]] && printf '%s' "${INV_NOTE[$s]}" | while IFS= read -r x; do say "  Hinweis:     $x"; done
        say "  [r] ZFS-Aufbewahrung  [k] Kopia-Aufbewahrung  [i] Ignore dazu  [x] Ignore weg"
        say "  [m] Methode auto/live  [e] Kind-Dataset aus/ein  [Enter] zurueck"
        ask "  Auswahl" ""
        case "$REPLY" in
            r) ask "  Aufbewahrung (t w m)" "$(pget "share|$s|retention" "$(pget "zfs|retention")")"
               [[ "$REPLY" =~ ^[0-9]+\ [0-9]+\ [0-9]+$ ]] && pset "share|$s|retention" "$REPLY" || say "  ungueltig" ;;
            k) ask "  Kopia: latest hourly daily weekly monthly annual (leer = wie alle Shares)" "$(pget "share|$s|kopia_retention")"
               if [[ -z "$REPLY" ]]; then unset "P[share|$s|kopia_retention]"
               elif [[ "$REPLY" =~ ^([0-9]+|inherit)(\ ([0-9]+|inherit)){5}$ ]]; then pset "share|$s|kopia_retention" "$REPLY"
               else say "  ungueltig - sechs Zahlen oder inherit"; fi ;;
            i) ask "  Regel (z.B. /cache/ - relativ zum Share)" ""
               [[ -n "$REPLY" ]] && plist_add "share|$s|kopia_ignore" "$REPLY" ;;
            x) ask "  Welche Regel entfernen" ""
               [[ -n "$REPLY" ]] && plist_del "share|$s|kopia_ignore" "$REPLY" ;;
            m) if [[ "$(pget "share|$s|method" auto)" == "auto" ]]; then pset "share|$s|method" live; else pset "share|$s|method" auto; fi ;;
            e) ask "  Dataset-Name" ""
               if [[ -n "$REPLY" ]]; then
                   if plist "share|$s|exclude_dataset" | grep -Fxq -- "$REPLY"; then plist_del "share|$s|exclude_dataset" "$REPLY"
                   else plist_add "share|$s|exclude_dataset" "$REPLY"; fi
               fi ;;
            "") return 0 ;;
        esac
        _apply_P
    done
}

# Ist der Share ein Time-Machine-Ziel? Gibt den Grund aus (Rueckgabe 0 = ja).
#   - SMB-Export "Yes/Time Machine" in der Unraid-Share-Config
#   - ein Time-Machine-Container (z.B. mbentley/timemachine) bindet ihn ein
#   - der Name sagt es (timemachine, time_machine, time-machine)
timemachine_reason() {
    local s="$1" n src r
    if grep -qE '^shareExport="et' "$UB_SHARES_CFG/$s.cfg" 2>/dev/null; then echo "SMB-Export"; return 0; fi
    for n in "${CT_NAMES[@]}"; do
        [[ "${CT_IMAGE[$n],,}" == *timemachine* || "${CT_IMAGE[$n],,}" == *time-machine* ]] || continue
        while IFS='|' read -r src _ _; do
            [[ -z "$src" ]] && continue
            r="$(path_share "$src")" || continue
            [[ "${r%%|*}" == "$s" ]] && { echo "Container $n"; return 0; }
        done <<<"${CT_BINDS[$n]:-}"
    done
    case "${s,,}" in *timemachine*|*time_machine*|*time-machine*|*"time machine"*) echo "Name"; return 0 ;; esac
    return 1
}

# Haelt der Share die Daten mehrerer Container (appdata & Co.)?
is_container_share() {
    [[ "$1" == "appdata" ]] && return 0
    (( $(appdata_suggestions "$1" | cut -d'|' -f2 | sort -u | wc -l) >= 3 ))
}

# Ziel-Pfad im Kopia-Container, der Kopias eigene Arbeitsdaten traegt?
kopia_workdir() { [[ "$1" =~ ^/(config|cache|logs|tmp|backups|repo|repository|app/config|app/cache|app/logs)(/|$) ]]; }

# Ordner eines "appdata-artigen" Shares, die zu Containern gehoeren
appdata_suggestions() { # appdata_suggestions <share>  -> Zeilen "ordner|container"
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
Ein Share ist die Einheit, ueber die du entscheidest. Pro Share:
  kopia     lokaler Snapshot + Kopia (offsite). Kopia-Quelle: <mount_root>/<share>
  snapshot  nur lokaler Snapshot (schnelles Zurueckholen, kein Offsite)
  off       gar nichts
Methode (aus dem Dateisystem abgeleitet):
  single    Share liegt auf einer Basis -> Snapshot direkt eingehaengt
  overlay   Share liegt auf mehreren Basen (Pool + Array, mehrere Disks) -> die Snapshots
            werden per overlayfs zu einer Ansicht vereint; der Pool hat Vorrang wie in /mnt/user
  split     wie overlay, aber mit Kind-Datasets -> overlayfs zeigt keine Unter-Mounts,
            darum erscheint jede Basis als Unterordner
  live      Basis kann keine Snapshots (z.B. XFS) -> /mnt/user/<share> wird read-only
            eingebunden; Kopia liest den laufenden Stand (Datenbanken dort sind nur per Dump sicher)
Kennung: ZFS-Shares merken sich die Dataset-GUID, andere die Inode-Nummer des Ordners.
Wird ein Share umbenannt, erkennt backup.sh ihn daran wieder und meldet die Umbenennung.
Groesse ueber 500 GB -> Vorschlag "off", damit nichts Grosses ungefragt offsite geht.
TXT
    local s mode gb
    SH=( "${INV_SHARES[@]}" )
    while IFS= read -r s; do [[ -n "$s" ]] && ! inv_has_share "$s" && SH+=( "$s" ); done < <(old_names share)

    # Groessen: ZFS sofort, sonst du (mit Zeitlimit)
    local measure="no"
    for s in "${INV_SHARES[@]}"; do [[ -z "${INV_GB[$s]}" && "${INV_METHOD[$s]}" != "none" ]] && measure="ask"; done
    if [[ "$measure" == "ask" && "$SIZE_TIMEOUT" != "0" ]]; then
        if ask_yn "Groesse der Shares ohne eigenes ZFS-Dataset messen? (du, max. ${SIZE_TIMEOUT}s je Share, weckt Array-Disks)" j; then
            measure="yes"
        fi
    fi
    for s in "${INV_SHARES[@]}"; do
        if [[ -n "${INV_GB[$s]}" ]]; then SH_GB[$s]="${INV_GB[$s]}"
        elif [[ "$measure" == "yes" && "${INV_METHOD[$s]}" != "none" ]]; then
            interactive && printf '  messe %-40s\r' "$s" >/dev/tty
            SH_GB[$s]="$(inv_measure "$s" "$SIZE_TIMEOUT")"
        fi
    done
    interactive && printf '%-60s\r' "" >/dev/tty

    # Umbenennungen erkennen: verschwundener Share mit gleicher Kennung
    local -A gone_by_id=()
    local id o k
    while IFS= read -r s; do
        [[ -z "$s" ]] && continue
        inv_has_share "$s" && [[ "${INV_METHOD[$s]}" != "none" ]] && continue
        id="$(old "share|$s|id")"; [[ -n "$id" && "$id" != *":?" ]] && gone_by_id[$id]="$s"
    done < <(old_names share)

    # Vorschlaege
    for s in "${SH[@]}"; do
        o=""
        # Ein Share ohne Daten (nur Unraid-Config) hat keine Kennung -> kein Abgleich
        if inv_has_share "$s" && ! old_has "share|$s" && [[ -n "${INV_ID[$s]:-}" ]]; then
            o="${gone_by_id[${INV_ID[$s]}]:-}"
        fi
        if [[ -n "$o" ]]; then
            for k in mode retention kopia_retention method kopia_ignore exclude_dataset; do
                [[ -n "${OLD[share|$o|$k]+x}" ]] && P[share|$s|$k]="${OLD[share|$o|$k]}"
            done
            why "$s" renamed_from "$o" "umbenannt aus '$o' - Einstellungen uebernommen"
            why "$o" renamed_to "$s" "existiert nicht mehr (umbenannt in '$s')"
        elif old_has "share|$s"; then
            [[ -z "${WHY[$s]:-}" ]] && why "$s" previous "" "bisher"
            pinit "share|$s|mode" off
            # Kopia wurde gerade eingeschaltet: was bisher nur lokal lief, kommt mit
            if is_yes "$KOPIA_ENABLED" && [[ "$(old "kopia|enabled" yes)" == "no" && "$(pget "share|$s|mode")" == "snapshot" ]]; then
                pset "share|$s|mode" kopia; why "$s" kopia_on "" "Kopia neu eingeschaltet"
            fi
        else
            share_propose "$s"
            pset "share|$s|mode" "$PROP_MODE"
        fi
        # Ohne Kopia gibt es nur lokal oder gar nicht
        if ! is_yes "$KOPIA_ENABLED" && [[ "$(pget "share|$s|mode")" == "kopia" ]]; then
            pset "share|$s|mode" snapshot; why "$s" kopia_off_local "" "${WHY[$s]:+${WHY[$s]}; }Kopia aus -> lokal"
        fi
        if ! inv_has_share "$s"; then
            [[ "${WHY[$s]:-}" == "existiert nicht mehr"* ]] || why "$s" gone "" "existiert nicht mehr - Eintrag wird entfernt"
        fi
        [[ -n "${P[share|$s|retention]+x}" ]] || pinit "share|$s|retention" ""
        [[ -z "$(pget "share|$s|retention")" ]] && unset "P[share|$s|retention]"
        [[ -n "${P[share|$s|kopia_retention]+x}" ]] || pinit "share|$s|kopia_retention" ""
        [[ -z "$(pget "share|$s|kopia_retention")" ]] && unset "P[share|$s|kopia_retention]"
        [[ -n "${P[share|$s|method]+x}" ]]          || pinit "share|$s|method"          "auto"
        [[ -n "${P[share|$s|kopia_ignore]+x}" ]]    || pinit "share|$s|kopia_ignore"    ""
        [[ -n "${P[share|$s|exclude_dataset]+x}" ]] || pinit "share|$s|exclude_dataset" ""
    done
    _apply_P

    # Kopia-eigene Ordner in appdata-artigen Shares immer ignorieren
    local dir n line
    for s in "${SH[@]}"; do
        [[ "$(pget "share|$s|mode")" == "kopia" ]] || continue
        while IFS='|' read -r dir n; do
            [[ -z "$dir" ]] && continue
            if [[ "$n" == "$KOPIA_CONTAINER" ]] && ! plist "share|$s|kopia_ignore" | grep -Fxq "/$dir/"; then
                plist_add "share|$s|kopia_ignore" "/$dir/"
                hint "Share '$s': /$dir/ gehoert dem Kopia-Container - wird ignoriert"
            fi
        done < <(appdata_suggestions "$s")
    done
    # Bearbeiten
    while :; do
        share_table
        interactive || break
        say ""
        if is_yes "$KOPIA_ENABLED"; then
            say "  ${C_D}k <nr..> = kopia   s <nr..> = nur lokaler Snapshot   o <nr..> = aus   d <nr> = Details/Ignores${C_0}"
        else
            say "  ${C_D}s <nr..> = lokaler Snapshot   o <nr..> = aus   d <nr> = Details   (Kopia ist aus)${C_0}"
        fi
        say "  ${C_D}Nummern auch als Bereich, z.B. 'o 4 7-9'. Enter = so uebernehmen${C_0}"
        ask "Shares" ""
        [[ -z "$REPLY" ]] && break
        local cmd rest i
        cmd="${REPLY%% *}"; rest="${REPLY#"$cmd"}"
        for i in $(expand_nums "$rest"); do
            s="${SH[$((i-1))]:-}"; [[ -z "$s" ]] && continue
            case "$cmd" in
                k) if is_yes "$KOPIA_ENABLED"; then pset "share|$s|mode" kopia; why "$s" user "" "von dir gesetzt"
                   else say "  Kopia ist aus - '$s' bleibt lokal (Kopia einschalten: setup.sh erneut, Schritt 3)"; fi ;;
                s) pset "share|$s|mode" snapshot; why "$s" user "" "von dir gesetzt" ;;
                o) pset "share|$s|mode" off;      why "$s" user "" "von dir gesetzt" ;;
                d) share_details "$s" ;;
            esac
        done
        _apply_P
    done

    # Grosse Ordner in appdata-artigen Shares anbieten
    if interactive; then
        for s in "${SH[@]}"; do
            [[ "$(pget "share|$s|mode")" == "kopia" ]] || continue
            local -a cand=()
            while IFS='|' read -r dir n; do [[ -n "$dir" ]] && cand+=( "$dir|$n" ); done < <(appdata_suggestions "$s")
            [[ ${#cand[@]} -lt 3 ]] && continue
            sub "Share '$s' enthaelt Container-Daten"
            if ask_yn "  Ordnergroessen anzeigen, um grosse Ordner (Caches, Blockchains, Mediadaten) auszunehmen?" j; then
                local -A seen=()
                local k=0 b p bytes x slow sz
                local -a rows=()
                # Ordner, deren Messung laenger als 60 s dauert, sind sehr gross:
                # sie stehen oben und zeigen "> 60 s" statt einer Zahl
                local SLOW=9000000000000000000
                for line in "${cand[@]}"; do
                    dir="${line%%|*}"; [[ -n "${seen[$dir]:-}" ]] && continue; seen[$dir]=1
                    bytes=0; slow=""
                    while IFS='|' read -r b _; do
                        [[ -z "$b" ]] && continue; p="${INV_BASE_PATH[$b]}/$s/$dir"
                        [[ -d "$p" ]] || continue
                        interactive && printf '  messe /%-40s\r' "${dir:0:40}" >/dev/tty
                        x="$(timeout 60 du -sb "$p" 2>/dev/null)"
                        if (( $? == 124 )); then slow=1
                        else bytes=$(( bytes + $(awk '{s+=$1} END{print s+0}' <<<"$x") )); fi
                    done <<<"${INV_LOCS[$s]}"
                    [[ -n "$slow" ]] && bytes=$SLOW
                    rows+=( "$bytes|$dir|$(printf '%s\n' "${cand[@]}" | awk -F'|' -v d="$dir" '$1==d{print $2}' | paste -sd, -)" )
                done
                interactive && printf '%-60s\r' "" >/dev/tty
                mapfile -t rows < <(printf '%s\n' "${rows[@]}" | sort -t'|' -k1,1nr | head -15)
                thead "$(printf '  %-3s %10s  %-31s %s' Nr Groesse Ordner Container)"
                for line in "${rows[@]}"; do
                    k=$((k+1)); IFS='|' read -r bytes dir n <<<"$line"
                    ignored_rel "$s" "$dir" && n="$n  (schon ignoriert)"
                    if (( bytes == SLOW )); then sz="> 60 s"; else sz="$(human "$bytes")"; fi
                    trow "$(printf '  %-3s %10s  /%-30s %s' "$k" "$sz" "$dir/" "$n")"
                done
                ask "  Nummern, die Kopia ignorieren soll (leer = keine)" ""
                for k in $(expand_nums "$REPLY"); do
                    line="${rows[$((k-1))]:-}"; [[ -z "$line" ]] && continue
                    IFS='|' read -r _ dir _ <<<"$line"
                    plist_add "share|$s|kopia_ignore" "/$dir/"
                done
            fi
        done
    fi
    _apply_P
}

declare -A NC_GROUP=()     # Nextcloud-Container -> die anderen Container derselben Instanz

##############################################################################
# Schritt 4: Container
##############################################################################
declare -A CT_STOP=() CT_WHY=()
declare -A CT_CODE=() CT_ARG=() CT_PREV=() CT_RISK=()   # Begruendung als Code (fuer Oberflaechen)
ctwhy() { CT_WHY[$1]="$4"; CT_CODE[$1]="$2"; CT_ARG[$1]="$3"; }   # ctwhy <container> <code> <wert> <text>

container_needs_stop() { # setzt CT_WHY; 0 = anhalten
    local n="$1" src r s rel touched="" ign=""
    if [[ -n "${CT_VOLUMES[$n]}" ]]; then ctwhy "$n" volumes "" "hat Docker-Volumes"; return 0; fi
    while IFS='|' read -r src _ _; do
        [[ -z "$src" ]] && continue
        [[ "$src" == "$UB_MNT"* ]] || continue
        r="$(path_share "$src")" || continue
        s="${r%%|*}"; rel="${r#*|}"
        if [[ "$s" == "*" ]]; then ctwhy "$n" binds_root "$src" "bindet $src ganz ein"; return 0; fi
        [[ "$(pget "share|$s|mode" off)" == "off" ]] && continue
        if ignored_rel "$s" "$rel"; then ign+="$s/$rel "; continue; fi
        touched+="$s${rel:+/$rel} "
    done <<<"${CT_BINDS[$n]:-}"
    if [[ -n "$touched" ]]; then ctwhy "$n" writes "${touched% }" "schreibt in ${touched% }"; return 0; fi
    if [[ -n "$ign" ]]; then ctwhy "$n" ignored "${ign% }" "Daten nur in ignorierten Pfaden (${ign% })"; return 1; fi
    ctwhy "$n" no_data "" "keine gesicherten Daten"; return 1
}

step_containers() {
    hdr "Container"
    explain <<'TXT'
Warum anhalten? Ein laufender Dienst hat Dateien halb geschrieben, SQLite-Journale offen,
Caches nicht geleert. Ein Snapshot davon ist "absturzkonsistent" - wie Strom weg. Angehalten
ist er sauber. Gestoppt wird nur fuer die Sekunden der Snapshots, nicht waehrend Kopia laeuft.
Vorschlag "weiterlaufen", wenn ein Container nichts schreibt, was gesichert wird (seine Pfade
liegen in off-Shares oder in Kopia-ignorierten Ordnern) - Anhalten braechte dort nichts.
Reihenfolge: erst Apps, dann Datenbanken, zuletzt Netzwerk-Container (z.B. ein VPN, dessen
Netz andere mitbenutzen); gestartet wird umgekehrt, Datenbanken erst, wenn sie "healthy" sind.
Dumps: zusaetzlich zum Snapshot ein logischer SQL-Dump - lesbar, versionsunabhaengig
einspielbar, und vor dem Anhalten sofort geprueft (Abschlusszeile, Tabellenzahl).
Nextcloud: Wartungsmodus vor dem Dump, damit Datenbank und Dateien zusammenpassen.
Docker-Volumes liegen im Docker-Verzeichnis (system) - das sichert niemand.
TXT
    local n i t
    local -a run=()
    for n in "${CT_NAMES[@]}"; do [[ "${CT_RUNNING[$n]}" == "true" ]] && run+=( "$n" ); done
    say "  ${#CT_NAMES[@]} Container, ${#run[@]} laufen."

    # --- Anhalten
    local had_cfg="no"; old_has "docker" && [[ "$HAVE_SETTINGS" == "yes" ]] && had_cfg="yes"
    # --apply: die Entscheidungen nennen alle Container (known) - auch beim ersten Setup
    [[ "$MODE" == "apply" && -n "${OLD[docker|known]+x}" ]] && had_cfg="yes"
    local -a nostop=() known=()
    mapfile -t nostop < <(old_list "docker|no_stop")
    mapfile -t known  < <(old_list "docker|known")
    P[docker|no_stop]=""
    for n in "${CT_NAMES[@]}"; do
        [[ "$n" == "$KOPIA_CONTAINER" ]] && { CT_STOP[$n]="no"; ctwhy "$n" kopia "" "Kopia - laeuft immer weiter"; continue; }
        if [[ "$had_cfg" == "yes" ]] && in_list "$n" "${known[@]}"; then
            if in_list "$n" "${nostop[@]}"; then CT_STOP[$n]="no"; else CT_STOP[$n]="yes"; fi
            CT_PREV[$n]=1
            if container_needs_stop "$n" && [[ "${CT_STOP[$n]}" == "no" ]]; then
                CT_RISK[$n]=1
                CT_WHY[$n]="bisher; ${C_Y}ACHTUNG${C_0} ${CT_WHY[$n]} - Snapshot davon nur absturzkonsistent"
            else
                CT_WHY[$n]="bisher; ${CT_WHY[$n]}"
            fi
            continue
        fi
        if container_needs_stop "$n"; then CT_STOP[$n]="yes"; else CT_STOP[$n]="no"; fi
        # Medienserver laufen weiter: Anhalten braeche laufende Streams ab. Ihre
        # SQLite-Datenbank ist im Snapshot dann nur absturzkonsistent (meist genuegt das).
        if [[ "${CT_STOP[$n]}" == "yes" ]] && is_media_server "${CT_IMAGE[$n]}"; then
            CT_STOP[$n]="no"; CT_RISK[$n]=1
            ctwhy "$n" media_server "${CT_ARG[$n]:-}" "Medienserver - laeuft weiter (Streams); Datenbank im Snapshot nur absturzkonsistent"
        fi
    done

    while :; do
        say ""
        thead "$(printf '  %-3s %-26s %-6s %-12s %s' Nr Container Laeuft Backup Grund)"
        i=0
        for n in "${CT_NAMES[@]}"; do
            i=$((i+1))
            trow "$(printf '  %-3s %-26s %-6s ' "$i" "${n:0:26}" "$([[ ${CT_RUNNING[$n]} == true ]] && echo ja || echo nein)")$([[ ${CT_STOP[$n]} == yes ]] && printf '%s' "${C_Y}anhalten${C_0}    " || printf '%s' "${C_G}weiterlaufen${C_0}") ${CT_WHY[$n]}"
        done
        interactive || break
        say "  ${C_D}a <nr..> = anhalten   w <nr..> = weiterlaufen lassen   Enter = uebernehmen${C_0}"
        ask "Container" ""
        [[ -z "$REPLY" ]] && break
        local cmd="${REPLY%% *}" rest="${REPLY#"${REPLY%% *}"}"
        for i in $(expand_nums "$rest"); do
            n="${CT_NAMES[$((i-1))]:-}"; [[ -z "$n" || "$n" == "$KOPIA_CONTAINER" ]] && continue
            case "$cmd" in a) CT_STOP[$n]="yes"; ctwhy "$n" user "" "von dir gesetzt" ;; w) CT_STOP[$n]="no"; ctwhy "$n" user "" "von dir gesetzt" ;; esac
        done
    done
    for n in "${CT_NAMES[@]}"; do
        [[ "$n" == "$KOPIA_CONTAINER" ]] && continue
        [[ "${CT_STOP[$n]}" == "no" ]] && plist_add "docker|no_stop" "$n"
    done
    P[docker|known]="$(printf '%s\n' "${CT_NAMES[@]}")"

    # --- Docker-Volumes
    local v vn vd vtxt
    for n in "${CT_NAMES[@]}"; do
        [[ -n "${CT_VOLUMES[$n]}" && "$n" != "$KOPIA_CONTAINER" ]] || continue
        vtxt=""
        while IFS='|' read -r vn vd; do
            [[ -z "$vn" ]] && continue
            if [[ "$vn" =~ ^[0-9a-f]{64}$ ]]; then vtxt+="anonym an $vd, "; else vtxt+="$vn an $vd, "; fi
        done <<<"${CT_VOLUMES[$n]}"
        wrn "'$n' nutzt Docker-Volumes (${vtxt%, }) - die liegen im Docker-Verzeichnis (system) und sind NICHT im Backup."
        hint "         Abhilfe: im Template einen Pfad in einen gesicherten Share mappen (z.B. /mnt/user/appdata/$n)."
    done

    _apply_P
}

##############################################################################
# Schritt 6: Datenbanken (Container und Compose-Stacks) und Nextcloud
##############################################################################
# Wo liegen die Daten eines Containers? -> "appdata/mariadb", "Volume:/data!", "im Container!"
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
    while IFS='|' read -r vn vd; do [[ -n "$vn" ]] && out+="Volume:$vd! "; done <<<"${CT_VOLUMES[$n]:-}"
    [[ -z "$out" ]] && out="im Container! "
    printf '%s' "${out% }"
}

declare -a DB_ROWS=() DB_MISSING=() NC_ROWS=()   # fuer --plan
step_databases() {
    hdr "Datenbanken und Nextcloud"
    explain <<'TXT'
Gesucht wird in allen Containern - einzeln angelegten wie solchen aus Compose-Stacks -
und zusaetzlich in den Stack-Dateien des Compose Managers (findet auch Datenbank-Dienste
von Stacks, die gerade nicht laufen). Erkannt wird eine Datenbank an
  - Umgebungsvariablen, die das Server-Image selbst setzt (PG_MAJOR, MARIADB_VERSION,
    MONGO_VERSION, REDIS_VERSION ...) - das trifft auch umbenannte oder abgeleitete Images,
  - am Image-Namen (mariadb, mysql, postgres, pgvecto-rs, mongo, redis ...),
  - am Standard-Port (3306, 5432, 27017, 6379).
MariaDB/MySQL, Postgres und MongoDB bekommen vor dem Snapshot einen logischen Dump:
lesbar, versionsunabhaengig einspielbar, sofort geprueft. Zusaetzlich liegen ihre Dateien
im Snapshot - sauber, weil der Container dafuer angehalten wird.
Redis & Co. sind Zwischenspeicher: kein Dump; was sie auf Platte haben, steckt im Snapshot.
Andere Datenbanken (InfluxDB, CouchDB, ...) sichert der Snapshot des angehaltenen Containers.
Liegen die Daten in einem Docker-Volume oder nur im Container selbst, sind sie bei einem
Image-Update bzw. im Backup weg - das wird markiert.
TXT
    local n t r why where proposal i=0 svc stack img cname
    local -a rows=() dump_cands=()
    local -A dtype=()
    local -a known=(); mapfile -t known < <(old_list "docker|known")

    # Container
    for n in "${CT_NAMES[@]}"; do
        r="$(ct_db "$n")"
        old_has "dump|$n" && r="$(old "dump|$n|type")|bisher"
        [[ -z "$r" ]] && continue
        t="${r%%|*}"; why="${r#*|}"
        where="$(ct_data_where "$n")"
        case "$t" in
            mariadb|postgres|mongodb) proposal="Dump"; dump_cands+=( "$n" ); dtype[$n]="$t" ;;
            cache) proposal="kein Dump (Zwischenspeicher)" ;;
            *)     proposal="Snapshot (Container wird angehalten)" ;;
        esac
        [[ "${CT_RUNNING[$n]}" != "true" ]] && proposal+=", laeuft gerade nicht"
        in_list "$n" "${DOCKER_NO_STOP[@]}" && proposal+=", ${C_Y}laeuft beim Snapshot weiter${C_0}"
        stack="-"; [[ -n "${CT_PROJECT[$n]}" ]] && stack="${CT_PROJECT[$n]}/${CT_SERVICE[$n]}"
        rows+=( "$n|$stack|$t|$why|$where|$proposal" )
        DB_ROWS+=( "$n"$'\x1f'"$stack"$'\x1f'"$t"$'\x1f'"$why"$'\x1f'"$where" )
    done

    # Compose-Stacks: Datenbank-Dienste ohne Container
    compose_scan
    local -a missing=()
    for r in "${COMPOSE_DB[@]}"; do
        IFS='|' read -r stack svc img t why cname <<<"$r"
        compose_container "$stack" "$svc" "$cname" >/dev/null && continue
        missing+=( "$stack|$svc|$img|$t" )
        DB_MISSING+=( "$stack"$'\x1f'"$svc"$'\x1f'"$img"$'\x1f'"$t" )
    done

    if [[ ${#rows[@]} -eq 0 && ${#missing[@]} -eq 0 ]]; then
        hint "Keine Datenbanken gefunden"
    else
        say ""
        thead "$(printf '  %-22s %-22s %-9s %-16s %-26s %s' Container Stack/Dienst Art "erkannt an" Daten Vorschlag)"
        for r in "${rows[@]}"; do
            IFS='|' read -r n stack t why where proposal <<<"$r"
            [[ "$where" == *"!"* ]] && where="${C_R}${where}${C_0}"
            trow "$(printf '  %-22s %-22s %-9s %-16s ' "${n:0:22}" "${stack:0:22}" "$t" "${why:0:16}")$(printf '%-26s' "$where") $proposal"
        done
        for r in "${missing[@]}"; do
            IFS='|' read -r stack svc img t <<<"$r"
            wrn "Stack '$stack': Datenbank-Dienst '$svc' ($t, $img) hat keinen Container - Stack starten und setup.sh erneut aufrufen, dann wird der Dump vorgeschlagen"
        done
    fi
    local e
    for e in "${COMPOSE_ERR[@]}"; do hint "Compose-Stack ${e%%|*} nicht auswertbar: ${e#*|}"; done

    while IFS= read -r n; do
        [[ -n "$n" ]] && ! in_list "$n" "${dump_cands[@]}" && wrn "Dump fuer '$n' steht in settings.ini, Container existiert nicht mehr - Eintrag wird entfernt"
    done < <(old_names dump)

    for n in "${dump_cands[@]}"; do
        local dflt="j"
        [[ "$HAVE_SETTINGS" == "yes" ]] && ! old_has "dump|$n" && in_list "$n" "${known[@]}" && dflt="n"
        if [[ "$MODE" == "apply" ]]; then old_has "dump|$n" && dflt="j" || dflt="n"; fi
        if ask_yn "  Dump von '$n' (${dtype[$n]})?" "$dflt"; then
            P[dump|$n|type]="${dtype[$n]}"
            ok "'$n': ${dtype[$n]}-Dump vor jedem Snapshot"
            [[ "${CT_RUNNING[$n]}" == "true" ]] && dump_probe "$n" "${dtype[$n]}"
        else
            unset "P[dump|$n|type]"
        fi
    done

    # --- Nextcloud
    sub "Nextcloud"
    # Mehrere Container koennen EINE Nextcloud bedienen (App + Cron mit
    # gemeinsamer config.php). Sie werden an der instanceid erkannt und
    # zusammen gefragt - backup.sh schaltet den Wartungsmodus nur einmal.
    local found=0 occ u id g ndf val names
    local -a groups=() m=()
    local -A members=() occ_of=()
    for n in "${CT_NAMES[@]}"; do
        is_nextcloud_image "${CT_IMAGE[$n]}" || old_has "nextcloud|$n" || continue
        [[ "${CT_RUNNING[$n]}" == "true" ]] || { wrn "Nextcloud '$n' laeuft nicht - occ nicht pruefbar"; continue; }
        occ=""
        for t in /var/www/html/occ /app/www/public/occ /config/www/nextcloud/occ /var/www/nextcloud/occ; do
            docker exec "$n" test -f "$t" 2>/dev/null && { occ="$t"; break; }
        done
        [[ -z "$occ" ]] && { hint "'$n' sieht nach Nextcloud aus, hat aber kein occ - uebersprungen"; continue; }
        found=1
        u="$(docker exec "$n" stat -c %U "$occ" 2>/dev/null)"; [[ -z "$u" || "$u" == root || "$u" == UNKNOWN ]] && u="www-data"
        id="$(docker exec -u "$u" "$n" php "$occ" config:system:get instanceid 2>/dev/null | tr -d '\r')"
        g="${id:-?$n}"
        [[ -z "${members[$g]:-}" ]] && groups+=( "$g" )
        members[$g]+="${members[$g]:+ }$n"
        occ_of[$n]="$occ"
    done
    for g in "${groups[@]}"; do
        read -r -a m <<<"${members[$g]}"
        names="$(printf "'%s' + " "${m[@]}")"; names="${names% + }"
        (( ${#m[@]} > 1 )) && hint "$names sind dieselbe Nextcloud (gemeinsame config.php) - ein Wartungsmodus fuer alle"
        # Vorgabe "n" nur, wenn keiner in settings.ini steht, aber alle schon
        # bekannt waren (also bewusst abgelehnt). "continue" nur, wenn alle es
        # hatten - sonst gilt das sichere "abort".
        ndf="n"; [[ "$HAVE_SETTINGS" == "yes" ]] || ndf="j"
        val="continue"
        for n in "${m[@]}"; do
            { old_has "nextcloud|$n" || ! in_list "$n" "${known[@]}"; } && ndf="j"
            [[ "$(old "nextcloud|$n|preexisting_maintenance" abort)" == "continue" ]] || val="abort"
        done
        if [[ "$MODE" == "apply" ]]; then
            ndf="n"; for n in "${m[@]}"; do old_has "nextcloud|$n" && ndf="j"; done
        fi
        NC_ROWS+=( "${m[*]}"$'\x1f'"${occ_of[${m[0]}]}"$'\x1f'"$val" )
        if ask_yn "  Nextcloud $names (occ ${occ_of[${m[0]}]}) waehrend Dump und Snapshot in den Wartungsmodus?" "$ndf"; then
            for n in "${m[@]}"; do
                P[nextcloud|$n|preexisting_maintenance]="$val"
                NC_GROUP[$n]="$(printf '%s\n' "${m[@]}" | grep -Fxv -- "$n" | paste -sd' ' -)"
            done
            ok "$names: Wartungsmodus waehrend des Backups (occ ${occ_of[${m[0]}]})"
        else
            for n in "${m[@]}"; do unset "P[nextcloud|$n|preexisting_maintenance]" "NC_GROUP[$n]"; done
        fi
    done
    (( found )) || hint "Keine Nextcloud erkannt"
    _apply_P
}

dump_probe() { # kurzer Zugangstest, damit der erste Lauf nicht daran scheitert
    local n="$1" t="$2" v
    case "$t" in
        mariadb)
            for v in MARIADB_ROOT_PASSWORD MYSQL_ROOT_PASSWORD MARIADB_PASSWORD MYSQL_PASSWORD; do
                docker exec "$n" sh -c "[ -n \"\${$v:-}\" ]" 2>/dev/null && { ok "'$n': Zugang ueber \$$v"; return; }
            done
            wrn "'$n': kein Passwort in den Umgebungsvariablen - Dump wird scheitern" ;;
        postgres)
            if docker exec "$n" sh -c 'command -v pg_dumpall' >/dev/null 2>&1; then ok "'$n': pg_dumpall vorhanden"
            else wrn "'$n': pg_dumpall fehlt im Container"; fi ;;
        mongodb)
            if docker exec "$n" sh -c 'command -v mongodump' >/dev/null 2>&1; then ok "'$n': mongodump vorhanden"
            else wrn "'$n': mongodump fehlt im Container - Dump wird scheitern"; fi ;;
    esac
}

##############################################################################
# Schritt 5: Snapshots und allgemeine Einstellungen
##############################################################################
step_general() {
    hdr "Snapshots, Aufbewahrung, Flash"
    explain <<'TXT'
ZFS "t w m": die t neuesten Snapshots, dazu je der neueste der letzten w Kalenderwochen
und der letzten m Monate. Pro Share ueberschreibbar (appdata z.B. 7 0 0 - die Zeittiefe
liefert dann Kopia). Snapshots kosten nur, was sich seither geaendert hat.
btrfs: ein Snapshot pro Disk und Lauf, Aufbewahrung in Tagen. Notbremse: faellt der freie
Platz unter die Grenze, werden die aeltesten vorzeitig geloescht. Durchstoebern unter
<view_root>/<disk> (Symlinks - die halten keine Disk fest, der Array-Stopp bleibt frei).
Flash: liegt /boot auf ZFS, wird es gesnapshottet und geht als Quelle "_flash" an Kopia;
sonst als tar.gz in die Dumps (Kernel-Images und Plugin-Pakete ausgenommen - die laedt
Unraid neu).
TXT
    inv_flash
    local havez=0 haveb=0 b
    for b in "${INV_BASES[@]}"; do
        [[ "${INV_BASE_FS[$b]}" == "zfs" ]] && havez=1
        [[ "${INV_BASE_FS[$b]}" == "btrfs" ]] && haveb=1
    done
    say "  Basen: $(for b in "${INV_BASES[@]}"; do printf '%s(%s) ' "$b" "${INV_BASE_FS[$b]}"; done)"
    if (( havez )); then
        ask "  Lokale ZFS-Snapshots behalten: taeglich woechentlich monatlich" "$(pget "zfs|retention")"
        [[ "$REPLY" =~ ^[0-9]+\ [0-9]+\ [0-9]+$ ]] && pset "zfs|retention" "$REPLY"
        ask "  Praefix der ZFS-Snapshots" "$(pget "general|snap_prefix")"
        [[ "$REPLY" =~ ^[a-z0-9_]+-$ ]] && pset "general|snap_prefix" "$REPLY"
    fi
    if (( haveb )); then
        ask "  btrfs-Snapshots behalten (Tage)" "$(pget "btrfs|keep_days")"; is_uint "$REPLY" && pset "btrfs|keep_days" "$REPLY"
        ask "  btrfs: aelteste Snapshots vorzeitig loeschen unter (GB frei)" "$(pget "btrfs|min_free_gb")"; is_uint "$REPLY" && pset "btrfs|min_free_gb" "$REPLY"
        if ask_yn "  Alle btrfs-Array-Disks snapshotten (zum Durchstoebern), auch ohne gesicherte Shares?" \
                  "$([[ $(pget "btrfs|snapshot_all") == yes ]] && echo j || echo n)"; then
            pset "btrfs|snapshot_all" yes
        else pset "btrfs|snapshot_all" no; fi
    fi
    ask "  Dump-/Manifest-Ordner behalten (Laeufe)" "$(pget "general|keep_runs")"; is_uint "$REPLY" && pset "general|keep_runs" "$REPLY"

    sub "Flash ($UB_BOOT)"
    local fm
    if [[ -n "$FLASH_DATASET" ]]; then
        say "  $UB_BOOT liegt auf ZFS ($FLASH_DATASET) - Snapshot moeglich"
        fm="$(old "flash|mode" snapshot)"
    else
        say "  $UB_BOOT ist ${FLASH_FS:-?} - Sicherung als Archiv in den Dumps"
        fm="$(old "flash|mode" tar)"; [[ "$fm" == "snapshot" ]] && fm="tar"
    fi
    if is_yes "$KOPIA_ENABLED"; then
        hint "snapshot = ZFS-Snapshot von $UB_BOOT, geht als Quelle '_flash' an Kopia"
    else
        hint "snapshot = nur lokaler ZFS-Snapshot (Kopia ist aus); tar = Archiv in den Dumps"
    fi
    ask "  Flash sichern: snapshot | tar | off" "$fm"
    case "$REPLY" in snapshot|tar|off) fm="$REPLY" ;; esac
    [[ "$fm" == "snapshot" && -z "$FLASH_DATASET" ]] && { wrn "snapshot geht nur mit ZFS - nehme tar"; fm="tar"; }
    pset "flash|mode" "$fm"
    pinit "flash|kopia_ignore" "$(printf '%s\n' '/bz*' '/EFI*/' '/previous/' '/config/plugins/nvidia-driver/')"
    pinit "flash|tar_exclude"  "$(printf '%s\n' './bz*' './previous' './config/plugins/*/packages')"

    sub "VM-Konfiguration (libvirt.img)"
    if mountpoint -q /etc/libvirt; then
        say "  libvirt.img ist unter /etc/libvirt eingehaengt: XML, NVRAM und TPM-Zustand aller VMs"
        hint "tar = Inhalt jede Nacht als Archiv zu den Dumps (klein; ohne das ist ein Umzug der VMs muehsam)"
        pinit "libvirt|mode" tar
    else
        say "  VM-Dienst aus - nichts zu sichern"
        pinit "libvirt|mode" off
    fi
    ask "  VM-Konfiguration sichern: tar | off" "$(pget "libvirt|mode")"
    case "$REPLY" in tar|off) pset "libvirt|mode" "$REPLY" ;; esac
    _apply_P
}

##############################################################################
# Schritt 6: Kopia
##############################################################################
KOPIA_MAIN=-1          # Index des Mappings, das mount_root abdeckt
KOPIA_POLICY_READY="no"
KOPIA_FAIL=""          # warum der Kopia-Teil nicht durchkam (Code, fuer --plan)
KOPIA_PROBE=""         # Live-Test: 0 = ok, 1 = Container sieht nichts, 2 = nicht moeglich

step_kopia() {
    hdr "Kopia einrichten"
    if ! is_yes "$KOPIA_ENABLED"; then
        KOPIA_FAIL="off"
        hint "Kopia ist aus - uebersprungen. Einschalten: setup.sh erneut, Schritt 3."
        return 0
    fi
    explain <<'TXT'
Mapping: Kopia braucht nur <mount_root>, Access Mode "Read Only - Slave".
- Read Only: Kopia kann an den Quellen nichts veraendern.
- Slave: der Container bekommt beim Start eine Kopie der Mount-Sicht. Die Snapshots
  haengt backup.sh aber erst nachts ein, NACH dem Containerstart. Ohne Slave saehe
  Kopia dort nur leere Ordner (ohne Slave muesste Kopia jede Nacht neu starten). Mit Slave
  reicht der Host neue Mounts und Unmounts in den laufenden Container weiter -
  nur in diese Richtung.
- Bind-Mounts kommen trotz "remount,ro" BESCHREIBBAR im Container an. backup.sh legt sie
  darum in einem privaten Bereich an, stellt sie dort auf ro und verschiebt sie erst dann.
Live-Test: setup.sh haengt ein winziges tmpfs ein und prueft, ob der Container es sieht.
Identitaet (benutzer@host): Quellen gehoeren zu ihr. Aendert sie sich (z.B. Hostname =
Container-ID nach Neuerstellen), beginnen neue Quellen bei null - Daten werden dank
Deduplizierung nicht neu hochgeladen, aber der Verlauf ist getrennt.
Policies: eine auf <mount_root> (Aufbewahrung, "nur manuell", Ignores, Kompression),
jeder Share erbt sie und traegt nur Abweichungen (eigene Ignores, eigene Aufbewahrung).
"Nur manuell", damit der Kopia-Server die Quellen nie selbst startet - tagsueber waeren
die Ordner leer. one-file-system=false, damit Kind-Datasets mitkommen.
TXT
    plan_build
    if [[ ${#PLAN_KOPIA[@]} -eq 0 && "$PLAN_FLASH" != "snapshot" ]]; then
        KOPIA_FAIL="no_shares"; hint "Kein Share geht an Kopia - Kopia-Teil uebersprungen"; return 0
    fi

    # --- Container
    local c n cands=()
    c="$(pget "kopia|container" "")"
    if [[ -z "$c" ]] || ! in_list "$c" "${CT_NAMES[@]}"; then
        for n in "${CT_NAMES[@]}"; do is_kopia_image "${CT_IMAGE[$n]}" && cands+=( "$n" ); done
        if [[ ${#cands[@]} -eq 0 ]]; then
            KOPIA_FAIL="no_container"; bad "Kein Kopia-Container gefunden (Image mit 'kopia' im Namen)"; return 1
        elif [[ ${#cands[@]} -gt 1 ]]; then
            say "  Mehrere Kopia-Container: ${cands[*]}"
            ask "  Welcher sichert dieses Backup" "${cands[0]}"; c="$REPLY"
        else c="${cands[0]}"; fi
    fi
    pset "kopia|container" "$c"; _apply_P
    in_list "$c" "${CT_NAMES[@]}" || { KOPIA_FAIL="no_container"; bad "Container '$c' gibt es nicht"; return 1; }
    ok "Container '$c' (${CT_IMAGE[$c]})"
    [[ "${CT_RUNNING[$c]}" == "true" ]] || { KOPIA_FAIL="not_running"; bad "'$c' laeuft nicht - bitte starten und 'setup.sh --kopia' erneut ausfuehren"; return 1; }

    # --- Mapping
    sub "Pfad-Mapping"
    kopia_mounts_load
    local i
    TROW=0
    for i in "${!KM_SRC[@]}"; do
        trow "$(printf '    %-28s -> %-22s %-3s %s' "${KM_SRC[$i]}" "${KM_DST[$i]}" "$([[ ${KM_RW[$i]} == true ]] && echo rw || echo ro)" "${KM_PROP[$i]:-rprivate}")"
    done
    if ! k_map "$MOUNT_ROOT"; then
        KOPIA_FAIL="no_mapping"; bad "Kein Mapping deckt $MOUNT_ROOT ab"
        mapping_help; return 1
    fi
    KOPIA_MAIN=$KMAP_IDX
    local msrc="${KM_SRC[$KOPIA_MAIN]}" mdst="${KM_DST[$KOPIA_MAIN]}"
    if [[ "$msrc" == "$MOUNT_ROOT" && "$mdst" == "$MOUNT_ROOT" ]]; then ok "$MOUNT_ROOT -> $MOUNT_ROOT (gleiche Pfade innen und aussen)"
    elif [[ "$msrc" == "$MOUNT_ROOT" ]]; then ok "$MOUNT_ROOT -> $mdst (Kopia-Quellen heissen $mdst/<share>)"
    else hint "$MOUNT_ROOT wird ueber das breitere Mapping $msrc -> $mdst erreicht - geht, noetig ist nur $MOUNT_ROOT"; fi
    case "${KM_PROP[$KOPIA_MAIN]}" in
        slave|rslave) ok "Propagation ${KM_PROP[$KOPIA_MAIN]}" ;;
        shared|rshared) ok "Propagation ${KM_PROP[$KOPIA_MAIN]} (slave genuegt)" ;;
        *) KOPIA_FAIL="propagation"; bad "Propagation '${KM_PROP[$KOPIA_MAIN]:-rprivate}' - neue Snapshot-Mounts bleiben fuer Kopia unsichtbar"
           mapping_help; return 1 ;;
    esac
    [[ "${KM_RW[$KOPIA_MAIN]}" == "true" ]] && wrn "Mapping ist beschreibbar - Access Mode 'Read Only - Slave' empfohlen" \
                                             || ok "Mapping ist read-only"
    for i in "${!KM_SRC[@]}"; do
        [[ $i -eq $KOPIA_MAIN ]] && continue
        [[ "${KM_SRC[$i]}" == "$UB_MNT"/* ]] || continue
        hint "Weiteres Daten-Mapping ${KM_SRC[$i]} -> ${KM_DST[$i]} - braucht $UB_NAME nicht; eigene Kopia-Quellen evtl. schon (siehe unten)"
    done

    # --- Live-Probe der Mount-Weitergabe
    kopia_probe_propagation; local pr=$?; KOPIA_PROBE=$pr
    case $pr in
        0) ok "Live-Test: ein neuer Mount unter $MOUNT_ROOT erscheint sofort im Container" ;;
        1) KOPIA_FAIL="probe"; bad "Live-Test: der Container sieht neue Mounts unter $MOUNT_ROOT NICHT - Container nach der Template-Aenderung neu erstellen?"; return 1 ;;
        *) wrn "Live-Test nicht moeglich (tmpfs-Mount unter $MOUNT_ROOT scheiterte)" ;;
    esac

    # --- Read-only auch fuer Unter-Mounts?
    kopia_mountinfo_load
    local mp rwlist=""
    for mp in "${!KMI[@]}"; do
        [[ "$mp" == "$mdst" || "$mp" == "$mdst/"* ]] || continue
        [[ ",${KMI[$mp]}," == *",rw,"* ]] && rwlist+="$mp "
    done
    if [[ -n "$rwlist" && "${KM_RW[$KOPIA_MAIN]}" != "true" ]]; then
        wrn "Im Container beschreibbar trotz read-only-Mapping: $rwlist"
        hint "Docker setzt 'ro' erst ab Version 25 und Kernel 5.12 auch fuer Unter-Mounts; spaeter eingehaengte Disks (Unassigned Devices) bleiben beschreibbar"
    elif [[ "${KM_RW[$KOPIA_MAIN]}" != "true" ]]; then
        ok "Alle Unter-Mounts sind im Container read-only"
    fi

    # --- Repository
    sub "Repository"
    if ! kopia_status_load; then
        KOPIA_FAIL="no_repo"; bad "Kopia ist mit keinem Repository verbunden (oder 'kopia' antwortet nicht im Container)"
        hint "In der KopiaUI verbinden, dann 'setup.sh --kopia'. setup.sh legt bewusst keine Verbindung an."
        return 1
    fi
    ok "Kopia $KOPIA_VERSION, verbunden als $KOPIA_ID, Speicher: ${KOPIA_STORAGE:-?}"
    hint "Config: $KOPIA_CONFIG_FILE"
    if [[ "$KOPIA_HOST" =~ ^[0-9a-f]{12}$ ]]; then
        wrn "Hostname im Repository ist eine Container-ID ($KOPIA_HOST) - nach Neuerstellen des Containers waeren alle Quellen 'fremd'."
        hint "Festlegen in der KopiaUI oder: docker exec $c kopia repository set-client --hostname=<name>"
    fi
    local old_id; old_id="$(old "kopia|identity" "")"
    [[ -n "$old_id" && "$old_id" != "$KOPIA_ID" ]] && wrn "Identitaet war bisher $old_id - neue Snapshots landen in neuen Quellen"
    pset "kopia|identity" "$KOPIA_ID"
    if [[ "$KOPIA_SERVER_UID" == "0" ]]; then
        ok "Kopia-Server laeuft als root - Script und Server teilen Cache und Logs ohne Rechteprobleme"
    else
        KOPIA_FAIL="not_root"; bad "Kopia-Server laeuft als UID $KOPIA_SERVER_UID - backup.sh startet Kopia so NICHT"
        explain <<'TXT'
Snapshots muessen als root laufen, sonst fehlen alle Dateien, die nur ihren Besitzern
gehoeren (Nextcloud-Daten, Datenbank-Ordner, ...). Kopia schreibt bei jedem Aufruf in
Cache und Logs; root legt dort Ordner mit Rechten 0700 an. Der Server (UID 99) kann das
Repository danach nicht mehr oeffnen - "permission denied" in der KopiaUI, auch fuer
deine eigenen geplanten Quellen.
Abhilfe, einmalig: Docker > kopia > Edit:  PUID = 0,  PGID = 0  > Apply.
Dann laufen Server und Script als derselbe Benutzer; vorhandene Dateien kann root lesen.
Bis dahin ruft setup.sh Kopia als UID des Servers auf, damit nichts weiter kaputtgeht.
TXT
        local cdir n
        cdir="$(docker exec "$c" cat "$KOPIA_CONFIG_FILE" 2>/dev/null | jq -r '.caching.cacheDirectory // empty')"
        [[ -n "$cdir" && "$cdir" != /* ]] && cdir="$(dirname "$KOPIA_CONFIG_FILE")/$cdir"
        if [[ -n "$cdir" ]]; then
            n="$(docker exec "$c" find "$cdir" -uid 0 2>/dev/null | wc -l)"
            (( n > 0 )) && wrn "Im Server-Cache $cdir liegen schon $n Eintraege von root - der Server hat damit jetzt schon Probleme; mit PUID=0 erledigt"
        fi
    fi

    # --- Policies
    kopia_policies_load
    sub "Policies"
    say "  Policy auf $(k_path "$MOUNT_ROOT") - alle Shares erben sie, pro Share steht nur die Abweichung:"
    say "  Aufbewahrung:"
    say "    latest $(pget "kopia|keep_latest")  hourly $(pget "kopia|keep_hourly")  daily $(pget "kopia|keep_daily")  weekly $(pget "kopia|keep_weekly")  monthly $(pget "kopia|keep_monthly")  annual $(pget "kopia|keep_annual")"
    say "  Kompression: $(pget "kopia|compression")   (inherit = wie global in Kopia)"
    say "  Zeitplan: nur manuell - Kopia startet diese Quellen nie selbst"
    say "  Ignore global: $(plist "kopia|ignore" | paste -sd' ' -)"
    if interactive && ! ask_yn "  So lassen?" j; then
        local f
        for f in keep_latest keep_hourly keep_daily keep_weekly keep_monthly keep_annual; do
            ask "    $f" "$(pget "kopia|$f")"; [[ "$REPLY" =~ ^([0-9]+|inherit)$ ]] && pset "kopia|$f" "$REPLY"
        done
        ask "    compression (inherit, none, zstd, zstd-fastest, s2-default, ...)" "$(pget "kopia|compression")"
        [[ "$REPLY" =~ ^[a-z0-9-]+$ ]] && pset "kopia|compression" "$REPLY"
        ask "    Ignore global (Leerzeichen-getrennt)" "$(plist "kopia|ignore" | paste -sd' ' -)"
        P[kopia|ignore]="$(tr ' ' '\n' <<<"$REPLY" | sed '/^$/d')"
        hint "Kompression wirkt nur auf neu hochgeladene Daten; vorhandene Blobs bleiben wie sie sind."
    fi
    _apply_P; plan_build
    KOPIA_POLICY_READY="yes"
}

mapping_help() {
    say ""
    say "  ${C_B}So richtest du das Mapping ein (Unraid > Docker > $KOPIA_CONTAINER > Edit):${C_0}"
    say "    Add another Path, Port, Variable, Label or Device > Config Type: Path"
    say "      Container Path:  $MOUNT_ROOT"
    say "      Host Path:       $MOUNT_ROOT"
    say "      Access Mode:     Read Only - Slave   (nicht nur 'Read Only' - ohne Slave"
    say "                       sieht der laufende Container keine neuen Snapshot-Mounts)"
    say "    Apply. Danach: setup.sh --kopia"
}

##############################################################################
# Schritt 7: Schreiben und anwenden
##############################################################################
w_kv()   { printf '%s = %s\n' "$1" "$2"; }
w_list() { local k="$1" v; while IFS= read -r v; do [[ -n "$v" ]] && printf '%s = %s\n' "$k" "$v"; done < <(plist "$2"); }
w_c()    { printf '# %s\n' "$@"; }

write_settings() {
    local tmp="$UB_SETTINGS.tmp.$$" s n
    {
        echo "###############################################################################"
        echo "# $UB_NAME - settings.ini fuer $(pget "general|server")"
        echo "# Erzeugt von setup.sh $UB_VERSION am $(date '+%d.%m.%Y %H:%M'). Von Hand aenderbar, danach:"
        echo "#   setup.sh --check    pruefen"
        echo "#   setup.sh --kopia    Kopia-Policies angleichen"
        echo "# Ein neuer setup.sh-Lauf uebernimmt alle Werte, schreibt die Kommentare aber neu."
        echo "#"
        echo "# Format: [abschnitt] bzw. [typ \"name\"], darunter  schluessel = wert"
        echo "# Listen: Schluessel mehrfach. Kommentare nur auf eigenen Zeilen (# oder ;)."
        echo "###############################################################################"
        echo
        echo "[general]"
        w_c "Name in den Mitteilungen"
        w_kv server "$(pget "general|server")"
        w_c "Hier haengen die Snapshots waehrend des Laufs: <mount_root>/<share>"
        w_kv mount_root "$(pget "general|mount_root")"
        w_c "btrfs-Snapshots durchstoebern: <view_root>/<disk> (Symlinks, halten keine Disk fest)"
        w_kv view_root "$(pget "general|view_root")"
        w_c "ZFS-Snapshots heissen <praefix>JJJJMMTT-HHMM; aufgeraeumt wird nur dieser Praefix"
        w_kv snap_prefix "$(pget "general|snap_prefix")"
        w_c "Unterordner fuer btrfs-Snapshots auf jeder btrfs-Disk/jedem btrfs-Pool"
        w_kv btrfs_snap_dir "$(pget "general|btrfs_snap_dir")"
        w_c "Aufbewahrung der Dump-/Manifest-Ordner und der Protokolle (Anzahl Laeufe)"
        w_kv keep_runs "$(pget "general|keep_runs")"
        w_kv keep_logs "$(pget "general|keep_logs")"
        w_c "So viel Platz (GB) muss fuer die Dumps mindestens frei sein"
        w_kv min_free_gb "$(pget "general|min_free_gb")"
        w_c "yes = Snapshots bleiben bis zum naechsten Lauf eingehaengt. Dann zusaetzlich ein"
        w_c "User Script 'At Stopping of Array' mit: backup.sh --unmount"
        w_kv keep_mounts "$(pget "general|keep_mounts")"
        w_c "Mitteilung auch bei Erfolg (yes/no)"
        w_kv notify_success "$(pget "general|notify_success")"
        echo
        echo "[zfs]"
        w_c "Lokale ZFS-Snapshots: taeglich woechentlich monatlich (pro Share ueberschreibbar)"
        w_kv retention "$(pget zfs\|retention)"
        echo
        echo "[btrfs]"
        w_kv keep_days "$(pget btrfs\|keep_days)"
        w_c "Unter so viel freiem Platz (GB) werden die aeltesten Snapshots vorzeitig geloescht"
        w_kv min_free_gb "$(pget btrfs\|min_free_gb)"
        w_c "yes = alle btrfs-Array-Disks bekommen Snapshots (zum Durchstoebern), auch ohne Kopia-Share"
        w_kv snapshot_all "$(pget btrfs\|snapshot_all)"
        echo
        echo "[drift]"
        w_c "Neue Shares, die auf eines dieser Muster passen, meldet backup.sh nicht"
        w_list ignore "drift|ignore"
        w_c "Dieselbe Abweichung erneut melden nach so vielen Tagen"
        w_kv remind_days "$(pget drift\|remind_days)"
        echo
        echo "[docker]"
        w_c "all = alle laufenden Container fuer den Snapshot anhalten (ausser no_stop und Kopia)"
        w_c "none = keinen anhalten (nur Dumps + absturzkonsistente Snapshots)"
        w_kv stop "$(pget docker\|stop)"
        w_kv stop_timeout "$(pget docker\|stop_timeout)"
        w_list no_stop "docker|no_stop"
        w_c "Beim letzten setup.sh vorhandene Container - alles andere meldet backup.sh als neu"
        w_list known "docker|known"
        echo
        echo "[flash]"
        w_c "snapshot = ZFS-Snapshot von /boot an Kopia, tar = Archiv in dumps/<zeit>/, off = nichts"
        w_kv mode "$(pget flash\|mode)"
        w_list kopia_ignore "flash|kopia_ignore"
        w_list tar_exclude "flash|tar_exclude"
        echo
        echo "[libvirt]"
        w_c "VM-Konfiguration aus libvirt.img (XML, NVRAM, TPM-Zustand): tar = Archiv in dumps/<zeit>/, off = nichts"
        w_kv mode "$(pget libvirt\|mode tar)"
        echo
        echo "[kopia]"
        w_c "yes = Shares mit mode=kopia gehen offsite; no = nur lokale Snapshots und Dumps"
        w_kv enabled "$(pget "kopia|enabled" yes)"
        w_kv container "$(pget "kopia|container")"
        w_c "Identitaet laut Repository beim letzten setup.sh (benutzer@host)"
        w_kv identity "$(pget "kopia|identity")"
        w_c "Aufbewahrung in Kopia (Zahl oder inherit)"
        for n in keep_latest keep_hourly keep_daily keep_weekly keep_monthly keep_annual; do w_kv "$n" "$(pget "kopia|$n")"; done
        w_c "inherit = wie die globale Kopia-Policy; sonst none, zstd, zstd-fastest, s2-default, ..."
        w_kv compression "$(pget "kopia|compression")"
        w_c "Ignore-Regeln fuer alle Quellen"
        w_list ignore "kopia|ignore"
        while IFS= read -r n; do
            [[ -z "$n" ]] && continue
            echo
            echo "[nextcloud \"$n\"]"
            [[ -n "${NC_GROUP[$n]:-}" ]] && w_c "dieselbe Nextcloud wie: ${NC_GROUP[$n]} (gemeinsame config.php) - Wartungsmodus nur einmal"
            w_c "abort = Lauf abbrechen, wenn der Wartungsmodus schon an war; continue = trotzdem sichern"
            w_kv preexisting_maintenance "$(pget "nextcloud|$n|preexisting_maintenance" abort)"
        done < <(printf '%s\n' "${!P[@]}" | sed -n 's/^nextcloud|\(.*\)|preexisting_maintenance$/\1/p' | sort)
        while IFS= read -r n; do
            [[ -z "$n" ]] && continue
            echo
            echo "[dump \"$n\"]"
            w_kv type "$(pget "dump|$n|type")"
        done < <(printf '%s\n' "${!P[@]}" | sed -n 's/^dump|\(.*\)|type$/\1/p' | sort)
        echo
        echo "# --- Shares -------------------------------------------------------------------"
        w_c "mode             kopia = Snapshot + Kopia, snapshot = nur lokaler Snapshot, off = nichts"
        w_c "retention        lokale ZFS-Snapshots 'taeglich woechentlich monatlich'"
        w_c "kopia_retention  eigene Kopia-Aufbewahrung 'latest hourly daily weekly monthly annual'"
        w_c "                 (fehlt = wie in [kopia]; einzelne Werte auch 'inherit')"
        w_c "method           auto | live  (live = ohne Snapshot: /mnt/user/<share> read-only einbinden)"
        w_c "kopia_ignore     Kopia-Ignore-Regel relativ zum Share (mehrfach)"
        w_c "exclude_dataset  Kind-Dataset weder snapshotten noch sichern (mehrfach)"
        w_c "id, locations    von setup.sh - erkennen Umbenennungen und Umzuege"
        for s in "${SH[@]}"; do
            [[ "${WHY[$s]:-}" == "existiert nicht mehr"* ]] && continue
            share_name_ok "$s" || continue
            echo
            echo "[share \"$s\"]"
            w_kv mode "$(pget "share|$s|mode")"
            [[ -n "$(pget "share|$s|retention")" ]] && w_kv retention "$(pget "share|$s|retention")"
            [[ -n "$(pget "share|$s|kopia_retention")" ]] && w_kv kopia_retention "$(pget "share|$s|kopia_retention")"
            # Ein Share, der von Natur aus nur live geht, bekommt method = live - damit ist
            # die Wahl bestaetigt und backup.sh meldet es nicht jede Nacht als Abweichung
            if [[ "$(pget "share|$s|mode")" != "off" && "${INV_METHOD[$s]:-}" == "live" ]]; then
                w_kv method live
            elif [[ "$(pget "share|$s|method" auto)" != "auto" ]]; then
                w_kv method "$(pget "share|$s|method")"
            fi
            w_list kopia_ignore "share|$s|kopia_ignore"
            w_list exclude_dataset "share|$s|exclude_dataset"
            inv_has_share "$s" && [[ "${INV_METHOD[$s]}" != "none" ]] && {
                w_kv id "${INV_ID[$s]}"
                w_kv locations "$(inv_locnames "$s")"
            }
        done
    } >"$tmp" || { bad "Kann $tmp nicht schreiben"; return 1; }

    # Gegenprobe: neue Datei muss sich fehlerfrei laden lassen
    local keep_cfg; keep_cfg="$(declare -p CFG)"; keep_cfg="${keep_cfg/declare -A/declare -gA}"
    if ! UB_SETTINGS="$tmp" cfg_load "$tmp" || ! cfg_validate; then
        bad "Erzeugte settings.ini ist fehlerhaft:"; local e; for e in "${CFG_ERRORS[@]}"; do say "    $e"; done
        mv "$tmp" "$UB_STATE/settings.ini.defekt.$TS"; eval "$keep_cfg"; return 1
    fi
    if [[ -f "$UB_SETTINGS" ]]; then
        cp -a "$UB_SETTINGS" "$UB_STATE/settings.ini.$TS"
        ls -1t "$UB_STATE"/settings.ini.[0-9]* 2>/dev/null | tail -n +11 | xargs -r rm -f
    fi
    mv "$tmp" "$UB_SETTINGS"
    WRITTEN="yes"
    ok "settings.ini geschrieben ($UB_SETTINGS)"
    [[ -f "$UB_STATE/settings.ini.$TS" ]] && hint "Vorherige Fassung: state/settings.ini.$TS"
    return 0
}

apply_kopia_policies() {
    [[ "$KOPIA_POLICY_READY" == "yes" ]] || { hint "Kopia-Policies nicht gesetzt (Kopia-Pruefung unvollstaendig)"; return 0; }
    load_settings >/dev/null
    plan_build
    kopia_mounts_load; kopia_status_load || return 1; kopia_policies_load
    local kind hpath share cpath changes=0
    local -a todo=()
    sub "Kopia-Policies (Soll aus settings.ini)"
    while IFS='|' read -r kind hpath share; do
        [[ -z "$kind" ]] && continue
        cpath="$(k_path "$hpath")" || { wrn "$hpath ist im Container nicht abgebildet"; continue; }
        if kopia_policy_eval "$cpath" "$kind" "$(kopia_want_ignores "$kind" "$share")" "$(kopia_want_retention "$kind" "$share")"; then
            ok "$cpath"
        else
            say "  ${C_Y}aendern${C_0} $cpath"; printf '%s' "$KP_DIFF" | while IFS= read -r l; do say "    $l"; done
            todo+=( "$cpath" ); changes=$((changes+1))
            KPA["$cpath"]="$(printf '%q ' "${KP_ARGS[@]}")"
        fi
    done < <(kopia_targets)
    (( changes == 0 )) && { ok "Alle Policies stimmen"; return 0; }
    [[ "$MODE" == "check" ]] && return 0
    ask_yn "  $changes Policy-Aenderung(en) in Kopia schreiben?" j || { wrn "Policies nicht geschrieben - backup.sh wird das melden"; return 0; }
    for cpath in "${todo[@]}"; do
        eval "local -a args=( ${KPA[$cpath]} )"
        # shellcheck disable=SC2154
        if kopia_x policy set "$KOPIA_ID:$cpath" "${args[@]}" >>"$LOG_FILE" 2>&1; then ok "gesetzt: $cpath"
        else bad "policy set $cpath fehlgeschlagen (siehe $LOG_FILE)"; fi
    done
}
declare -A KPA=()
declare -a KOPIA_SOURCES=()   # "zustand<US>quelle" - active foreign orphan own gone
DECIDE_RETIRE="no"

# Alte und verwaiste Quellen
step_kopia_sources() {
    [[ "$KOPIA_POLICY_READY" == "yes" ]] || return 0
    sub "Vorhandene Quellen im Repository"
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
        if [[ "$u@$h" != "$KOPIA_ID" ]]; then hint "fremd     $src (andere Identitaet)"; KOPIA_SOURCES+=( "foreign"$'\x1f'"$src" ); continue; fi
        if grep -Fxq -- "$p" <<<"$want_paths"; then ok "aktiv     $p"; KOPIA_SOURCES+=( "active"$'\x1f'"$src" ); continue; fi
        if [[ "$p" == "$croot" || "$p" == "$croot/"* ]]; then
            wrn "verwaist  $p (unter $croot, aber kein Share mehr)"; retire+=( "$src" ); KOPIA_SOURCES+=( "orphan"$'\x1f'"$src" ); continue
        fi
        if docker exec "$KOPIA_CONTAINER" test -e "$p" 2>/dev/null; then
            hint "eigene    $p (nicht von $UB_NAME verwaltet - braucht ihr eigenes Mapping)"
            KOPIA_SOURCES+=( "own"$'\x1f'"$src" )
        else
            KOPIA_SOURCES+=( "gone"$'\x1f'"$src" )
            wrn "ohne Pfad $p - existiert im Container nicht mehr (z.B. von einem frueheren Backup-Script)."
            hint "          War das eine eigene Sicherung mit Zeitplan in der KopiaUI, fehlt ihr Mapping -"
            hint "          wieder eintragen oder den Share hier mit mode=kopia aufnehmen."
            retire+=( "$src" )
        fi
    done < <(kopia_sources)

    [[ "$MODE" == "check" || "$MODE" == "plan" ]] && return 0
    [[ ${#retire[@]} -eq 0 ]] && return 0
    # --apply: nur auf ausdruecklichen Wunsch (_retire_sources = yes)
    [[ "$MODE" == "apply" && "$DECIDE_RETIRE" != "yes" ]] && return 0
    local -a tomanual=()
    for src in "${retire[@]}"; do
        u="${src%%@*}"; h="${src#*@}"; h="${h%%:*}"; p="${src#*:}"
        if [[ "$(jq -r --arg p "$p" --arg u "$u" --arg h "$h" \
                 'first(.[] | select(.target.path==$p and .target.userName==$u and .target.host==$h) | .scheduling.manual) // false' \
                 <<<"$KP_JSON")" == "true" ]]; then
            hint "          $p steht schon auf manuell"
        else
            tomanual+=( "$src" )
        fi
    done
    if [[ ${#tomanual[@]} -gt 0 ]] && \
       ask_yn "  ${#tomanual[@]} alte/verwaiste Quelle(n) auf 'manuell' stellen (Kopia plant sie nicht mehr, Snapshots bleiben)?" j; then
        for src in "${tomanual[@]}"; do
            if kopia_x policy set "$src" --manual >>"$LOG_FILE" 2>&1; then ok "manuell: $src"; else wrn "konnte $src nicht umstellen"; fi
        done
    fi
    interactive || return 0
    say "  Loeschen entfernt ALLE Snapshots einer Quelle endgueltig (Platz wird bei der naechsten"
    say "  Kopia-Wartung frei). Erst tun, wenn der neue Lauf erfolgreich war."
    for src in "${retire[@]}"; do
        ask "  '$src' loeschen? Zum Loeschen LOESCHEN eintippen" "nein"
        if [[ "$REPLY" == "LOESCHEN" ]]; then
            kopia_x snapshot delete --all-snapshots-for-source "$src" --delete >>"$LOG_FILE" 2>&1 \
                && ok "geloescht: $src" || bad "Loeschen von $src fehlgeschlagen"
        fi
    done
}



##############################################################################
# Schritt 8: User Scripts, Aufraeumen, Abschluss
##############################################################################
step_finish() {
    hdr "User Scripts und Aufraeumen"
    explain <<'TXT'
User Scripts braucht sein Script unter /boot/config/plugins/user.scripts/scripts/. Dort
liegt nur ein Aufruf von backup.sh im Script-Ordner (#arrayStarted=true: laeuft nur bei
gestartetem Array). Den Zeitplan setzt du in der Oberflaeche (Custom, z.B. 0 3 * * *).
TXT
    local us_root="$UB_BOOT/config/plugins/user.scripts/scripts"
    local us_dir="$us_root/$UB_USER_SCRIPT" upath
    upath="$(ub_user_path)"
    if [[ -d "$us_root" ]]; then
        # the old name: normally the office has moved it already (with its schedule)
        if [[ -d "$us_root/$UB_NAME" && ! -e "$us_dir" ]]; then
            mv "$us_root/$UB_NAME" "$us_dir" && echo "$UB_USER_SCRIPT" >"$us_dir/name" \
                && hint "User Script '$UB_NAME' heisst jetzt '$UB_USER_SCRIPT' - den Zeitplan dort pruefen"
        fi
        if [[ -f "$us_dir/script" ]] && grep -q "$upath/backup.sh" "$us_dir/script"; then
            ok "User Script '$UB_USER_SCRIPT' vorhanden"
        elif ask_yn "  User Script '$UB_USER_SCRIPT' anlegen? (nur ein 3-Zeilen-Aufruf auf dem Flash, alles andere bleibt in $upath)" j; then
            mkdir -p "$us_dir"
            cat >"$us_dir/script" <<EOF
#!/bin/bash
#description=Unraid Secretary Office - Mr. Backupsy's nightly backup: snapshots, database dumps, Kopia offsite. Set up and scheduled in the office. Code: $upath, data: $(dirname "$upath")/data/$UB_NAME
#arrayStarted=true
exec "$upath/backup.sh" "\$@"
EOF
            echo "$UB_USER_SCRIPT" >"$us_dir/name"
            ok "Angelegt: $us_dir/script"
        fi
        hint "Zeitplan in Settings > User Scripts setzen: Custom, z.B. 0 3 * * *"
        # Andere User Scripts, die ebenfalls Snapshots oder Kopia-Laeufe machen?
        local f
        for f in "$UB_BOOT"/config/plugins/user.scripts/scripts/*/script; do
            [[ -f "$f" && "$f" != "$us_dir/script" ]] || continue
            if grep -qE 'kopia[^|]* snapshot create|zfs snapshot|btrfs subvolume snapshot' "$f" 2>/dev/null; then
                wrn "User Script '$(basename "$(dirname "$f")")' macht ebenfalls Snapshots/Kopia-Laeufe - pruefen, ob es abgeloest ist (sonst Zeitplan dort deaktivieren)"
            fi
        done
    fi
}

summary() {
    say ""
    band "Ergebnis" "== Ergebnis =="
    plan_build
    if is_yes "$KOPIA_ENABLED"; then say "  Shares an Kopia:      ${PLAN_KOPIA[*]:-keine}"
    else say "  Kopia:                aus (nur lokale Snapshots und Dumps)"; fi
    say "  Nur lokal (Snapshot): $(for s in "${PLAN_SNAP[@]}"; do in_list "$s" "${PLAN_KOPIA[@]}" || printf '%s ' "$s"; done)"
    say "  ZFS-Datasets:         ${#PLAN_ZFS[@]}   btrfs-Basen: ${#PLAN_BTRFS[@]}   Flash: $PLAN_FLASH"
    say "  Fehler: $ERRORS   Warnungen: $WARNINGS   Protokoll: $LOG_FILE"
    say ""
    say "  Naechste Schritte:"
    say "    1. Trockenlauf:   UB_DRY_RUN=1 $(ub_user_path)/backup.sh"
    say "    2. Erster Lauf:   in User Scripts 'Run in Background' oder warten bis zum Zeitplan"
    say "    3. Pruefen:       $UB_LOGS/latest.log  (oder im Sekretariat bei Herrn Backup)"
}

##############################################################################
# Pruefmodus
##############################################################################
run_check() {
    hdr "Pruefung gegen settings.ini"
    load_settings || { bad "settings.ini fehlt - erst setup.sh ohne --check"; return 1; }
    inv_scan; docker_load; plan_build
    drift_check_settings
    drift_check_shares
    drift_check_containers
    drift_check_kopia
    if [[ ${#DRIFT[@]} -eq 0 ]]; then ok "Keine Abweichungen"
    else drift_text | while IFS= read -r l; do say "  $l"; done; fi
    if [[ "$KOPIA_OK" == "yes" ]]; then
        kopia_probe_propagation && ok "Live-Test Mount-Weitergabe in '$KOPIA_CONTAINER' bestanden" \
                                || bad "Live-Test: '$KOPIA_CONTAINER' sieht neue Mounts unter $MOUNT_ROOT nicht"
    fi
    say ""
    say "  Plan: ${#PLAN_KOPIA[@]} Shares an Kopia, ${#PLAN_ZFS[@]} ZFS-Datasets, ${#PLAN_BTRFS[@]} btrfs-Basen, Flash $PLAN_FLASH"
}

##############################################################################
# Fuer Oberflaechen: Plan, Entscheidungen, Status (--plan / --apply)
##############################################################################
# Alles mit festen, englischen Schluesseln (Schnittstelle UB_INTERFACE wie
# bei backup.sh). Texte in "text"/"why_text" sind nur fuer Menschen; wer
# uebersetzt, nimmt die Codes.
SETUP_STARTED=0
WRITTEN="no"

# Zeilen "a<US>b<US>c" -> JSON-Array von Objekten mit den Feldnamen $*
us_json() { jq -Rn --arg f "$*" '($f | split(" ")) as $k
    | [inputs | split("\u001f") as $v | [range(0; $k | length) | {key: $k[.], value: ($v[.] // "")}] | from_entries]'; }

setup_status_write() { # setup_status_write <ergebnis>
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

setup_end() {
    local rc=$?
    if (( rc == 0 )); then setup_status_write ok; else setup_status_write failed; fi
}

# Entscheidungen (JSON-Objekt Schluessel -> Wert oder Liste) ueber die
# bisherigen Werte legen: sie werden zu den Vorgaben, der Rest laeuft wie --yes.
# Dumps und Nextclouds gelten nur, wenn sie in den Entscheidungen stehen.
decisions_load() {
    local f="${UB_DECISIONS:-}" k v sec n=0
    [[ -n "$f" && -r "$f" ]] || { bad "Entscheidungen fehlen: ${f:-?}"; exit 1; }
    jq -e 'type == "object"' "$f" >/dev/null 2>&1 || { bad "Entscheidungen sind kein JSON-Objekt: $f"; exit 1; }
    for k in "${!OLD[@]}"; do [[ "$k" == dump\|* || "$k" == nextcloud\|* ]] && unset "OLD[$k]"; done
    local -a keep=(); for sec in "${OLD_SECTIONS[@]}"; do [[ "$sec" == dump\|* || "$sec" == nextcloud\|* ]] || keep+=( "$sec" ); done
    OLD_SECTIONS=( "${keep[@]}" )
    while IFS= read -r -d '' k && IFS= read -r -d '' v; do
        if [[ "$k" == "_retire_sources" ]]; then DECIDE_RETIRE="$v"; continue; fi
        if [[ ! "$k" =~ ^[a-z_]+(\|[^|]+)?\|[a-z_]+$ || "$v" == *$'\n'* ]]; then wrn "Entscheidung '$k' ignoriert (ungueltig)"; continue; fi
        OLD[$k]="${v//$'\x1f'/$'\n'}"
        sec="${k%|*}"; old_has "$sec" || OLD_SECTIONS+=( "$sec" )
        n=$((n+1))
    done < <(jq --raw-output0 'to_entries[] | .key, (if (.value | type) == "array" then (.value | map(tostring) | join("\u001f")) else (.value | tostring) end)' "$f")
    ok "$n Entscheidungen aus dem Sekretariat uebernommen"
}

# Alles, was eine Oberflaeche zum Entscheiden braucht -> state/setup-plan.json
plan_write() {
    local k s n b tmp="$UB_STATE/.setup-plan.json.$$"
    local p shares cts dbs miss ncs bases srcs maps
    p="$(for k in "${!P[@]}"; do printf '%s\x1f%s\n' "$k" "${P[$k]//$'\n'/$'\x1e'}"; done \
        | jq -Rn '[inputs | index("\u001f") as $i | {key: .[0:$i], value: .[$i + 1:]}]
                  | map(if (.key | test("\\|(ignore|no_stop|known|kopia_ignore|exclude_dataset|tar_exclude)$"))
                        then .value |= (split("\u001e") | map(select(length > 0))) else . end) | from_entries')"
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
    cts="$(for n in "${CT_NAMES[@]}"; do
        printf '%s\x1f' "$n" "${CT_IMAGE[$n]}" "${CT_RUNNING[$n]}" "${CT_STOP[$n]:-}" "${CT_CODE[$n]:-}" "${CT_ARG[$n]:-}" \
            "${CT_PREV[$n]:-}" "${CT_RISK[$n]:-}" "$(printf '%s' "${CT_VOLUMES[$n]:-}" | cut -d'|' -f2 | tr '\n' $'\x1e')" \
            "${CT_PROJECT[$n]:-}" "${CT_SERVICE[$n]:-}" "$([[ "$n" == "$KOPIA_CONTAINER" ]] && echo 1)"
        echo
    done | us_json name image running stop why why_arg previous risk volumes project service kopia \
         | jq 'map(.running = (.running == "true") | .stop = (.stop == "yes") | .previous = (.previous == "1")
                   | .risk = (.risk == "1") | .kopia = (.kopia == "1")
                   | .volumes = (.volumes | split("\u001e") | map(select(length > 0))))')"
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
        --arg have "$HAVE_SETTINGS" --argjson P "$p" --argjson shares "$shares" --argjson containers "$cts" \
        --argjson databases "$dbs" --argjson missing "$miss" --argjson nextcloud "$ncs" --argjson bases "$bases" \
        --arg flash_ds "${FLASH_DATASET:-}" --arg flash_fs "${FLASH_FS:-}" --arg size_timeout "$SIZE_TIMEOUT" \
        --arg k_enabled "$(pget "kopia|enabled" no)" --arg k_container "$kc" --arg k_cands "$cands" \
        --arg k_running "${CT_RUNNING[$kc]:-}" --arg k_image "${CT_IMAGE[$kc]:-}" --arg k_ready "$KOPIA_POLICY_READY" \
        --arg k_fail "$KOPIA_FAIL" --arg k_probe "$KOPIA_PROBE" --argjson k_maps "$maps" \
        --arg k_connected "${KOPIA_CONNECTED:-no}" --arg k_id "${KOPIA_ID:-}" --arg k_version "${KOPIA_VERSION:-}" \
        --arg k_storage "${KOPIA_STORAGE:-}" --arg k_host "${KOPIA_HOST:-}" --arg k_uid "${KOPIA_SERVER_UID:-}" \
        --argjson k_sources "$srcs" --arg mount_root "$MOUNT_ROOT" \
        '{interface: $interface, version: $version, time: $time, have_settings: ($have == "yes"),
          sizes_measured: ($size_timeout != "0"), P: $P, shares: $shares, containers: $containers,
          databases: $databases, missing_databases: $missing, nextcloud: $nextcloud,
          bases: $bases, flash: {dataset: $flash_ds, fs: $flash_fs}, mount_root: $mount_root,
          kopia: {enabled: ($k_enabled == "yes"), container: $k_container,
                  candidates: ($k_cands | split("\u001e") | map(select(length > 0))),
                  running: ($k_running == "true"), image: $k_image, ready: ($k_ready == "yes"),
                  problem: (if $k_fail == "" then null else $k_fail end),
                  probe: (if $k_probe == "" then null else ($k_probe | tonumber) end), mappings: $k_maps,
                  connected: ($k_connected == "yes"), identity: $k_id, version: $k_version, storage: $k_storage,
                  host_is_container_id: ($k_host | test("^[0-9a-f]{12}$")), server_uid: $k_uid, sources: $k_sources},
          messages: map(select(.text != ""))}' >"$tmp" \
        && mv -f "$tmp" "$UB_STATE/setup-plan.json" && ok "Plan geschrieben: $UB_STATE/setup-plan.json" \
        || { rm -f "$tmp"; bad "Plan liess sich nicht schreiben"; return 1; }
}

##############################################################################
# Ablauf
##############################################################################
exec 9>"$UB_STATE/lock"
flock -n 9 || { echo "backup.sh laeuft gerade - setup.sh spaeter starten."; exit 1; }
if [[ "$MODE" == "plan" || "$MODE" == "apply" ]]; then
    SETUP_STARTED="$(date +%s)"
    setup_status_write running
    trap setup_end EXIT
fi

say "${C_B}$UB_NAME $UB_VERSION - setup ($MODE) auf $(hostname -s)${C_0}"
[[ "$MODE" != "check" && "$MODE" != "plan" ]] && recover_interrupted_run
case "$MODE" in
    check)
        STEPS=1; run_check ;;
    kopia)
        STEPS=1
        load_settings || { echo "settings.ini fehlt - erst setup.sh ohne --kopia"; exit 1; }
        is_yes "$KOPIA_ENABLED" || { echo "Kopia ist in settings.ini ausgeschaltet - zum Einschalten setup.sh ohne Option aufrufen."; exit 1; }
        old_keep
        for k in "${!CFG[@]}"; do P[$k]="${CFG[$k]}"; done
        HAVE_SETTINGS="yes"
        mapfile -t SH < <(cfg_names share)
        inv_scan; docker_load
        step_kopia && { write_settings; apply_kopia_policies; step_kopia_sources; } ;;
    plan)
        STEP_ID=environment; step_environment
        STEP_ID=basis;       step_basis
        STEP_ID=offsite;     step_offsite
        STEP_ID=shares;      step_shares
        STEP_ID=containers;  step_containers
        STEP_ID=databases;   step_databases
        STEP_ID=general;     step_general
        STEP_ID=kopia;       step_kopia || true
        STEP_ID=sources;     step_kopia_sources
        STEP_ID=plan;        plan_write ;;
    interactive|auto|apply)
        STEP_ID=environment; step_environment
        STEP_ID=basis;       step_basis
        STEP_ID=offsite;     step_offsite
        STEP_ID=shares;      step_shares
        STEP_ID=containers;  step_containers
        STEP_ID=databases;   step_databases
        STEP_ID=general;     step_general
        STEP_ID=kopia;       step_kopia || true
        STEP_ID=write
        hdr "Schreiben"
        explain <<'TXT'
settings.ini wird erst in eine Temp-Datei geschrieben, neu geladen und geprueft; nur wenn
sie fehlerfrei ist, ersetzt sie die alte (die alte wandert nach state/). Danach vergleicht
setup.sh jede Kopia-Policy mit dem Soll und aendert nur die Abweichungen (Ignores einzeln
hinzufuegen/entfernen statt alles neu zu setzen).
TXT
        if interactive && ! ask_yn "settings.ini jetzt schreiben?" j; then
            say "Nichts geschrieben."; exit 0
        fi
        if ! write_settings; then
            say "settings.ini wurde NICHT geschrieben - Abbruch."; exit 1
        fi
        STEP_ID=policies; apply_kopia_policies
        STEP_ID=sources;  step_kopia_sources
        STEP_ID=finish;   step_finish
        summary ;;
esac
exit 0
