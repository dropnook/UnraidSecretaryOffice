#!/bin/bash
# tools/fanboy/vm-run.sh — Fanboy's whole run on test3 against vm-rig.sh's chip, through the plugin's own mailbox
# (tools/scenarios/ask.php: what the page's buttons send). Dev only. Runs ON test3 from a copy of the tree, the rig up.
#
#   bash vm-run.sh <phase>…     setup steer safety alarms round4 chip renumber other drivers leave   (in this order)
#                               boot-pre / boot-post (the reboot case: the Mac reboots test3 between them)
#   bash vm-run.sh report       every phase's table into /tmp/uso-fanboy-vmrun/report.md
#
# Each phase writes /tmp/uso-fanboy-vmrun/<phase>/: report.md (a table: check · PASS/FAIL/info · evidence), log.txt (every
# request and answer, every value seen, with times), state-*.json (the desk's state at named moments), pwm-*.txt (the
# outputs sampled: time p<n>=value/enable/rpm), agent.log lines of the phase. Expected values are the rig's fans
# (vm-responder.php) and the zones saved in «setup».
set -uo pipefail
HERE=$(cd "$(dirname "$0")" && pwd -P)
TREE=$(cd "$HERE/../.." && pwd -P)
P=/usr/local/emhttp/plugins/unraid-secretary-office
D=$(sed -n 's/^DATA_DIR="\(.*\)"$/\1/p' /boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg 2>/dev/null)
D=${D:-/mnt/user/appdata/UnraidSecretaryOffice/data}
FB=/var/run/unraid-secretary-office/fanboy
RIGDIR=/tmp/uso-fanboy-rig
ROOT=/tmp/uso-fanboy-vmrun
CHIP=it8696_a0060607
RIG() { bash "$HERE/vm-rig.sh" "$@"; }
ASKP="$TREE/tools/scenarios/ask.php"

# ---------------------------------------------------------------------------------------------------------- helpers
ts() { date +%H:%M:%S.%2N; }
say() { echo "$(ts) $*" | tee -a "$OUT/log.txt" >&2; }
ask() { local a=$1 j=${2:-'{}'} r; r=$(php "$ASKP" --wait 90 "$a" "$j" 2>&1); echo "$(ts) ask $a $j → ${r:0:3000}" >> "$OUT/log.txt"; printf '%s\n' "$r"; }
state() { php "$ASKP" --raw fanboy.refresh 2>/dev/null | jq '.state' > "$OUT/state-$1.json"; }
sj() { jq -r "$2" "$OUT/state-$1.json"; }
row() { printf '| %s | %s | %s |\n' "$1" "$2" "${3//|/\\|}" >> "$OUT/report.md"; }
check() { local n=$1 ok=$2; shift 2; local w=PASS; [[ $ok == 1 ]] || w=FAIL; row "$n" "$w" "$*"; say "CHECK $w $n — $*"; }
# a check of a finding known and planned (round 5, 2026-11-15): kept as it is, a FAIL marked so it isn't counted as new
kcheck() { local n=$1 ok=$2; shift 2; local w=PASS; [[ $ok == 1 ]] || w='FAIL (known, round 5)'; row "$n" "$w" "$*"; say "CHECK $w $n — $*"; }
info() { row "$1" info "$2"; say "INFO $1 — $2"; }
ok() { "$@" >/dev/null 2>&1 && echo 1 || echo 0; }
addr() { jq -r '.addr // "pcspkr"' "$RIGDIR/layout.json" 2>/dev/null || echo pcspkr; }
F() { echo "$CHIP:$(addr):pwm$1"; }
chipdir() { local a n; a=$(jq -r .addr "$RIGDIR/layout.json" 2>/dev/null); n=$(jq -r .it87 "$RIGDIR/layout.json" 2>/dev/null); echo "/sys/devices/platform/$a/hwmon/hwmon$n"; }
reg() { local h; h=$(chipdir); echo "$(cat "$h/pwm$1" 2>/dev/null || echo -)/$(cat "$h/pwm${1}_enable" 2>/dev/null || echo -)"; }
rpm() { cat "$(chipdir)/fan$1_input" 2>/dev/null || echo -; }
regs() { local i s=''; for i in 1 2 3 4 5 6; do s+="p$i=$(reg $i) "; done; echo "${s% }"; }
logmark() { wc -l < "$D/agent.log" > "$OUT/.mark-$1"; }
logsince() { tail -n +$(( $(cat "$OUT/.mark-$1") + 1 )) "$D/agent.log"; }
nlist() { ls /tmp/notifications/unread /tmp/notifications/archive 2>/dev/null | grep '\.notify$' | LC_ALL=C sort -u; }
nmark() { nlist > "$OUT/.notify-$1"; }
# Fanboy's notifications since a mark (his link #/fanboy): «subject | description | importance» one per line
nsince() {
    local f g
    comm -13 "$OUT/.notify-$1" <(nlist) | while read -r f; do
        # Unraid's archive copy carries no link: the unread one first; his subjects begin «…: Fanboy»
        g=/tmp/notifications/unread/$f; [[ -f $g ]] || g=/tmp/notifications/archive/$f
        grep -q '^subject=".*: Fanboy' "$g" || continue
        printf '%s | %s | %s\n' "$(sed -n 's/^subject="\(.*\)"$/\1/p' "$g")" "$(sed -n 's/^description="\(.*\)"$/\1/p' "$g")" "$(sed -n 's/^importance="\(.*\)"$/\1/p' "$g")"
    done
}
ctl() { jq -r "$1" "$FB/control.json" 2>/dev/null; }                 # the control's own status (RAM) — no look at the chip
ctlpid() { local r; r=$(cat "$FB/control.pid" 2>/dev/null); echo "${r%% *}"; }
# until <seconds> <cmd…>: polls every 0.2 s; prints the seconds it took (or «timeout»), 0/1 exit
until_() {
    local max=$1 t0; shift; t0=$(date +%s.%N)
    while :; do
        "$@" >/dev/null 2>&1 && { awk -v a="$t0" -v b="$(date +%s.%N)" 'BEGIN{printf "%.1f\n", b-a}'; return 0; }
        awk -v a="$t0" -v b="$(date +%s.%N)" -v m="$max" 'BEGIN{exit !(b-a>m)}' && { echo timeout; return 1; }
        sleep 0.2
    done
}
is_reg() { [[ $(reg "$1") == "$2" ]]; }
steering() { [[ $(ctl .who) == fanboy && -n $(ctlpid) ]] && kill -0 "$(ctlpid)" 2>/dev/null; }
sampler_start() { (cd / && exec setsid bash "$HERE/vm-rig.sh" watch 100000 "${2:-0.5}" </dev/null > "$OUT/pwm-$1.txt" 2>/dev/null) & echo $! > "$OUT/.sampler"; }
sampler_stop() { local p; p=$(cat "$OUT/.sampler" 2>/dev/null); [[ -n $p ]] && { pkill -P "$p" 2>/dev/null; kill "$p" 2>/dev/null; pkill -f "vm-rig.sh watch 100000" 2>/dev/null; }; rm -f "$OUT/.sampler"; }
step_wait() {   # <alive 0|1> <max s>: polls the step until done/stopped/failed; the page's step_alive every 10 s when alive
    local alive=$1 max=$2 t0=$SECONDS last=$SECONDS st s
    while :; do
        st=$(php "$ASKP" --raw fanboy.refresh 2>/dev/null | jq -c '.state.step | {status, why, current, done: (.done | length)}')
        echo "$(ts) step $st $(regs)" >> "$OUT/log.txt"
        s=$(jq -r .status <<<"$st")
        [[ $s == done || $s == stopped || $s == failed ]] && break
        if ((alive && SECONDS - last >= 10)); then php "$ASKP" fanboy.step_alive >/dev/null 2>&1; last=$SECONDS; fi
        ((SECONDS - t0 > max)) && { say "step_wait: still $s after $max s"; break; }
        sleep 2
    done
    echo $((SECONDS - t0))
}
php_errors() { logsince "$1" | grep -E 'PHP (Fatal|Warning|Notice|Deprecated)|Uncaught|TypeError' | grep -v -i 'expected' | head -5; }
end_phase() {
    sampler_stop
    logsince phase > "$OUT/agent-log.txt"
    local e; e=$(php_errors phase)
    [[ -z $e ]] && check "$PHASE: no PHP error" 1 "agent.log of the phase has no PHP warning/error" || check "$PHASE: no PHP error" 0 "$e"
}
begin_phase() {
    PHASE=$1; OUT=$ROOT/$1; rm -rf "$OUT"; mkdir -p "$OUT"
    printf '\n## %s (%s)\n\n| check | result | evidence |\n|---|---|---|\n' "$1" "$(date -Is)" > "$OUT/report.md"
    logmark phase; nmark phase
    say "=== phase $1 — rig: $(jq -c . "$RIGDIR/layout.json" 2>/dev/null) — $(regs)"
}
# the zones every phase relies on (also used again after a renumbering)
ZONES() {
    local a=$(addr) B="$CHIP@platform/$(addr)"
    jq -nc --arg f2 "$(F 2)" --arg f3 "$(F 3)" --arg f4 "$(F 4)" --arg f5 "$(F 5)" --arg b "$B" '{zones: [
      {id: "disks", name: "Disk cage", fans: [$f4], unit: "pct", target: {preset: "normal"},
       sources: [{id: "parity"}, {id: "disk1"}, {id: "disk2"}, {id: ($b + "#1")}]},
      {id: "case", name: "Case", fans: [$f2, $f3], unit: "pct", target: {points: [[45, 25], [60, 50], [75, 100]]},
       sources: [{id: "coretemp@platform/intel_rapl_msr.0#1"}, {id: "nvme@pci0000:00/0000:00:11.0/nvme#1", limit: 70}, {id: ($b + "#2")}]},
      {id: "rear", name: "Rear", fans: [$f5], unit: "rpm", target: {points: [[35, 400], [50, 700], [60, 1000]]},
       sources: [{id: ($b + "#3")}]}]}'
}
calm() { RIG knob cpu 45 >/dev/null; RIG knob nvme 42 >/dev/null; RIG knob board1 35 >/dev/null; RIG knob board2 33 >/dev/null; RIG knob board3 38 >/dev/null
         local k; for k in stand1 stand2 stand4 stand5 quiet_nvme quiet_board3 quiet_cpu; do RIG knob $k 0 >/dev/null; done; RIG knob scale5 1 >/dev/null; }
