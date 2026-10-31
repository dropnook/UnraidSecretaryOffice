# Unraid Secretary Office - the notification centre's gate for the bash senders: the engine's ub_notify (lib/common.sh)
# and the plugin's scripts/agent.sh (tell). Sourced; it only defines.
#
# The Team Lead's «Meldungen» are the office's one place for what goes to Unraid's notifications. Every sender asks the
# same decision before it calls Unraid's notify script - send now, hold for the summary, count only - the PHP side's
# officeNotifyGate() in agent/lib/notifycenter.php is the same decision (tests/run.php runs both over the same cases):
#   * the settings: the key=value file the office's agent writes (<home>/notify.cfg; none = everything goes, as before
#     the centre): the desk's threshold (all|warning|alert), its successes (backup ok), «Still bis …», quiet hours,
#     the desk on the daily summary. An alert always goes.
#   * the flood guard: the same notification (desk + event + subject) once, then only counted until resolved (a success
#     of that desk, a line after it in the guard) or a day passed; caps per desk and for the whole office per hour - past
#     one, ONE notice and the rest collected for a summary. An alert is never folded into «the same» (2.74, the truth
#     review T01: a second «Backup failed» within a day was swallowed): only the very same alert - its key also covers
#     its description - again within NOTIFY_ALERT_SAME is counted; past NOTIFY_ALERT_CAP alerts an hour the rest go
#     as one alert every NOTIFY_ALERT_BUNDLE. 2.80 (the rest of T01): a run's failure (ung_fail_same: «Backup failed»,
#     «Backup not started») is the very same again - the same cause, its key covering the description - within
#     NOTIFY_FAILED_SAME: counted, with how many times (UNG_TIMES, the decision's «times»), until a success resolves
#     it; another cause always goes. Every counted one leaves a «counted» line (ung_drop) - the next one's count.
#     Its lines in <home>/notify-guard, read and written only under the flock of <home>/notify.second the sender holds
#     around its call (so the decision and the call are one turn).
#   * each decision one small JSON in <home>/notify-in/ (the agent folds them into the Team Lead's queue and history).
# Hold only while someone will send the summary: the agent's heartbeat (<home>/agent.json) at most 70 s old.
#
# The numbers - the same names and values as in agent/lib/notifycenter.php (tests/run.php compares them):
NOTIFY_SAME_FORGET=72000    # an unresolved repeat may go out again after 20 h (2.80, a day up to 2.79: a daily failure a few
                            # seconds earlier than the day before was counted - it reminded every other day; now daily)
NOTIFY_ALERT_SAME=60        # an alert: only the very same one again within a minute is counted (never a day)
NOTIFY_FAILED_SAME=72000    # 2.80: a run's very same failure again within 20 h is counted (a daily schedule still reminds daily)
NOTIFY_CAP_WINDOW=3600      # the caps count the last hour
NOTIFY_DESK_CAP=5           # more than this from one character in an hour is a flood
NOTIFY_OFFICE_CAP=20        # more than this from the whole office in an hour is a flood
NOTIFY_ALERT_CAP=10         # alerts in an hour before the rest are bundled
NOTIFY_ALERT_BUNDLE=900     # a bundle of the rest at most every 15 minutes
NOTIFY_HEART_FRESH=70       # the agent's heartbeat at most this old: it will send the summary
NOTIFY_DROPS_MAX=500        # decisions waiting for the agent at most - past it nothing is held (it goes now)
NOTIFY_GUARD_MAX=3000       # lines of the guard before the old ones go

# ung_cfg_read <file> - the agent's key=value file into UNG_CFG; 1 when there is none (then everything goes as before)
ung_cfg_read() {
    declare -gA UNG_CFG=()
    local f="$1" line
    [[ -n "$f" && -f "$f" && ! -L "$f" ]] || return 1
    (( $(stat -c %s -- "$f" 2>/dev/null || echo 99999) <= 16384 )) || return 1
    while IFS= read -r line || [[ -n "$line" ]]; do
        if [[ "$line" =~ ^([a-z][a-z_]{0,30}(\.[a-z_]{1,20})?)=([A-Za-z0-9_-]{0,24})$ ]]; then
            UNG_CFG["${BASH_REMATCH[1]}"]="${BASH_REMATCH[3]}"
        fi
    done <"$f"
    [[ "${UNG_CFG[v]:-}" == 1 ]] || { UNG_CFG=(); return 1; }
    return 0
}

