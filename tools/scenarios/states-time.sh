#!/bin/bash
# tools/scenarios/states-time.sh — the server's clock and time zone, the I states (TM-*) of zustaende.md (H4, wave 1b).
# Sourced by lib.sh; uses states-common.sh.
#
#   clock_set <id> <date|@epoch>   ntpd stopped, the clock set (`date -s`); where the real time is comes from /proc/uptime
#                                  (noted in the stash: real epoch + uptime at the moment of the set — Unraid has no bc)
#   clock_restore <id>             the real time back from that note (bash arithmetic), ntpd started when it ran before
#   tz_break <id> [zone]           /etc/localtime → a zone file that doesn't exist (default Europe/Zurich: missing on 7.3/7.4 -
#                                  the server runs UTC) and ident.cfg's timeZone the same, as Unraid's ⟦Date and Time⟧ leaves it
#   tz_fix <id>                    both as they were
#
# The clock is put back three ways, the first that comes: the state's undo (lib.sh runs it from its EXIT trap too), a
# guard process `uso-clock-guard` that restores it when the scenario's shell is gone or after USO_CLOCK_MAX seconds
# (default 1800: no state holds the server in another time longer), and the guard's own TERM trap. A set clock is in
# the fingerprint (`clock-set` line) until restored.
#
# States: TM-UTC-BROKEN TM-DST TM-CLOCK-BACK TM-YEAR-ROLL. Notes: $KEEP/state-<id>.env (CLOCK_SET=epoch, TZ_FILE=…).

ST_IDENT=/boot/config/ident.cfg
declare -F _st_fp_add >/dev/null || _st_fp_add() { [[ " ${ST_FP_EXTRA[*]-} " == *" $1 "* ]] || ST_FP_EXTRA+=("$1"); }
declare -F _st_keep_env >/dev/null || _st_keep_env() {
    local f=${KEEP:-/tmp/uso-keep/states}/state-${1,,}.env kv; shift
    mkdir -p "$(dirname "$f")"
    for kv in "$@"; do printf '%s\n' "$kv" >> "$f"; done
}
_st_fp_time() {
    echo "## time"
    echo "localtime $(readlink /etc/localtime)"
    echo "ident-tz $(tr -d '\r' < "$ST_IDENT" 2>/dev/null | sed -n 's/^timeZone=//p')"
    ls /tmp/uso-clock-*.set 2>/dev/null | sed 's/^/clock-set /'
    pgrep -f '[u]so-clock-guard.sh' >/dev/null && echo "clock guard running"
}
_st_fp_add _st_fp_time