take() { ask fanboy.take_over >/dev/null; until_ 30 steering >/dev/null; }

# ---------------------------------------------------------------------------------------------------------- setup
phase_setup() {
    begin_phase setup
    if [[ ${FRESH:-0} == 1 ]]; then     # a setup from the start: his settings of an earlier run put aside (never deleted)
        local f; for f in settings.json initial.json; do [[ -f $D/fanboy/$f ]] && mv "$D/fanboy/$f" "$D/fanboy/$f.vmrun-$(date +%s)"; done
        info "fresh" "earlier settings put aside: $(ls "$D/fanboy" | tr '\n' ' ')"
    fi
    if [[ ${PATCH_WARM:-0} == 1 ]]; then   # test3 only, never the repo: NVMe pools (rotational=0) left out of «too warm to measure»
        sed -i "s/        if (!\$d\['asleep'\] && \$d\['temp'\] !== null && \$d\['temp'\] >= FANBOY_SOURCE_DEFAULTS\['disk'\]\['limit'\] - 5) {/        if (\$d['rotational'] \&\& !\$d['asleep'] \&\& \$d['temp'] !== null \&\& \$d['temp'] >= FANBOY_SOURCE_DEFAULTS['disk']['limit'] - 5) {   \/\/ VMRIG PATCH/" "$P/agent/desks/fanboy-setup.php"
        info "patched on test3" "$(grep -n 'VMRIG PATCH' "$P/agent/desks/fanboy-setup.php" | head -c 300)"
        sleep 8
    fi
    calm; sleep 2
    state before
    local before; before=$(regs)
    check "detect: chip found" "$( [[ $(sj before .situation.code) == ok && $(sj before .counts.outputs) == 6 ]] && echo 1 || echo 0)" \
        "situation $(sj before .situation.code), $(sj before .counts.outputs) outputs, chip $(sj before "[.chips[] | select(.name == \"$CHIP\") | {hwmon, device, module}] | tostring"); regs $before"
    local all; all=$(jq -nc --args '{fans: $ARGS.positional}' "$(F 1)" "$(F 2)" "$(F 3)" "$(F 4)" "$(F 5)" "$(F 6)")
    sampler_start test_up 0.5
    local r t; r=$(ask fanboy.test_up "$all")
    check "test_up: started" "$(jq -r '.ok' <<<"$r" | grep -c true)" "${r:0:200}"
    t=$(step_wait 1 400)
    sampler_stop
    state after-test
    local res; res=$(sj after-test '.step.results | to_entries | map("\(.key | split(":") | last)=\(.value.found)\(.value.tachs)") | join(" ")')
    check "test_up: pairs" "$( [[ $res == *"pwm1=tach[1]"*"pwm2=tach[2]"*"pwm3=ask[]"*"pwm4=tach[4]"*"pwm5=tach[5]"*"pwm6=ask[]"* ]] && echo 1 || echo 0)" \
        "$(sj after-test .step.status) in ${t} s: $res (expected 1→1, 2→2 (splitter, one tach), 3 ask (no tach), 4→4, 5→5, 6 ask (empty))"
    check "test_up: every output back exactly" "$( [[ $(regs) == "$before" ]] && echo 1 || echo 0)" "before: $before · after: $(regs)"
    r=$(ask fanboy.answer "{\"fan\":\"$(F 3)\",\"louder\":true}"); check "answer: pwm3 louder → silent" "$(jq -r '.fan.found == "silent"' <<<"$r" | grep -c true)" "${r:0:200}"
    r=$(ask fanboy.answer "{\"fan\":\"$(F 6)\",\"louder\":false}"); check "answer: pwm6 not louder → empty" "$(jq -r '.fan.found == "empty"' <<<"$r" | grep -c true)" "${r:0:200}"
    ask fanboy.role "{\"fan\":\"$(F 1)\",\"role\":\"cpu\",\"name\":\"CPU cooler\"}" >/dev/null
    ask fanboy.role "{\"fan\":\"$(F 2)\",\"role\":\"case\",\"name\":\"Front pair\",\"place\":\"front\"}" >/dev/null
    ask fanboy.role "{\"fan\":\"$(F 3)\",\"role\":\"case\",\"name\":\"Top 3-pin\",\"place\":\"top\"}" >/dev/null
    ask fanboy.role "{\"fan\":\"$(F 4)\",\"role\":\"disks\",\"name\":\"Disk cage\",\"place\":\"front\"}" >/dev/null
    r=$(ask fanboy.role "{\"fan\":\"$(F 5)\",\"role\":\"case\",\"name\":\"Rear\",\"place\":\"rear\"}")
    r=$(ask fanboy.measure "{\"fans\":[\"$(F 1)\"]}"); check "measure: the CPU cooler refused" "$(jq -r '.error.key == "fanboy_not_his"' <<<"$r" | grep -c true)" "${r:0:200}"
    r=$(ask fanboy.measure "{\"fans\":[\"$(F 3)\"]}"); check "measure: the fan without tach refused" "$(jq -r '.error.key == "fanboy_not_his"' <<<"$r" | grep -c true)" "${r:0:200}"
    before=$(regs)
    sampler_start measure 0.5
    r=$(ask fanboy.measure "{\"fans\":[\"$(F 2)\",\"$(F 4)\",\"$(F 5)\"]}")
    t=$(step_wait 1 900)
    sampler_stop
    state after-measure
    local m2 m4 m5
    m2=$(sj after-measure ".step.results[\"$(F 2)\"] | [.result, .min_pct, .start_pct, .stalled_at, .max_rpm] | @csv")
    m4=$(sj after-measure ".step.results[\"$(F 4)\"] | [.result, .min_pct, .start_pct, .stalled_at, .max_rpm] | @csv")
    m5=$(sj after-measure ".step.results[\"$(F 5)\"] | [.result, .min_pct, .start_pct, .stalled_at, .max_rpm] | @csv")
    near() { awk -v a="$1" -v b="$2" 'BEGIN{exit !(a > b*0.96 && a < b*1.04)}'; }
    check "measure: splitter pwm2 (stalls < 20 %, starts at 20 %, 1200 rpm)" "$( [[ $m2 == '"ok",20,20,15,'* ]] && near "${m2##*,}" 1200 && echo 1 || echo 0)" "result,min,start,stalled_at,max = $m2"
    # 35 % is value 89 = 34.9 % for the rig's fan: it starts at the next step, 40 %
    check "measure: disk cage pwm4 (stalls < 25 %, starts at 35 % → 40 %, 1500 rpm)" "$( [[ $m4 == '"ok",25,40,20,'* ]] && near "${m4##*,}" 1500 && echo 1 || echo 0)" "$m4"
    check "measure: rear pwm5 (no stall down to 10 %, 1000 rpm)" "$( [[ $m5 == '"ok",10,10,,'* ]] && near "${m5##*,}" 1000 && echo 1 || echo 0)" "$m5"
    # finding 1 of the first night (2026-11-15): test3's NVMe cache reads 50 °C in disks.ini — the warmth check must hold
    # an SSD/NVMe pool to its own kind's limit, not the HDD's 47 °C (without PATCH_WARM this is the real code's check)
    local warm; warm=$(sj after-measure '[.step.results[]? | select(.result == "warm") | .warm] | join(", ")')
    check "measure: an NVMe pool at 50 °C doesn't stop it «warm»$( [[ ${PATCH_WARM:-0} == 1 ]] && echo ' (PATCHED on test3)')" \
        "$( [[ -z $warm && $(sj after-measure .step.status) == done ]] && echo 1 || echo 0)" "status $(sj after-measure .step.status) $(sj after-measure .step.why); warm: ${warm:-none}; disks.ini pools: $(awk -F'"' '/^name=/{n=$2} /^rotational=/{r=$2} /^temp=/{if (r == "0") printf "%s %s °C (rotational 0) ", n, $2}' /var/local/emhttp/disks.ini)"
    info "measure: time and tables" "$(sj after-measure .step.status) in ${t} s; pwm4 table $(sj after-measure ".step.results[\"$(F 4)\"].table | tostring")"
    check "measure: every output back exactly" "$( [[ $(regs) == "$before" ]] && echo 1 || echo 0)" "before: $before · after: $(regs)"
    # the page gone: no step_alive — the step must stop after 45 s and give the outputs back
    local m5before t0 tstop; m5before=$(sj after-measure ".setup.fans[] | select(.id == \"$(F 5)\") | .table | tostring")
    sampler_start lease 0.5
    t0=$(date +%s); r=$(ask fanboy.measure "{\"fans\":[\"$(F 5)\"]}")
    t=$(step_wait 0 200); tstop=$(date +%s)
    sampler_stop
    state after-lease
    local back=$(regs)
    check "lease: a step without the page stops after 45 s" "$( [[ $(sj after-lease .step.status) == stopped && $(sj after-lease .step.why) == page_gone ]] && ((tstop - t0 >= 44 && tstop - t0 <= 52)) && echo 1 || echo 0)" \
        "status $(sj after-lease .step.status) ($(sj after-lease .step.why)) after $((tstop - t0)) s (polled every 2 s); log: $(logsince phase | grep 'setup step measure' | tail -1)"
    check "lease: outputs back" "$( [[ $back == "$before" ]] && echo 1 || echo 0)" "$back"
    check "lease: the stored measurement kept" "$( [[ $(sj after-lease ".setup.fans[] | select(.id == \"$(F 5)\") | .table | tostring") == "$m5before" && $m5before != null ]] && echo 1 || echo 0)" "table ${m5before:0:120}"
    ask fanboy.zones_propose > "$OUT/zones-propose.json"
    info "zones_propose" "$(jq -c '[.zones[] | {id, fans: [.fans[] | split(":") | last], sources: [.sources[] | "\(.id) (\(.kind))"]}]' "$OUT/zones-propose.json")"
    # the same finding, its second half: the proposal must not put SSD/NVMe pool disks on the HDD scale (kind «disk»)
    local ssd; ssd=$(awk -F'"' '/^name=/{n=$2} /^rotational=/{if ($2 == "0") print n}' /var/local/emhttp/disks.ini | sort -u | tr '\n' ' ')
    local onhdd; onhdd=$(jq -r --arg s "$ssd" '[.zones[]? | .sources[]? | select(.kind == "disk") | .id | select(. as $id | $s | split(" ") | any(. == $id))] | join(" ")' "$OUT/zones-propose.json")
    check "zones_propose: SSD/NVMe pools not on the HDD scale" "$( [[ -z $onhdd ]] && echo 1 || echo 0)" "pools that don't rotate: ${ssd:-none}; proposed as kind «disk»: ${onhdd:-none}"
    r=$(ask fanboy.zones_save "$(ZONES)")
    check "zones_save: three zones (preset, own curve, rpm)" "$(jq -r '(.zones | length) == 3' <<<"$r" | grep -c true)" "$(jq -c '[.zones[]? | {id, unit, target, n: (.sources | length)}]' <<<"$r") ${r:0:120}"
    before=$(regs)
    ask fanboy.dry_run > "$OUT/dry-run.json"
    check "dry_run: writes nothing" "$( [[ $(regs) == "$before" ]] && jq -e '.ok' "$OUT/dry-run.json" >/dev/null && echo 1 || echo 0)" \
        "$(jq -c '[.dry.zones[] | {id, level, warmest: .warmest.label, fans: [.fans[] | {o: .output, pct, value, now}]}]' "$OUT/dry-run.json")"
    sampler_start take 0.2
    t0=$(date +%s.%N); r=$(ask fanboy.take_over)
    t=$(until_ 30 steering); sleep 4
    sampler_stop
    state after-take
    check "take_over: he steers" "$( [[ $t != timeout ]] && echo 1 || echo 0)" "answer ${r:0:120}; steering after ${t} s; who $(sj after-take .who.who)/$(sj after-take .who.why); regs $(regs); log: $(logsince phase | grep -E 'taken over|control started' | tr '\n' ' ')"
    check "take_over: the CPU cooler untouched" "$( [[ $(reg 1) == 128/2 ]] && echo 1 || echo 0)" "pwm1 $(reg 1)"
    check "take_over: the empty connector untouched" "$( [[ $(reg 6) == 128/2 ]] && echo 1 || echo 0)" "pwm6 $(reg 6)"
    check "take_over: the guard's cron line there" "$(ok test -s /boot/config/plugins/unraid-secretary-office/fanboy-guard.cron)" "$(cat /boot/config/plugins/unraid-secretary-office/fanboy-guard.cron 2>/dev/null | tail -1); in /etc/cron.d/root (update_cron): $(grep -c fanboy-guard /etc/cron.d/root)"
    end_phase
}

# ---------------------------------------------------------------------------------------------------------- steering
pct() { awk -v v="$1" 'BEGIN{printf "%d", (v/2.55)+0.5}'; }
phase_steer() {
    begin_phase steer
    steering || take
    calm; sleep 20
    state calm
    info "calm" "$(regs); zones $(sj calm '[.control.zones[] | {id, level, w: .warmest.c}] | tostring')"
    check "floor: the disk cage at its floor (30 % = min 25 + 5)" "$( [[ $(reg 4) == 77/1 ]] && echo 1 || echo 0)" "pwm4 $(reg 4) — disks 31 °C, board1 35 °C → normal preset 20 %, floor 30 %"
    sampler_start updown 0.5
    # up at once: the case zone's CPU 45 → 70 °C — own curve 45:25 60:50 75:100 → 83 %
    local t0 t v
    RIG knob cpu 70 >/dev/null; t=$(until_ 10 bash -c "[[ \$(cat $(chipdir)/pwm2) -ge 205 ]]")
    check "up at once: CPU 45 → 70 °C" "$( [[ $t != timeout ]] && awk -v t="$t" 'BEGIN{exit !(t <= 4.5)}' && echo 1 || echo 0)" "pwm2 at $(reg 2) ($(pct "$(cat "$(chipdir)/pwm2")") %) after ${t} s (a round every 2 s); pwm3 $(reg 3)"
    sleep 6; local high; high=$(cat "$(chipdir)/pwm2")
    # the gap: 70 → 69 °C (less than 2 °C below): nothing goes down
    RIG knob cpu 69 >/dev/null; sleep 12
    check "gap: 1 °C down changes nothing" "$( [[ $(cat "$(chipdir)/pwm2") == "$high" ]] && echo 1 || echo 0)" "pwm2 $high → $(cat "$(chipdir)/pwm2") after 12 s at 69 °C"
    # down by the leading kind (round 4): the case zone is led by the CPU — «balanced» cpu: gap 2.5 °C, hold 6 s, then 5 % a
    # round down to the curve. 67 °C (3 °C below) → the curve's 73 % (187): holds 6 s, then two rounds
    RIG knob cpu 67 >/dev/null; t0=$(date +%s.%N); sleep 4
    v=$(cat "$(chipdir)/pwm2")
    check "down (cpu, balanced): holds 6 s first" "$( [[ $v == "$high" ]] && echo 1 || echo 0)" "4 s at 67 °C: pwm2 $high → $v"
    t=$(until_ 30 bash -c "[[ \$(cat $(chipdir)/pwm2) -le 188 ]]")
    local tdown; tdown=$(awk -v a="$t0" -v b="$(date +%s.%N)" 'BEGIN{printf "%.1f", b-a}')
    check "down (cpu, balanced): then 5 % a round to the curve's 73 %" "$( [[ $t != timeout ]] && awk -v t="$tdown" 'BEGIN{exit !(t >= 6 && t <= 14)}' && echo 1 || echo 0)" \
        "$(pct "$high") % → $(pct "$(cat "$(chipdir)/pwm2")") % $tdown s after 70 → 67 °C (expected ≈ 6 s hold + 2 rounds); zone $(jq -c '.zones[] | select(.id == "case") | {react, down}' "$FB/control.json")"
    # the floor: everything cool — the case zone wants 25 %; pwm2 floor 25 (min 20 + 5), pwm3 (no tach) 40 %
    RIG knob cpu 30 >/dev/null; RIG knob nvme 30 >/dev/null; RIG knob board2 25 >/dev/null
    t=$(until_ 150 bash -c "[[ \$(cat $(chipdir)/pwm2) -le 64 ]]")
    sleep 6
    check "floor: pwm2 never under 25 %" "$( [[ $(reg 2) == 64/1 ]] && echo 1 || echo 0)" "pwm2 $(reg 2) after ${t} s"
    check "floor: pwm3 (no tach) never under 40 %" "$( [[ $(reg 3) == 102/1 ]] && echo 1 || echo 0)" "pwm3 $(reg 3)"
    sampler_stop
    # the rpm zone: rear wants 460 rpm at 38 °C (35:400 50:700) — the fan worn 15 % slower: corrected until it fits
    calm; sleep 10
    state rpm-before
    local r0 p0 r1 p1
    r0=$(rpm 5); p0=$(reg 5)
    check "rpm zone: holds its rpm" "$(awk -v r="$r0" 'BEGIN{exit !(r > 440 && r < 480)}' && echo 1 || echo 0)" "fan5 $r0 rpm at pwm5 $p0 (want 460)"
    sampler_start rpm 1
    RIG knob scale5 0.85 >/dev/null; sleep 5
    t=$(until_ 180 bash -c "r=\$(cat $(chipdir)/fan5_input); ((r > 441 && r < 479))"); sleep 10
    r1=$(rpm 5); p1=$(reg 5)
    sampler_stop
    check "rpm zone: corrects a slower fan" "$(awk -v r="$r1" 'BEGIN{exit !(r > 440 && r < 480)}' && echo 1 || echo 0)" "scale 0.85: $r0 rpm at $p0 → back in 4 % after ${t} s more; 10 s later $r1 rpm at $p1"
    RIG knob scale5 1 >/dev/null
    # a sleeping disk: spun down by Unraid's own command (never woken here); QEMU's disks may ignore it
    local s
    /usr/local/sbin/emcmd cmdSpindown=disk2 >/dev/null 2>&1; sleep 15
    s=$(awk -F'"' '/^\["disk2"\]/{f=1} f && /^spundown=/{print $2; exit}' /var/local/emhttp/disks.ini)
    if [[ $s == 1 ]]; then
        sleep 12; state asleep
        check "asleep disk: counted asleep, not cold" "$(sj asleep '[.control.zones[] | select(.id == "disks") | .asleep] | .[0] >= 1' | grep -c true)" "$(sj asleep '.control.zones[] | select(.id == "disks") | tostring')"
    else
        info "asleep disk: skipped" "emcmd cmdSpindown=disk2 → disks.ini spundown=$s after 15 s: QEMU's disk ignores standby (as tools/scenarios/states-disks.sh says)"
    fi
    # the history: a point a minute per zone
    local h; h=$(jq -c '.zones | map_values(length)' "$FB/history.json" 2>/dev/null)
    r=$(ask fanboy.history)
    check "history: grows a point a minute" "$(jq -e '.zones | to_entries | all(.value | length >= 3)' "$FB/history.json" >/dev/null 2>&1 && echo 1 || echo 0)" \
        "RAM history $h (control up since $(stat -c %y "$FB/control.pid" | cut -c12-19)); data copy $(jq -c '.zones | map_values(length)' "$D/fanboy/history.json" 2>/dev/null); part answer ${r:0:200}"
    end_phase
}

# ---------------------------------------------------------------------------------------------------------- safety
kill_wait_back() {   # <name> <signal> <pids…>: kill, then the seconds until pwm2 is the BIOS's again
    local name=$1 sig=$2; shift 2
    sampler_start "$name" 0.2
    local t0; t0=$(date +%s.%N)
    kill "-$sig" "$@"
    until_ 120 is_reg 2 128/2
}
phase_safety() {
    begin_phase safety
    steering || take
    calm; sleep 8
    local t r before
    # 1. hand back — the state before Fanboy: pwm2/4/5 their curve (2), pwm3 had a fixed 128 → the board's automatic (owner's decision)
    before=$(regs); sampler_start hand_back 0.2
    r=$(ask fanboy.hand_back); sleep 1; sampler_stop
    check "hand_back: everything back at once" "$( [[ $(regs) == 'p1=128/2 p2=128/2 p3=128/2 p4=128/2 p5=128/2 p6=128/2' ]] && echo 1 || echo 0)" \
        "before $before → after $(regs) (pwm3 found on a fixed 128: back to the automatic); answer ${r:0:120}"
    check "hand_back: guard line gone, control gone" "$( [[ ! -e /boot/config/plugins/unraid-secretary-office/fanboy-guard.cron && $(grep -c fanboy-guard /etc/cron.d/root) == 0 && -z $(ctlpid) ]] && echo 1 || echo 0)" "cron $(ls /boot/config/plugins/unraid-secretary-office/fanboy-guard.cron 2>&1 | head -c 120)"
    take; sleep 6
    # 2. the agent restarts itself in place (its code changed): the control in its own session never notices
    sampler_start inplace 0.2; logmark inplace
    touch "$P/agent/agent.php"; sleep 25; sampler_stop
    check "agent restart in place: no flap" "$( ! grep -qE 'p2=128/2|p4=128/2|p5=128/2' "$OUT/pwm-inplace.txt" && logsince inplace | grep -q 'restarting' && echo 1 || echo 0)" \
        "$(logsince inplace | grep -E 'restarting|Agent started' | tr '\n' ' '); BIOS samples of pwm2/4/5 in 0.2 s sampling: $(grep -cE 'p2=128/2|p4=128/2|p5=128/2' "$OUT/pwm-inplace.txt") of $(wc -l < "$OUT/pwm-inplace.txt")"
    # 3. agent.sh restart (stop + start): stop gives everything back (the guard's release) — then the control again
    sampler_start restart 0.2; logmark restart; t=$(date +%s.%N)
    bash "$P/scripts/agent.sh" restart >/dev/null 2>&1
    local tb; tb=$(until_ 90 steering); sleep 3; sampler_stop
    local flap; flap=$(grep -cE 'p2=128/2' "$OUT/pwm-restart.txt")
    info "agent.sh restart: what the fans did" "BIOS samples of pwm2 at 0.2 s: $flap (≈ $(awk -v n="$flap" 'BEGIN{printf "%.1f", n*0.2}') s with the BIOS); steering again ${tb} s after the restart began; log: $(logsince restart | grep -E 'Fanboy|Agent (started|stopped)' | tr '\n' ' ' | head -c 600)"
    # 4. kill -9 of the control — three times: given back by the agent's tick, started again after 30 s, after the third the BIOS keeps them
    take; sleep 4; nmark crash; logmark crash
    local i pid tback trest
    for i in 1 2 3; do
        until_ 120 steering >/dev/null; sleep 3
        pid=$(ctlpid)
        tback=$(kill_wait_back "kill$i" 9 "$pid"); sampler_stop
        trest=$(until_ 60 steering)
        check "kill -9 control #$i: given back" "$( [[ $tback != timeout ]] && echo 1 || echo 0)" "PID $pid: pwm2 the BIOS's after ${tback} s; steering again after ${trest} s more; log: $(logsince crash | grep -E 'holder was gone|ended without|control started' | tail -3 | tr '\n' ' ')"
    done
    sleep 70; state crashed
    check "kill -9 ×3: the BIOS keeps them" "$( ! steering && [[ $(regs) == 'p1=128/2 p2=128/2 p3=128/2 p4=128/2 p5=128/2 p6=128/2' ]] && echo 1 || echo 0)" \
        "70 s after the third: who $(sj crashed .who.who)/$(sj crashed .who.why), control running $(sj crashed .control.running), $(regs)"
    local n; n=$(nsince crash)
    check "kill -9 ×3: one notification" "$( [[ $(grep -c . <<<"$n") == 1 ]] && echo 1 || echo 0)" "${n:-none}"
    ask fanboy.hand_back >/dev/null; take; sleep 6
    # 5. kill -9 of the control AND the agent (its supervisor starts it again in 10 s — whose start gives back?)
    logmark ka; local apid; apid=$(pgrep -f 'agent/agent.php run' | head -1)
    tback=$(kill_wait_back killboth 9 "$(ctlpid)" "$apid"); sampler_stop
    check "kill -9 control + agent: given back" "$( [[ $tback != timeout ]] && echo 1 || echo 0)" "after ${tback} s; log: $(logsince ka | grep -E 'holder was gone|agent ended|Agent started|released' | tr '\n' ' ' | head -c 500)"
    until_ 90 steering >/dev/null; sleep 4
    # 6. kill -9 of control, agent AND its supervisor: only the guard's cron line is left — within a minute
    logmark guard; local spid; spid=$(cat /var/run/unraid-secretary-office/supervisor.pid 2>/dev/null); apid=$(pgrep -f 'agent/agent.php run' | head -1)
    tback=$(kill_wait_back guard 9 "$spid" "$apid" "$(ctlpid)"); sampler_stop
    check "kill -9 control + agent + supervisor: the cron guard within a minute" "$( [[ $tback != timeout ]] && awk -v t="$tback" 'BEGIN{exit !(t <= 62)}' && echo 1 || echo 0)" \
        "after ${tback} s; log: $(logsince guard | grep -E 'Fanboy' | tr '\n' ' ' | head -c 400); guard.json $(cat "$FB/guard.json" 2>/dev/null | head -c 200)"
    bash "$P/scripts/agent.sh" start >/dev/null 2>&1; until_ 90 steering >/dev/null; sleep 4
    # 7. a hung control (SIGSTOP): 30 s without a beat → ended for good, given back
    logmark hung; pid=$(ctlpid)
    tback=$(kill_wait_back hung STOP "$pid"); sampler_stop
    check "hung control: given back after ~30 s" "$( [[ $tback != timeout ]] && awk -v t="$tback" 'BEGIN{exit !(t >= 29 && t <= 40)}' && ! kill -0 "$pid" 2>/dev/null && echo 1 || echo 0)" \
        "SIGSTOP PID $pid: pwm2 the BIOS's after ${tback} s; process $(kill -0 "$pid" 2>/dev/null && echo 'still there' || echo 'ended'); log: $(logsince hung | grep -E 'holder was gone' | tr '\n' ' ')"
    kill -CONT "$pid" 2>/dev/null
    until_ 90 steering >/dev/null; sleep 4
    # 8. agent.sh stop / start
    sampler_start stop 0.2; logmark stop
    bash "$P/scripts/agent.sh" stop >/dev/null 2>&1; sleep 1; sampler_stop
    check "agent.sh stop: given back, control ended" "$( [[ $(regs) == 'p1=128/2 p2=128/2 p3=128/2 p4=128/2 p5=128/2 p6=128/2' && -z $(pgrep -f 'job fanboy-control') ]] && echo 1 || echo 0)" \
        "$(regs); log: $(logsince stop | grep -E 'Fanboy|stopped' | tr '\n' ' ' | head -c 400)"
    bash "$P/scripts/agent.sh" start >/dev/null 2>&1
    t=$(until_ 90 steering)
    check "agent.sh start: he steers again" "$( [[ $t != timeout ]] && echo 1 || echo 0)" "after ${t} s"
    sleep 4
    # 9. the array stops and starts (emhttp's own command)
    sampler_start array 0.5; logmark array
    /usr/local/sbin/emcmd cmdStop=Stop >/dev/null 2>&1
    t=$(until_ 300 bash -c 'grep -q "^mdState=\"STOPPED\"" /var/local/emhttp/var.ini')
    check "array stop: given back" "$( [[ $(regs) == 'p1=128/2 p2=128/2 p3=128/2 p4=128/2 p5=128/2 p6=128/2' && -z $(pgrep -f 'job fanboy-control') ]] && echo 1 || echo 0)" "stopped after ${t} s; $(regs); log: $(logsince array | grep -E 'Fanboy' | tr '\n' ' ' | head -c 400)"
    state array-stopped
    info "array stopped: what the state says" "who $(sj array-stopped .who.who)/$(sj array-stopped .who.why) control $(sj array-stopped '.control | {running, why} | tostring')"
    /usr/local/sbin/emcmd cmdStart=Start >/dev/null 2>&1
    t=$(until_ 300 bash -c 'grep -q "^fsState=\"Started\"" /var/local/emhttp/var.ini')
    local t2; t2=$(until_ 120 steering); sampler_stop
    check "array start: he steers again" "$( [[ $t2 != timeout ]] && echo 1 || echo 0)" "array started after ${t} s, steering ${t2} s later"
    sleep 4
    # 10. agent.sh release (what the plugin's removal runs)
    logmark release
    bash "$P/scripts/agent.sh" release >/dev/null 2>&1
    check "agent.sh release: given back" "$( [[ $(regs) == 'p1=128/2 p2=128/2 p3=128/2 p4=128/2 p5=128/2 p6=128/2' && -z $(pgrep -f 'job fanboy-control') ]] && echo 1 || echo 0)" "$(regs); log: $(logsince release | grep Fanboy | tr '\n' ' ' | head -c 300)"
    bash "$P/scripts/agent.sh" start >/dev/null 2>&1; until_ 90 steering >/dev/null; sleep 6
    # 11. someone else writes an output he holds: the value, then the mode
    nmark foreign; logmark foreign; sampler_start foreign 0.2
    RIG foreign 4 200 >/dev/null
    t=$(until_ 10 bash -c "[[ \$(cat $(chipdir)/pwm4_enable) == 2 ]]"); sleep 8
    state foreign-value
    check "foreign value: that output back with the BIOS, said once" "$( [[ $t != timeout && $(reg 4) == 128/2 ]] && echo 1 || echo 0)" \
        "pwm4=200 written by another: pwm4 $(reg 4) after ${t} s; fan held: $(sj foreign-value "[.control.zones[].fans[] | select(.output == 4) | {held, foreign}] | tostring"); note $(logsince foreign | grep -i foreign | tr '\n' ' ')"
    RIG foreign 2 2 enable >/dev/null
    t=$(until_ 10 bash -c "[[ \$(jq -r '.outputs[\"$(F 2)\"].owner' $FB/journal.json) == null ]]"); sleep 6
    check "foreign mode: that output given back (its curve: the value is the chip's)" "$( [[ $t != timeout && $(reg 2) == */2 ]] && echo 1 || echo 0)" "pwm2_enable=2 written by another: after ${t} s pwm2 $(reg 2); others: $(regs)"
    sleep 10; sampler_stop
    n=$(nsince foreign)
    kcheck "foreign: one notification each (finding 3)" "$( [[ $(grep -c . <<<"$n") == 2 ]] && echo 1 || echo 0)" "${n:-none}"
    ask fanboy.hand_back >/dev/null; take
    end_phase
}

# ---------------------------------------------------------------------------------------------------------- alarms
alarm_case() {   # <name> <expected code> <outputs of the zone at 100 %, e.g. "2 3"> <knob on…> -- <knob off…>
    local name=$1 code=$2 full=$3; shift 3
    local on=() off=() side=on
    for a in "$@"; do [[ $a == -- ]] && { side=off; continue; }; [[ $side == on ]] && on+=("$a") || off+=("$a"); done
    nmark "$name"; logmark "$name"; sampler_start "$name" 0.5
    local t0 t; t0=$(date +%s)
    RIG knob "${on[@]}" >/dev/null
    t=$(until_ 150 bash -c "[[ \$(jq -r '.alarm.code // empty' $FB/control.json) == $code ]]")
    sleep 3; state "$name-on"
    check "alarm $name: $code" "$( [[ $t != timeout ]] && echo 1 || echo 0)" \
        "alarm after ${t} s: $(sj "$name-on" '.control.alarm | tostring'); regs $(regs)"
    # round 4: the alarm acts per zone — that zone's outputs at 100 %, the other zones steer on (a BIOS fan: told only)
    local o zok=1 zs=''
    for o in 2 3 4 5; do
        if [[ " $full " == *" $o "* ]]; then [[ $(reg $o) == 255/1 ]] || zok=0; zs+="p$o=$(reg $o) (zone: full) "
        else [[ $(reg $o) == 255/* ]] && zok=0; zs+="p$o=$(reg $o) (steers on) "; fi
    done
    [[ $(reg 6) == 128/2 ]] || zok=0
    check "alarm $name: only its zone at 100 %${full:+ (pwm ${full// /, })}${full:-: none (told only)}" "$zok" "$zs; pwm1 $(reg 1), empty pwm6 $(reg 6); zones full: $(jq -c '[.zones[] | select(.full) | .id]' "$FB/control.json")"
    if [[ $name == stands ]]; then
        # finding 0 of the first night: a blocked fan whose floor (30 %) is under its start value (40 %) — two kicks at most
        local kicks; kicks=$(awk '{for (i=2;i<=NF;i++) if ($i ~ /^p4=/) {split(substr($i,4),a,"/"); v=a[1]+0; if (v == 102 && p < 102) k++; p=v}} END{print k+0}' "$OUT/pwm-$name.txt")
        check "alarm stands: two kicks at most (floor 30 % < start 40 %)" "$( ((kicks >= 1 && kicks <= 2)) && echo 1 || echo 0)" "kicks to 40 % seen in pwm4 samples: $kicks; stand_since $(jq -c '.stand_since' "$FB/control.json")"
    fi
    RIG knob "${off[@]}" >/dev/null
    t=$(until_ 200 bash -c "[[ \$(jq -r '.alarm // \"none\"' $FB/control.json) == none ]]")
    sleep 8
    local n; n=$(nsince "$name")
    check "alarm $name: over after 120 s calm" "$( [[ $t != timeout ]] && echo 1 || echo 0)" "over ${t} s after the cause went; regs $(regs)"
    check "alarm $name: exactly one notification" "$( [[ $(grep -c . <<<"$n") == 1 ]] && echo 1 || echo 0)" "${n:-none}; log: $(logsince "$name" | grep -i alarm | tr '\n' ' ' | head -c 300)"
    sampler_stop
}
phase_alarms() {
    begin_phase alarms
    steering || take
    calm; sleep 20
    alarm_case stands stands "4" stand4 1 -- stand4 0
    calm; sleep 10
    alarm_case hot hot "2 3" nvme 72 -- nvme 42
    calm; sleep 10
    alarm_case sensor sensor "5" quiet_board3 1 -- quiet_board3 0
    calm; sleep 10
    alarm_case bios_stands bios_stands "" stand1 1 -- stand1 0
    end_phase
}

# ---------------------------------------------------------------------------------------------------------- round 3 + 4
err() { jq -r '.error.key // "ok"' <<<"$1"; }
errf() { jq -r '"\(.error.key // "ok") \(.error.params.field // "")"' <<<"$1"; }
fanj() { sj "$1" ".setup.fans[] | select(.id == \"$2\") | $3"; }
zone() { jq -c ".zones[] | select(.id == \"$1\") | $2" "$FB/control.json" 2>/dev/null; }
# the zones of «setup» with a jq change on top (react, kinds, a CPU cooler)
ZONESX() { ZONES | jq -c "$1"; }
phase_round4() {
    begin_phase round4
    steering || take
    calm; sleep 10
    local r t t0 high v
    # --- fans per connector (round 3): the splitter's two fans
    r=$(ask fanboy.role "{\"fan\":\"$(F 2)\",\"role\":\"case\",\"name\":\"Front pair\",\"place\":\"front\",\"count\":2}")
    check "count: two fans on pwm2 kept" "$(jq -r '.fan.count == 2' <<<"$r" | grep -c true)" "${r:0:200}"
    r=$(ask fanboy.role "{\"fan\":\"$(F 2)\",\"role\":\"case\",\"name\":\"Front pair\",\"count\":9}"); local r2; r2=$(ask fanboy.role "{\"fan\":\"$(F 2)\",\"role\":\"case\",\"name\":\"Front pair\",\"count\":\"2\"}")
    local r3; r3=$(ask fanboy.role "{\"fan\":\"$(F 2)\",\"role\":\"case\",\"name\":\"Front pair\",\"count\":0}")
    check "count: 9, \"2\" and 0 refused" "$( [[ $(err "$r") == fanboy_bad_count && $(err "$r2") == bad_request && $(err "$r3") == bad_request ]] && echo 1 || echo 0)" "9 → $(err "$r"), \"2\" → $(err "$r2"), 0 → $(err "$r3")"
    r=$(ask fanboy.role "{\"fan\":\"$(F 2)\",\"role\":\"case\",\"name\":\"Front pair\",\"place\":\"front\"}"); state count
    check "count: not sent = as it was" "$( [[ $(fanj count "$(F 2)" .count) == 2 ]] && echo 1 || echo 0)" "state count $(fanj count "$(F 2)" .count)"
    # --- reaction per zone (round 4): the case zone «quick» — cpu: gap 1.5 °C, hold 3 s, 8 % a round
    r=$(ask fanboy.zones_save "$(ZONESX '.zones[1].react = "fast"')")
    check "react: an unknown reaction refused" "$( [[ $(errf "$r") == 'fanboy_bad_zones react' ]] && echo 1 || echo 0)" "$(errf "$r")"
    r=$(ask fanboy.zones_save "$(ZONESX '.zones[1].react = "quick"')")
    t=$(until_ 30 bash -c "[[ \$(jq -r '.zones[] | select(.id == \"case\") | .react' $FB/control.json) == quick ]]")
    check "react: quick saved, the control takes it" "$( [[ $(jq -r '.zones[1].react' <<<"$r") == quick && $t != timeout ]] && echo 1 || echo 0)" "after ${t} s: $(zone case '{react, down}')"
    sampler_start react-quick 0.5
    RIG knob cpu 70 >/dev/null; until_ 10 bash -c "[[ \$(cat $(chipdir)/pwm2) -ge 205 ]]" >/dev/null; sleep 6; high=$(cat "$(chipdir)/pwm2")
    t0=$(date +%s.%N); RIG knob cpu 68 >/dev/null
    t=$(until_ 20 bash -c "[[ \$(cat $(chipdir)/pwm2) -lt $high ]]")
    sleep 6; v=$(cat "$(chipdir)/pwm2"); sampler_stop
    check "react quick: 2 °C down is enough (gap 1.5), after ~3 s" "$( [[ $t != timeout ]] && awk -v t="$t" 'BEGIN{exit !(t >= 2.5 && t <= 7)}' && ((v >= 194 && v <= 198)) && echo 1 || echo 0)" \
        "70 → 68 °C: pwm2 $high ($(pct "$high") %) began down after ${t} s, 6 s later $v ($(pct "$v") %; the curve at 68 °C: 77 %)"
    r=$(ask fanboy.zones_save "$(ZONES)")
    until_ 30 bash -c "[[ \$(jq -r '.zones[] | select(.id == \"case\") | .react' $FB/control.json) == balanced ]]" >/dev/null
    sampler_start react-balanced 0.5
    RIG knob cpu 45 >/dev/null; sleep 4; RIG knob cpu 70 >/dev/null; until_ 10 bash -c "[[ \$(cat $(chipdir)/pwm2) -ge 205 ]]" >/dev/null; sleep 6; high=$(cat "$(chipdir)/pwm2")
    RIG knob cpu 68 >/dev/null; sleep 14; v=$(cat "$(chipdir)/pwm2"); sampler_stop
    check "react balanced (the default again): 2 °C down holds (gap 2.5)" "$( [[ $v == "$high" && $(zone case .react) == '"balanced"' ]] && echo 1 || echo 0)" "70 → 68 °C: pwm2 $high → $v after 14 s; $(zone case '{react, down}')"
    calm; sleep 6
    # --- degrees per kind (round 4): the case zone's NVMe «full at 55, limit 60» — its own limit (70) taken off the source
    local KZ='del(.zones[1].sources[1].limit)'
    r=$(ask fanboy.zones_save "$(ZONESX "$KZ | .zones[1].kinds = {disk: {limit: 50}}")"); r2=$(ask fanboy.zones_save "$(ZONESX "$KZ | .zones[1].kinds = {nvme: {limit: 50}}")")
    r3=$(ask fanboy.zones_save "$(ZONESX "$KZ | .zones[1].kinds = {nvme: {limit: 130}}")")
    check "kinds: refused — a kind the zone hasn't, low < high < limit broken, out of bounds" \
        "$( [[ $(errf "$r") == 'fanboy_bad_zones kinds' && $(err "$r2") == fanboy_bad_temps && $(err "$r3") != ok ]] && echo 1 || echo 0)" "disk in case → $(errf "$r"); nvme limit 50 (< high 65) → $(err "$r2") $(jq -c .error.params <<<"$r2"); nvme 130 → $(errf "$r3")"
    r=$(ask fanboy.zones_save "$(ZONESX "$KZ | .zones[1].kinds = {nvme: {high: 55, limit: 60}}")")
    check "kinds: saved" "$(jq -r '.zones[1].kinds.nvme == {high: 55, limit: 60}' <<<"$r" | grep -c true)" "$(jq -c '.zones[1] | {kinds, s: .sources[1]}' <<<"$r")"
    sleep 10; nmark kinds; logmark kinds; sampler_start kinds 0.5
    RIG knob nvme 62 >/dev/null
    t=$(until_ 120 bash -c "[[ \$(jq -r '.alarm.code // empty' $FB/control.json) == hot ]]"); sleep 3
    check "kinds: NVMe 62 °C over its zone's limit 60 → «too warm» after 60 s" "$( [[ $t != timeout ]] && awk -v t="$t" 'BEGIN{exit !(t >= 58 && t <= 75)}' && echo 1 || echo 0)" "after ${t} s: $(jq -c .alarm "$FB/control.json")"
    check "kinds: only the case zone at 100 %" "$( [[ $(reg 2) == 255/1 && $(reg 3) == 255/1 && $(reg 4) != 255/* && $(reg 5) != 255/* ]] && echo 1 || echo 0)" "$(regs); full $(jq -c '[.zones[] | select(.full) | .id]' "$FB/control.json")"
    RIG knob nvme 42 >/dev/null
    t=$(until_ 200 bash -c "[[ \$(jq -r '.alarm // \"none\"' $FB/control.json) == none ]]"); sleep 4; sampler_stop
    check "kinds: over, one notification" "$( [[ $t != timeout && $(nsince kinds | grep -c .) == 1 ]] && echo 1 || echo 0)" "over after ${t} s; $(nsince kinds | tr '\n' ' ')"
    r=$(ask fanboy.zones_save "$(ZONES)"); calm; sleep 6
    # --- the CPU cooler under Fanboy (round 4): only confirmed, only in a zone with a CPU temperature
    r=$(ask fanboy.role "{\"fan\":\"$(F 1)\",\"role\":\"cpu\",\"name\":\"CPU cooler\",\"cpu_own\":true}")
    r2=$(ask fanboy.role "{\"fan\":\"$(F 2)\",\"role\":\"case\",\"name\":\"Front pair\",\"cpu_own\":true,\"cpu_confirm\":true}")
    check "cpu_own: refused without the tick, refused for a case fan" "$( [[ $(err "$r") == fanboy_cpu_confirm && $(err "$r2") == fanboy_bad_role ]] && echo 1 || echo 0)" "no cpu_confirm → $(err "$r"); role case → $(err "$r2")"
    r=$(ask fanboy.role "{\"fan\":\"$(F 1)\",\"role\":\"cpu\",\"name\":\"CPU cooler\",\"cpu_own\":true,\"cpu_confirm\":true}")
    check "cpu_own: given, dated" "$(jq -r '.fan.cpu_own.at | type == "number"' <<<"$r" | grep -c true)" "$(jq -c '.fan | {role, cpu_own}' <<<"$r"); pwm1 still $(reg 1)"
    r=$(ask fanboy.zones_save "$(ZONESX ".zones[2].fans += [\"$(F 1)\"]")")
    check "cpu_own: refused in a zone without a CPU temperature" "$( [[ $(err "$r") == fanboy_cpu_zone_needs_cpu ]] && echo 1 || echo 0)" "rear + CPU cooler → $(err "$r")"
    sampler_start cpu-take 0.5
    r=$(ask fanboy.zones_save "$(ZONESX ".zones[1].fans += [\"$(F 1)\"]")")
    t=$(until_ 30 bash -c "[[ \$(cat $(chipdir)/pwm1_enable) == 1 ]]")
    check "cpu_own: he takes the CPU cooler in the case zone" "$( [[ $t != timeout ]] && echo 1 || echo 0)" "after ${t} s: pwm1 $(reg 1); $(zone case '[.fans[] | {o: .output, pct, held}]')"
    r=$(ask fanboy.role "{\"fan\":\"$(F 1)\",\"role\":\"cpu\",\"name\":\"CPU fan\",\"place\":\"\"}"); state cpu-renamed
    check "cpu_own: a role edit without cpu_own keeps it" "$( [[ $(fanj cpu-renamed "$(F 1)" .cpu_own) =~ ^[0-9]+$ ]] && echo 1 || echo 0)" "cpu_own $(fanj cpu-renamed "$(F 1)" .cpu_own), name $(fanj cpu-renamed "$(F 1)" .name)"
    # its floor: max(40, min + 5) — never off: cool, the case zone wants 25 %
    RIG knob cpu 30 >/dev/null; RIG knob nvme 30 >/dev/null; RIG knob board2 25 >/dev/null
    t=$(until_ 120 bash -c "[[ \$(cat $(chipdir)/pwm1) -le 102 ]]"); sleep 10; sampler_stop
    local min1; min1=$(awk '{for (i=2;i<=NF;i++) if ($i ~ /^p1=/) {split(substr($i,4),a,"/"); if (a[2] == 1 && (m == "" || a[1]+0 < m)) m=a[1]+0}} END{print m}' "$OUT/pwm-cpu-take.txt")
    check "cpu_own: floor 40 % (max(40, min+5)), never under, never off" "$( [[ $(reg 1) == 102/1 && $min1 -ge 102 ]] && echo 1 || echo 0)" "cool: pwm1 $(reg 1) after ${t} s; lowest written $min1; $(zone case '{level, warmest: .warmest.c}')"
    # the way down: cpu 70 → 60 °C — holds 6 s, then about 5 % a round to the curve's 50 %
    RIG knob cpu 70 >/dev/null; until_ 10 bash -c "[[ \$(cat $(chipdir)/pwm1) -ge 205 ]]" >/dev/null; sleep 6
    sampler_start cpu-down 0.25; sleep 1; t0=$(date +%s.%N); RIG knob cpu 60 >/dev/null
    until_ 40 bash -c "[[ \$(cat $(chipdir)/pwm1) -le 128 ]]" >/dev/null; sleep 3; sampler_stop
    local steps; steps=$(awk -v t0="$t0" '{for (i=2;i<=NF;i++) if ($i ~ /^p1=/) {split(substr($i,4),a,"/"); v=a[1]+0; if (p != "" && v != p) printf "%.1f:%d ", $1-t0, v-p; p=v}}' "$OUT/pwm-cpu-down.txt")
    local first; first=$(awk '{print $1+0; exit}' <<<"${steps%%:*}")
    check "cpu_own: way down — hold 6 s, then ~5 % a round" "$(awk -v f="$first" -v s="$steps" 'BEGIN{n=split(s,a," "); ok=(f >= 5.5 && f <= 9); for (i=1;i<=n;i++) {split(a[i],b,":"); if (b[2] < -14 || b[2] > 0) ok=0} exit !ok}' && echo 1 || echo 0)" \
        "70 → 60 °C, changes of pwm1 (s after : value step): $steps(5 % = 12.75)"
    # it stands: its zone at 100 % — the CPU cooler, the front pair, the 3-pin; disks and rear steer on
    calm; sleep 12; nmark cpustand; logmark cpustand; sampler_start cpu-stand 0.5
    RIG knob stand1 1 >/dev/null
    t=$(until_ 60 bash -c "[[ \$(jq -r '.alarm.code // empty' $FB/control.json) == stands ]]"); sleep 3
    check "cpu_own: it stands → alarm" "$( [[ $t != timeout ]] && echo 1 || echo 0)" "after ${t} s: $(jq -c .alarm "$FB/control.json")"
    check "cpu_own: it stands → its zone at 100 %, the others steer on" "$( [[ $(reg 1) == 255/1 && $(reg 2) == 255/1 && $(reg 3) == 255/1 && $(reg 4) != 255/* && $(reg 5) != 255/* ]] && echo 1 || echo 0)" "$(regs)"
    RIG knob stand1 0 >/dev/null
    t=$(until_ 200 bash -c "[[ \$(jq -r '.alarm // \"none\"' $FB/control.json) == none ]]"); sleep 4; sampler_stop
    check "cpu_own: over, one notification" "$( [[ $t != timeout && $(nsince cpustand | grep -c .) == 1 ]] && echo 1 || echo 0)" "over after ${t} s; $(nsince cpustand | tr '\n' ' ')"
    # «back to the BIOS»: the confirmation gone, the output back to the board's automatic at once
    sampler_start cpu-back 0.25
    r=$(ask fanboy.role "{\"fan\":\"$(F 1)\",\"role\":\"cpu\",\"name\":\"CPU cooler\",\"place\":\"\",\"cpu_own\":false}")
    t=$(until_ 30 bash -c "[[ \$(cat $(chipdir)/pwm1_enable) == 2 ]]"); sleep 4; sampler_stop; state cpu-back
    check "cpu_own: back to the BIOS clears it" "$( [[ $t != timeout && $(reg 1) == */2 && $(fanj cpu-back "$(F 1)" .cpu_own) == null ]] && echo 1 || echo 0)" "after ${t} s pwm1 $(reg 1); cpu_own $(fanj cpu-back "$(F 1)" .cpu_own); the others $(regs)"
    r=$(ask fanboy.zones_save "$(ZONES)"); sleep 4
    # --- the history and its periods (round 3)
    r=$(ask fanboy.history '{"hours":1}'); r2=$(ask fanboy.history '{"hours":5}'); r3=$(ask fanboy.history '{"hours":"24"}')
    local now; now=$(jq -r .state.now <<<"$r")
    check "history: the period 1 h only the last hour; 5 and \"24\" refused" \
        "$( [[ $(jq -r '.state.hours' <<<"$r") == 1 && $(jq -r --argjson n "$now" '[.state.zones[][] | .[0] < $n - 3600] | any' <<<"$r") == false && $(err "$r2") == fanboy_bad_period && $(err "$r3") == bad_request ]] && echo 1 || echo 0)" \
        "1 h: $(jq -c '.state.zones | map_values(length)' <<<"$r"); 5 → $(err "$r2"); \"24\" → $(err "$r3")"
    # thinning: points two days old a minute each (a peak among them) and one four days old put into the RAM history while
    # the control is away — loaded, folded into 5-minute spans (peaks kept), the old one gone (seconds :07 = ours)
    ask fanboy.hand_back >/dev/null; until_ 20 bash -c "! kill -0 $(ctlpid) 2>/dev/null" >/dev/null; sleep 1
    local T; T=$(( $(date +%s) / 60 * 60 + 7 ))
    jq -c --argjson T "$T" '.zones.disks = ([[($T - 345600), 99, 99, 9999]] + [range(0; 61) as $i | [($T - 144000 - 3600 + $i * 60), (if $i == 30 then 66 else 40 end), (if $i == 31 then 90 else 30 end), 700]] + .zones.disks)' \
        "$FB/history.json" > "$FB/.vmrun-history" && mv "$FB/.vmrun-history" "$FB/history.json"
    r=$(ask fanboy.history '{"hours":72}')
    local seeded; seeded=$(jq -c --argjson T "$T" '[.state.zones.disks[] | select(.[0] < $T - 43200)]' <<<"$r")
    check "history: 3 days — minutes older than 12 h folded into 5-minute spans, peaks kept, older than 3 days gone" \
        "$(jq -r --argjson T "$T" 'length >= 12 and length <= 13 and all(.[0] >= $T - 259200) and (map(.[1]) | max) == 66 and (map(.[2]) | max) == 90' <<<"$seeded" | grep -c true)" \
        "61 minutes 40 h ago + 1 point 96 h ago seeded → $(jq -r length <<<"$seeded") points: $(jq -c 'map([.[0] % 3600, .[1], .[2]])' <<<"$seeded" | head -c 400); hours $(jq .state.hours <<<"$r")"
    take; sleep 70
    check "history: the control keeps it thinned (RAM, a minute later)" "$(jq -r --argjson T "$T" '[.zones.disks[] | select(.[0] < $T - 43200)] | length >= 12 and length <= 13' "$FB/history.json" | grep -c true)" \
        "$(jq -c --argjson T "$T" '.zones | map_values(length), [.disks[] | select(.[0] < $T - 43200)] | length' "$FB/history.json" | tr '\n' ' ')"
    ask fanboy.hand_back >/dev/null; until_ 20 bash -c "[[ -z \$(pgrep -f '[j]ob fanboy-control') ]]" >/dev/null; sleep 1
    local h; for h in "$FB/history.json" "$D/fanboy/history.json"; do
        [[ -f $h ]] && jq -c --argjson T "$T" '.zones.disks |= map(select(.[0] >= $T - 43200 or .[0] % 60 != 7))' "$h" > "$h.vmrun" && mv "$h.vmrun" "$h"
    done
    info "history: the seeded points taken out again" "$(jq -c --argjson T "$T" '[.zones.disks[] | select(.[0] < $T - 43200)] | length' "$FB/history.json") left"
    take; sleep 6
    # --- the Dashboard setting and its tile (reads state files only)
    r=$(ask fanboy.dashboard '{"show":"loud"}'); r2=$(ask fanboy.dashboard '{"show":true}'); r3=$(ask fanboy.dashboard '{}')
    check "dashboard: odd values refused" "$( [[ $(err "$r") == bad_request && $(err "$r2") == bad_request && $(err "$r3") == bad_request ]] && echo 1 || echo 0)" "loud → $(err "$r"), true → $(err "$r2"), {} → $(err "$r3"); file $(cat "$D/fanboy/dashboard.json" 2>/dev/null || echo none)"
    local s
    for s in speeds history off; do
        r=$(ask fanboy.dashboard "{\"show\":\"$s\"}")
        php -d disable_functions=exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec "$HERE/vm-tile.php" > "$OUT/tile-$s.txt" 2>&1
        check "dashboard $s: kept, mirrored, the tile from state files only" \
            "$( [[ $(jq -r .show <<<"$r") == "$s" && $(jq -r .show "$D/fanboy/dashboard.json") == "$s" && $(jq -r .show "$FB/dash.json") == "$s" ]] && grep -q '^forbidden: none$' "$OUT/tile-$s.txt" && grep -q "^tile: $( [[ $s == off ]] && echo empty || echo shown)" "$OUT/tile-$s.txt" && echo 1 || echo 0)" \
            "file $(jq -c . "$D/fanboy/dashboard.json"); mirror $(cat "$FB/dash.json"); $(grep -E '^(tile|rows|outside|forbidden|zones|curves):' "$OUT/tile-$s.txt" | tr '\n' ' ' | head -c 500)"
    done
    end_phase
}