ung_rank() { case "$1" in alert) echo 2 ;; warning) echo 1 ;; *) echo 0 ;; esac; }

# ung_is_success <desk> <event> - a success (OFFICE_NOTIFY_SUCCESS in notifycenter.php): the desk's «auch Erfolge»
ung_is_success() { case "$1:$2" in backup:ok|jack:release_ok) return 0 ;; esac; return 1; }
# ung_resolves <desk> <event> - it resolves what that desk said went wrong (OFFICE_NOTIFY_RESOLVES): a success, the agent back
ung_resolves() { case "$1:$2" in backup:ok|jack:release_ok|office:agent_back) return 0 ;; esac; return 1; }

# ung_fail_same <desk> <event> - a run's failure, folded when the very same one comes again (OFFICE_NOTIFY_FAIL_SAME)
ung_fail_same() { case "$1:$2" in backup:failed|backup:refused) return 0 ;; esac; return 1; }

# ung_quiet_now <epoch> - inside the quiet hours (quiet=HHMM-HHMM; across midnight when from > to)
ung_quiet_now() {
    local q="${UNG_CFG[quiet]:-off}" now from to
    [[ "$q" =~ ^([0-2][0-9][0-5][0-9])-([0-2][0-9][0-5][0-9])$ ]] || return 1
    from=$((10#${BASH_REMATCH[1]})); to=$((10#${BASH_REMATCH[2]}))
    now=$((10#$(date -d "@$1" +%H%M)))
    (( from == to )) && return 1
    if (( from < to )); then (( now >= from && now < to )) && return 0; return 1; fi
    (( now >= from || now < to )) && return 0
    return 1
}

# ung_can_hold <home> - will someone send the summary? The agent's heartbeat fresh, and room for the decision
ung_can_hold() {
    local beat n
    beat="$(stat -c %Y -- "$1/agent.json" 2>/dev/null || echo 0)"
    (( $(date +%s) - beat <= NOTIFY_HEART_FRESH )) || return 1
    ung_drops_room "$1"
}
ung_drops_room() {
    local f n=0
    [[ -d "$1/notify-in" && ! -L "$1/notify-in" ]] || return 0
    for f in "$1"/notify-in/*.json; do [[ -e "$f" ]] && n=$((n + 1)); done
    (( n < NOTIFY_DROPS_MAX ))
}

# ung_decide <home> <desk> <event> <level> <key> <now>
# -> UNG_FATE sent|held|counted|collected|dropped, UNG_WHY, UNG_DUE (a collected one's summary), UNG_NOTICE ('' or the
#    desk - or * for the whole office - whose «reporting a lot» notice goes now), UNG_TIMES (a counted one: the how-many-th
#    time it came since it went, else 0)
ung_decide() {
    local home="$1" desk="$2" event="$3" level="$4" key="$5" now="$6" need hold=0 why="" su lv
    UNG_FATE=sent; UNG_WHY=""; UNG_DUE=0; UNG_NOTICE=""; UNG_TIMES=0
    if ung_can_hold "$home"; then hold=1; fi
    # the settings (none: as before the centre)
    if [[ "$level" != alert ]] && ung_cfg_read "$home/notify.cfg"; then
        lv="${UNG_CFG[$desk.level]:-all}"
        case "$lv" in warning) need=1 ;; alert) need=2 ;; *) need=0 ;; esac
        if (( $(ung_rank "$level") < need )); then UNG_FATE=dropped; UNG_WHY=threshold; return 0; fi
        if ung_is_success "$desk" "$event" && [[ "${UNG_CFG[$desk.success]:-1}" == 0 ]]; then UNG_FATE=dropped; UNG_WHY=success; return 0; fi
        su="${UNG_CFG[silent_until]:-0}"
        if [[ "$su" =~ ^[0-9]{1,12}$ ]] && (( su > now )); then why=silent
        elif ung_quiet_now "$now"; then why=quiet
        elif [[ "${UNG_CFG[$desk.when]:-now}" == summary ]]; then why=summary; fi
        if [[ -n "$why" ]]; then
            if (( hold )); then UNG_FATE=held; UNG_WHY="$why"; return 0; fi
            UNG_WHY=no_sender       # nobody would send the summary: it goes now
        fi
    fi
    ung_guard "$home/notify-guard" "$desk" "$level" "$key" "$now" "$hold" "$event"
    return 0
}

# ung_guard <file> <desk> <level> <key> <now> <hold> [<event>] - the flood guard for what would go now
ung_guard() {
    local g="$1" desk="$2" level="$3" key="$4" now="$5" hold="$6" event="${7:-}" out same times dk off al fd fo room=0 fw="$NOTIFY_SAME_FORGET"
    [[ -n "$g" && ! -L "$g" && -d "${g%/*}" ]] || return 0
    [[ -f "$g" ]] || return 0
    if ung_drops_room "${g%/*}"; then room=1; fi
    if ung_fail_same "$desk" "$event"; then fw="$NOTIFY_FAILED_SAME"      # 2.80: a run's very same failure within 20 h
    elif [[ "$level" == alert ]]; then fw="$NOTIFY_ALERT_SAME"; fi      # an alert: only the very same one within a minute
    # same: when the very same one last went (0: not within fw, or resolved since); times: it and the counted ones after it
    out="$(awk -v d="$desk" -v k="$key" -v now="$now" -v fw="$fw" -v w="$NOTIFY_CAP_WINDOW" '
        $1 !~ /^[0-9]+$/ { next }
        $2 == "sent" && $5 == k { if (now - $1 < fw) { same = $1; times = 1 } else { same = 0; times = 0 } }
        $2 == "counted" && $5 == k && same > 0 { times++ }
        $2 == "resolved" && $3 == d { same = 0; times = 0 }
        $2 == "sent" && $4 != "alert" && now - $1 < w { off++; if ($3 == d) dk++ }
        $2 == "sent" && $4 == "alert" && now - $1 < w { al++ }
        $2 == "flood" && now - $1 < w { if ($3 == d) fd = $1; if ($3 == "*") fo = $1 }
        END { printf "%d %d %d %d %d %d %d\n", same, times, dk, off, al, fd, fo }' "$g" 2>/dev/null)"
    read -r same times dk off al fd fo <<<"${out:-0 0 0 0 0 0 0}"
    if (( same > 0 )); then UNG_FATE=counted; UNG_WHY=same; UNG_TIMES=$(( times + 1 )); return 0; fi
    if [[ "$level" == alert ]]; then
        # an alert is never lost: bundled only while someone sends the bundle
        if (( al >= NOTIFY_ALERT_CAP && hold )); then UNG_FATE=collected; UNG_WHY=alert_cap; fi
        return 0
    fi
    (( room )) || return 0              # no room to keep it: it goes
    if (( dk >= NOTIFY_DESK_CAP )); then
        UNG_FATE=collected; UNG_WHY=flood
        if (( fd > 0 )); then UNG_DUE=$((fd + NOTIFY_CAP_WINDOW)); else UNG_DUE=$((now + NOTIFY_CAP_WINDOW)); UNG_NOTICE="$desk"; fi
    elif (( off >= NOTIFY_OFFICE_CAP )); then
        UNG_FATE=collected; UNG_WHY=flood
        if (( fo > 0 )); then UNG_DUE=$((fo + NOTIFY_CAP_WINDOW)); else UNG_DUE=$((now + NOTIFY_CAP_WINDOW)); UNG_NOTICE='*'; fi
    fi
    return 0
}

# ung_line <home> <fate> <desk> <level> <key> - one line of the guard (sent, flood, resolved, counted); the old ones go past the max
ung_line() {
    local g="$1/notify-guard" n tmp
    [[ -d "$1" && ! -L "$g" ]] || return 0
    ( umask 077; printf '%s %s %s %s %s\n' "$(date +%s)" "$2" "$3" "$4" "${5:--}" >>"$g" ) 2>/dev/null
    n="$(wc -l <"$g" 2>/dev/null || echo 0)"
    if (( n > NOTIFY_GUARD_MAX )); then
        tmp="$g.$$.tmp"
        awk -v old="$(( $(date +%s) - 2 * NOTIFY_SAME_FORGET ))" '$1 ~ /^[0-9]+$/ && $1 > old' "$g" >"$tmp" 2>/dev/null && mv -f -- "$tmp" "$g"
        rm -f -- "$tmp"
    fi
    return 0
}

# ung_key <desk> <event> <subject> [<level> <description> [<codes>]] - «the same notification»: 16 hex of md5 over
# desk|event|subject (as in PHP); an alert's over desk|event|subject|description (2.74: only the very same alert is «the
# same»); a warning that names its causes (2.85: the run's item codes, sorted, «,» between) over desk|event|subject|codes -
# another set of causes within 20 h is a new warning, not a counted one
ung_key() {
    if [[ "${4:-}" == alert ]]; then printf '%s|%s|%s|%s' "$1" "$2" "$3" "${5:-}" | md5sum | cut -c1-16
    elif [[ "${4:-}" == warning && -n "${6:-}" ]]; then printf '%s|%s|%s|%s' "$1" "$2" "$3" "$6" | md5sum | cut -c1-16
    else printf '%s|%s|%s' "$1" "$2" "$3" | md5sum | cut -c1-16; fi
}

# ung_drop <home> <fate> <why> <due> <desk> <event> <level> <key> <subject> <description> <message> <link> [<times>] - the
# decision for the Team Lead's queue and history (a JSON of its own; written beside, then moved in). 2.80: a counted one
# carries «times» (default UNG_TIMES) and leaves its «counted» line in the guard - the next one's count
ung_drop() {
    local dir="$1/notify-in" id now us tmp times="${13:-${UNG_TIMES:-0}}"
    [[ -d "$1" ]] || return 0
    [[ "$times" =~ ^[0-9]{1,6}$ ]] || times=0
    [[ "$2" == counted ]] && ung_line "$1" counted "$5" "$7" "$8"
    [[ -L "$dir" ]] && return 0
    [[ -d "$dir" ]] || ( umask 077; mkdir -p -- "$dir" ) 2>/dev/null || return 0
    command -v jq >/dev/null 2>&1 || return 0
    us="$(date +%s%N)"; us="${us:0:16}"        # the name sorts by the microsecond it was decided in
    [[ "$us" =~ ^[0-9]{16}$ ]] || us="$(date +%s)000000"
    now="${us:0:10}"
    id="$(od -An -N6 -tx1 /dev/urandom 2>/dev/null | tr -d ' \n')"
    [[ "$id" =~ ^[0-9a-f]{12}$ ]] || id="$$$RANDOM"
    tmp="$dir/.$us-$id.tmp"
    if ( umask 077; jq -n --arg id "$id" --argjson time "$now" --arg fate "$2" --arg why "$3" --argjson due "${4:-0}" --arg desk "$5" \
            --arg event "$6" --arg level "$7" --arg key "$8" --arg subject "$9" --arg description "${10}" --arg message "${11:0:4096}" \
            --arg link "${12}" --argjson times "$times" '{v: 1, id: $id, time: $time, desk: $desk, event: $event, level: $level, fate: $fate, why: $why,
            due: $due, key: $key, subject: $subject, description: $description, message: $message, link: $link}
            + (if $times > 0 then {times: $times} else {} end)' >"$tmp" ) 2>/dev/null; then
        mv -f -- "$tmp" "$dir/$us-$id.json"
    fi
    rm -f -- "$tmp"
    return 0
}