_st_uptime_cs() { local u; read -r u _ < /proc/uptime; echo $((10#${u/./})); }      # centiseconds since boot

# the guard: <note file> <pid to watch> <max seconds> — restores the clock from the note when the pid is gone or the time
# is up, unless the note went first (clock_restore removes it before it kills the guard)
_ST_CLOCK_GUARD='
note=$1 pid=$2 max=$3
put() {
    [[ -f $note ]] || exit 0
    local t0 u0 ntp u now
    read -r t0 u0 ntp < "$note"
    read -r u _ < /proc/uptime; u=$((10#${u/./}))
    now=$((t0 + (u - u0) * 10000000))
    date -s "@${now:0:-9}.${now: -9}" >/dev/null
    [[ $ntp == yes ]] && /etc/rc.d/rc.ntpd start >/dev/null 2>&1
    rm -f "$note"
    logger -t uso-clock-guard "clock put back from the uptime note"
    exit 0
}
trap put TERM INT
end=$(( $(read -r u _ < /proc/uptime; echo ${u%.*}) + max ))
while [[ -f $note ]]; do
    kill -0 "$pid" 2>/dev/null || put
    read -r u _ < /proc/uptime; ((${u%.*} >= end)) && put
    sleep 2 & wait $!
done'

clock_set() {
    local id=$1 when=$2 target note ntp=no
    note=/tmp/uso-clock-${id,,}.set
    [[ -f $note ]] && { say "clock_set: the clock is set already for $id"; return 1; }
    target=$(date -d "$when" +%s 2>/dev/null) || { say "clock_set: no date «$when»"; return 1; }
    pgrep -x ntpd >/dev/null && ntp=yes
    [[ $ntp == yes ]] && _st_clean /etc/rc.d/rc.ntpd stop >/dev/null 2>&1
    _st_until 10 bash -c '! pgrep -x ntpd' || { say "clock_set: ntpd still runs"; return 1; }
    printf '%s %s %s\n' "$(date +%s%N)" "$(_st_uptime_cs)" "$ntp" > "$note"
    printf '%s\n' "$note" > "$(_st_dir "$id")/clock.note"
    printf '%s\n' "$_ST_CLOCK_GUARD" > /tmp/uso-clock-guard.sh
    _st_bg bash /tmp/uso-clock-guard.sh "$note" "${USO_SC_PID:-$$}" "${USO_CLOCK_MAX:-1800}" > "$(_st_dir "$id")/clock.guard"
    date -s "@$target" >/dev/null || { clock_restore "$id"; return 1; }
    _st_keep_env "$id" CLOCK_SET="$target" CLOCK_SET_AT="$(cut -d' ' -f1 "$note" | cut -c1-10)"
    say "clock_set: the clock at $(date '+%F %T %Z') (ntpd stopped; real time noted, guard $(cat "$(_st_dir "$id")/clock.guard"))"
}
clock_restore() {
    local id=$1 dir note t0 u0 ntp now
    dir=$(_st_dir "$id")
    [[ -f $dir/clock.note ]] || return 0
    note=$(cat "$dir/clock.note")
    if [[ -f $note ]]; then
        read -r t0 u0 ntp < "$note"
        now=$((t0 + ($(_st_uptime_cs) - u0) * 10000000))
        date -s "@${now:0:-9}.${now: -9}" >/dev/null || return 1
        rm -f "$note"
        [[ $ntp == yes ]] && _st_clean /etc/rc.d/rc.ntpd start >/dev/null 2>&1
        say "clock_restore: the clock back at $(date '+%F %T %Z')$( [[ $ntp == yes ]] && echo ', ntpd started')"
    else
        say "clock_restore: the guard put the clock back already"
    fi
    # the office's own at jobs handed to atd «now» under the clock set ahead wait for that moment once it is back
    # (restore-partner-look, partner-ping, --kopia-watch: 2026-10-22, three for «Sun Oct 25 02:58» after the DST cell) —
    # the state's leftovers: removed (a minor product finding of its own, README «Full run»)
    local j at_t lim=$(( $(date +%s) + 600 ))
    for j in $(atq 2>/dev/null | awk '{print $1}'); do
        at -c "$j" 2>/dev/null | grep -q 'unraid-secretary-office' || continue
        at_t=$(date -d "$(atq 2>/dev/null | awk -v j="$j" '$1 == j {print $2, $3, $4, $5, $6}')" +%s 2>/dev/null) || continue
        ((at_t > lim)) && atrm "$j" 2>/dev/null && say "clock_restore: the office's at job $j for $(date -d "@$at_t" '+%F %T') (queued under the clock set ahead) removed"
    done
    [[ -f $dir/clock.guard ]] && kill "$(cat "$dir/clock.guard")" 2>/dev/null
    pkill -f "[u]so-clock-guard.sh $note" 2>/dev/null
    rm -f "$dir/clock.note" "$dir/clock.guard"
    ls /tmp/uso-clock-*.set >/dev/null 2>&1 || rm -f /tmp/uso-clock-guard.sh
    _st_until 15 pgrep -x ntpd || [[ ${ntp:-no} != yes ]] || { say "clock_restore: ntpd doesn't run"; return 1; }
}

tz_break() {
    local id=$1 zone=${2:-Europe/Zurich} dir; dir=$(_st_dir "$id")
    [[ -e /usr/share/zoneinfo/$zone ]] && { say "tz_break: /usr/share/zoneinfo/$zone exists here — not a broken zone"; return 1; }
    [[ -f $dir/localtime ]] || readlink /etc/localtime > "$dir/localtime"
    [[ -f $dir/ident.cfg ]] || cp -p "$ST_IDENT" "$dir/ident.cfg" || return 1
    ln -sfn "/usr/share/zoneinfo/$zone" /etc/localtime || return 1
    if grep -q '^timeZone=' "$ST_IDENT"; then
        sed -i "s|^timeZone=\"[^\"]*\"|timeZone=\"$zone\"|" "$ST_IDENT"
    fi
    _st_keep_env "$id" TZ_FILE="/usr/share/zoneinfo/$zone" TZ_NAME="$zone" TZ_ABBR="$(date +%Z)"
    say "tz_break: /etc/localtime → $zone (missing) — the server shows $(date +%Z)"
}
tz_fix() {
    local dir; dir=$(_st_dir "$1")
    [[ -f $dir/localtime ]] && { ln -sfn "$(cat "$dir/localtime")" /etc/localtime && rm -f "$dir/localtime" || return 1; }
    [[ -f $dir/ident.cfg ]] && { cp -p "$dir/ident.cfg" "$ST_IDENT" && rm -f "$dir/ident.cfg" || return 1; }
    return 0
}

# ------------------------------------------------------------------------------------------------------------ states
_st_zone() { local z; z=$(readlink /etc/localtime); [[ -e $z ]] && printf '%s' "${z#/usr/share/zoneinfo/}"; }

# TM-UTC-BROKEN [zone] — the zone file is missing, the server runs UTC (USOTest3VM's base: then only looked at)
state_tm_utc_broken() {
    if [[ ! -e $(readlink /etc/localtime) ]]; then
        say "TM-UTC-BROKEN: the base is so already (/etc/localtime → $(readlink /etc/localtime), missing)"
        _st_keep_env TM-UTC-BROKEN TZ_FILE="$(readlink /etc/localtime)" TZ_ABBR="$(date +%Z)" BASE=yes
        return 0
    fi
    tz_break TM-UTC-BROKEN "${1:-Europe/Zurich}"
}
undo_tm_utc_broken() { tz_fix TM-UTC-BROKEN; }

# TM-DST [autumn|spring] [zone] [minutes=2] — <minutes> before the change of the time zone's next switch on 2026-10-25
# (03:00 → 02:00, the 02:xx hour twice: 20 = 02:40 CEST, inside the repeated hour's first time) or 2027-03-28 (02:00 →
# 03:00, no 02:xx): the clock runs on, ntpd stopped. A server whose zone file is missing (TM-UTC-BROKEN — USOTest3VM's
# base) gets <zone> (e.g. Europe/Berlin) linked as /etc/localtime for the state's time — ident.cfg stays as Unraid's
# form wrote it; the undo links the old one back
state_tm_dst() {
    local which=${1:-autumn} zone=${2:-} mins=${3:-2} z at sw dir; dir=$(_st_dir TM-DST)
    [[ $mins =~ ^[1-9][0-9]?$ ]] && ((mins <= 59)) || { say "TM-DST: minutes 1-59 before the switch, not $mins"; return 1; }
    z=$(_st_zone)
    if [[ -z $z && -n $zone ]]; then
        [[ -e /usr/share/zoneinfo/$zone ]] || { say "TM-DST: no zone file $zone here"; return 2; }
        [[ -f $dir/localtime ]] || readlink /etc/localtime > "$dir/localtime"
        ln -sfn "/usr/share/zoneinfo/$zone" /etc/localtime || return 1
        z=$zone
        say "TM-DST: the server's zone file is missing — $zone linked for the state"
    fi
    [[ -n $z ]] || { say "needs a time zone file (this server runs UTC: no DST)"; return 2; }
    case $which in
        autumn) sw=$(date -d '2026-10-25 01:00:00 UTC' +%s) ;;
        spring) sw=$(date -d '2027-03-28 01:00:00 UTC' +%s) ;;
        *) say "TM-DST: autumn|spring, not $which"; return 1 ;;
    esac
    at=@$((sw - mins * 60))
    # a zone without that switch (UTC, …): nothing to cross
    [[ $(TZ=$z date -d "@$((sw - 60))" +%Z) != $(TZ=$z date -d "@$sw" +%Z) ]] || { say "needs a zone with DST ($z has no switch then)"; return 2; }
    clock_set TM-DST "$at" || return 1
    _st_keep_env TM-DST DST=$which ZONE="$z" SWITCH_AT="$sw"
}
undo_tm_dst() {
    local dir; dir=$(_st_dir TM-DST)
    clock_restore TM-DST || return 1
    [[ -f $dir/localtime ]] || return 0
    ln -sfn "$(cat "$dir/localtime")" /etc/localtime && rm -f "$dir/localtime"
}

# TM-CLOCK-BACK [hours=2] — the clock jumped back (an NTP correction after a boot without RTC)
state_tm_clock_back() { clock_set TM-CLOCK-BACK "@$(( $(date +%s) - ${1:-2} * 3600 ))"; }
undo_tm_clock_back() { clock_restore TM-CLOCK-BACK; }

# TM-YEAR-ROLL [year=2026] — two minutes before New Year (local time): retention by month and year
state_tm_year_roll() { clock_set TM-YEAR-ROLL "${1:-2026}-12-31 23:58:00"; }
undo_tm_year_roll() { clock_restore TM-YEAR-ROLL; }