# ---------------------------------------------------------------------------------------------------------- the chip
atime_watch() { (cd / && exec setsid bash -c 'while :; do printf "%s %s\n" "$(date +%s)" "$(stat -c %X /sys/class/hwmon 2>/dev/null)"; sleep 1; done' </dev/null > "$OUT/looks.txt" 2>/dev/null) & echo $! > "$OUT/.atime"; }
atime_stop() { kill "$(cat "$OUT/.atime" 2>/dev/null)" 2>/dev/null; }
looks() { awk '{print $2}' "$OUT/looks.txt" | uniq | awk 'NR>1{printf "%d ", $1-p} {p=$1}'; }
phase_chip() {
    begin_phase chip
    steering || take
    calm; sleep 10
    nmark gone; logmark gone
    local before t0 t; before=$(regs)
    atime_watch
    t0=$(date +%s)
    RIG chip gone >/dev/null
    t=$(until_ 20 bash -c "[[ \$(jq -r .phase $FB/control.json) == wait_chip ]]")
    sleep 2
    info "chip gone: the registers (the fans keep what was last written — nothing can be given back)" "before $before; kept by the chip: $(tr '\n' ' ' < "$RIGDIR/regs.txt")"
    check "chip gone: he waits for it" "$( [[ $t != timeout ]] && echo 1 || echo 0)" "after ${t} s: $(jq -c '{who, why, phase, missing, alarm}' "$FB/control.json")"
    # 15 real minutes: one notification; looks every 5 s for 2 min, then every minute, then every 5 minutes
    sleep $((t0 + 915 - $(date +%s)))         # the 15-minute one is due at 900 s; Unraid writes its file a moment later
    local n15; n15=$(nsince gone)
    kcheck "chip missing 15 min: one notification (finding 7)" "$( [[ $(grep -c . <<<"$n15") == 1 ]] && echo 1 || echo 0)" "${n15:-none}; status $(jq -c '{why, missing}' "$FB/control.json")"
    sleep $((t0 + 1260 - $(date +%s)))         # 21 minutes: a quiet look (every 5 min) seen
    info "chip missing: the looks (seconds between the control's looks into /sys/class/hwmon)" "$(looks)"
    RIG chip back >/dev/null; t=$(until_ 330 steering); atime_stop
    sleep 8
    local nb; nb=$(nsince gone | grep -v -F "$n15")
    check "chip back: he takes over" "$( [[ $t != timeout ]] && echo 1 || echo 0)" "after ${t} s (the next quiet look); $(regs); log: $(logsince gone | grep Fanboy | tr '\n' ' ' | head -c 600)"
    check "chip back: one notification" "$( [[ $(grep -c . <<<"$nb") == 1 ]] && echo 1 || echo 0)" "${nb:-none}"
    info "chip: looks after it was back" "$(looks)"
    end_phase
}

phase_renumber() {
    begin_phase renumber
    steering || take
    calm; sleep 6
    state before
    logmark renum
    local fans0 t; fans0=$(sj before '[.setup.fans[].id] | sort | tostring')
    # a «boot» with other numbers: everything of the rig gone, then up with the chip as hwmon7 and the CPU as hwmon4
    RIG down >/dev/null; sleep 8
    RIG up --layout b >/dev/null
    ask fanboy.look_now >/dev/null
    t=$(until_ 90 steering); sleep 6
    state after
    check "hwmon number changed: found again" "$( [[ $t != timeout && $(sj after '[.setup.fans[].id] | sort | tostring') == "$fans0" && $(sj after '.setup.missing | length') == 0 ]] && echo 1 || echo 0)" \
        "chip now $(sj after "[.chips[] | select(.name == \"$CHIP\") | .hwmon] | first"), steering after ${t} s; stored fans same: $(sj after '.setup.fans | length'), missing $(sj after '.setup.missing | tostring'), zones $(sj after '.setup.zones | length'); $(regs)"
    check "hwmon number changed: sources found again" "$( [[ $(sj after '[.control.zones[].warmest.c] | all(. != null)') == true ]] && echo 1 || echo 0)" "$(sj after '[.control.zones[] | {id, w: .warmest.label, c: .warmest.c}] | tostring')"
    # the address moves (another platform device number): exactly one chip of that name → taken, said
    logmark moved; nmark moved
    RIG chip gone >/dev/null; sleep 3; RIG chip back --addr serial8250 >/dev/null
    ask fanboy.look_now >/dev/null
    t=$(until_ 60 steering); sleep 40
    state moved
    check "address moved: taken, said" "$( [[ $t != timeout ]] && logsince moved | grep -q 'moved' && echo 1 || echo 0)" "steering after ${t} s; log: $(logsince moved | grep -E 'moved|Fanboy' | tr '\n' ' ' | head -c 500)"
    info "address moved: zones and alarm 40 s later" "alarm $(sj moved '.control.alarm | tostring'); zones $(sj moved '[.control.zones[] | {id, w: .warmest.label, c: .warmest.c}] | tostring'); setup.missing $(sj moved '.setup.missing | tostring'); notifications $(nsince moved | tr '\n' ' ')"
    RIG chip gone >/dev/null; sleep 3; RIG chip back --addr pcspkr >/dev/null; ask fanboy.look_now >/dev/null; until_ 60 steering >/dev/null
    end_phase
}

# ---------------------------------------------------------------------------------------------------------- another controller
phase_other() {
    begin_phase other
    steering || take
    calm; sleep 6
    logmark other; nmark other
    local t r
    RIG other on >/dev/null
    t=$(until_ 30 bash -c "[[ \$(jq -r .phase $FB/control.json) == hold ]]")
    sleep 2; state other
    check "another controller: he holds, the BIOS has them" "$( [[ $t != timeout && $(regs) == 'p1=128/2 p2=128/2 p3=128/2 p4=128/2 p5=128/2 p6=128/2' ]] && echo 1 || echo 0)" \
        "after ${t} s: $(regs); who $(sj other '.who | tostring'); situation $(sj other '.situation | tostring')"
    r=$(ask fanboy.test_up "{\"fans\":[\"$(F 2)\"]}")
    check "another controller: a setup step refused, naming it" "$(jq -r '.error.key == "fanboy_other" or .error.key == "fanboy_steering"' <<<"$r" | grep -c true)" "${r:0:200}"
    RIG other off >/dev/null
    t=$(until_ 40 steering)
    check "another controller gone: he steers again" "$( [[ $t != timeout ]] && echo 1 || echo 0)" "after ${t} s; $(regs); notifications $(nsince other | tr '\n' ' ')"
    end_phase
}

# ---------------------------------------------------------------------------------------------------------- drivers
phase_drivers() {
    begin_phase drivers
    ask fanboy.hand_back >/dev/null
    RIG down >/dev/null
    lsmod | sort > "$OUT/lsmod-before.txt"
    local r; r=$(ask fanboy.drivers_load)
    lsmod | sort > "$OUT/lsmod-after.txt"
    check "drivers_load: each refuses or brings no output (a VM)" "$(jq -r '[.steps[]?.result] | all(. == "no_chip" or . == "no_output") and (.kept == null)' <<<"$r" | grep -c true)" "$(jq -c '[.steps[]? | "\(.module) \(.result)"]' <<<"$r") kept $(jq -c .kept <<<"$r")"
    check "drivers_load: nothing left loaded" "$(ok diff -q "$OUT/lsmod-before.txt" "$OUT/lsmod-after.txt")" "$(diff "$OUT/lsmod-before.txt" "$OUT/lsmod-after.txt" | grep '^[<>]' | awk '{print $1, $2}' | tr '\n' ' ' | head -c 300)"
    r=$(ask fanboy.drivers_undo)
    lsmod | sort > "$OUT/lsmod-undo.txt"
    check "drivers_undo" "$(jq -r .ok <<<"$r" | grep -c true)" "${r:0:200}; lsmod same: $(ok diff -q "$OUT/lsmod-before.txt" "$OUT/lsmod-undo.txt")"
    RIG up >/dev/null
    end_phase
}

# ---------------------------------------------------------------------------------------------------------- the boot case
phase_boot_pre() {
    begin_phase boot-pre
    steering || take
    sleep 4
    check "armed before the reboot" "$(ok steering)" "$(regs); boot_id $(cat /proc/sys/kernel/random/boot_id)"
    cp "$D/agent.log" "$OUT/agent-before-boot.log"
    RIG down >/dev/null
    info "rig down before the reboot" "$(mount | grep -c '^rig-') rig mounts left"
    end_phase
}
phase_boot_post() {   # after the reboot, the array started and the office dev-synced (the Mac does that); the rig still down
    begin_phase boot-post
    local up; up=$(awk '{print int($1)}' /proc/uptime)
    info "boot" "uptime ${up} s, boot_id $(cat /proc/sys/kernel/random/boot_id)"
    state no-chip
    info "no chip at the start" "who $(sj no-chip '.who | tostring') control $(sj no-chip '.control | {running, why, phase, missing} | tostring')"
    local wait=$(( 180 - ($(date +%s) - $(cat "$ROOT/boot-synced" 2>/dev/null || date +%s)) ))
    ((wait > 0)) && sleep "$wait"
    RIG up >/dev/null; local t0; t0=$(date +%s)
    local t; t=$(until_ 150 steering); sleep 6
    state up
    check "boot without the chip: takes over when it comes" "$( [[ $t != timeout ]] && echo 1 || echo 0)" "rig up 3 min after the office's start; steering after ${t} s; $(regs); log: $(logsince phase | grep Fanboy | tr '\n' ' ' | head -c 800)"
    end_phase
}

# ---------------------------------------------------------------------------------------------------------- leave
phase_leave() {
    begin_phase leave
    local r; r=$(ask fanboy.hand_back)
    RIG down >/dev/null
    rm -f "$D/fanboy/show.json"
    bash "$P/scripts/agent.sh" restart >/dev/null 2>&1; sleep 5
    check "left: no rig mount, no responder, no control, no step" "$( [[ -z $(mount | grep '^rig-') && -z $(pgrep -f '[v]m-responder|[j]ob fanboy|[f]anctrlplus_loop|[v]m-rig.sh watch') ]] && echo 1 || echo 0)" \
        "$(mount | grep -c '^rig-') mounts; procs: $(pgrep -af 'vm-responder|job fanboy|fanctrlplus_loop|vm-rig.sh watch' | head -3)"
    check "left: array started, agent at work, Fanboy hidden" "$( grep -q '^fsState="Started"' /var/local/emhttp/var.ini && bash "$P/scripts/agent.sh" status >/dev/null && ! tail -3 "$D/agent.log" | grep 'Agent started' | grep -q fanboy && echo 1 || echo 0)" \
        "$(tail -2 "$D/agent.log" | tr '\n' ' '); guard cron $(ls /boot/config/plugins/unraid-secretary-office/fanboy-guard.cron 2>&1 | head -c 100)"
    info "left in the data folder" "$(ls "$D/fanboy" | tr '\n' ' ')"
    end_phase
}

report() { { echo "# Fanboy on test3 — $(date -Is)"; for p in setup steer safety alarms round4 chip renumber other drivers boot-pre boot-post leave; do cat "$ROOT/$p/report.md" 2>/dev/null; done; } > "$ROOT/report.md"; echo "$ROOT/report.md"; }

[[ $(cat /sys/class/dmi/id/product_uuid 2>/dev/null) == 89b3d774-ddbb-4fbd-84b1-49b1f8647350 ]] || { echo "not test3 — refused"; exit 1; }
mkdir -p "$ROOT"
for ph in "$@"; do
    case $ph in
    setup|steer|safety|alarms|round4|chip|renumber|other|drivers|leave) "phase_$ph" ;;
    boot-pre) phase_boot_pre ;; boot-post) phase_boot_post ;;
    report) report ;;
    *) echo "unknown phase $ph"; exit 2 ;;
    esac
done
