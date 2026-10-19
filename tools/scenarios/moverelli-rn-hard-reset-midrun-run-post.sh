#!/bin/bash
# Cell M6 of matrix.md, the power cut, post half (nightly): back after the power cut that hit her run. What must hold:
# her locks (office-run.lock, smartmover.lock, night.lock) belong to nobody (INV-NO-STALE-LOCK) — her next run starts;
# her page doesn't say a run is at work, her history says the cut run didn't end «ok»; her next run removes a leftover
# .partial whose source still exists and moves the rest — every file once, on the array (INV-FILES, INV-COUNTS).
# Unraid's own parity check after the unclean stop is cancelled. The undo removes the files, takes the demo set off
# Docker's autostart again and starts any container still missing.
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-rn-hard-reset-midrun-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
parity_cancel() { local d; d=$(_st_dir UNCLEAN-PARITY)
    if [[ -f /boot/config/parity-checks.log ]]; then cp -p /boot/config/parity-checks.log "$d/parity-checks.log"; else : >"$d/parity-checks.none"; fi
    parity_stop UNCLEAN-PARITY; }
on() { find /mnt/"$1"/uso-yes/uso-wip-d-m6r -type f ! -name '*.partial' 2>/dev/null | wc -l; }
partials() { find /mnt/disk*/uso-yes /mnt/cache/uso-yes -name '*.partial' 2>/dev/null; }
out=${USO_SC_OUT:-$SC_REPORTS}
reb=$out/moverelli-rn-hard-reset-midrun-run.reboot
prenote=$out/moverelli-rn-hard-reset-midrun-run.prenotes
was=$(sed -n 's/^docker=//p' "$reb" 2>/dev/null)
if grep -qx autostart=had "$prenote" 2>/dev/null; then undo_push cp -p "$prenote.autostart" /var/lib/docker/unraid-autostart
else undo_push rm -f /var/lib/docker/unraid-autostart; fi
undo_push ct_start ${was//,/ }
undo_push bash -c 'rm -rf /mnt/*/uso-yes/uso-wip-d-m6r'
agent_wait 300
nc_data_closed                               # Unraid's array start at the boot opened Nextcloud's data folder (0777) — not this cell's
if WAIT_SOFT=1 wait_for 60 "Unraid's parity check after the unclean stop" bash -c '(( $(/usr/local/sbin/mdcmd status | awk -F= "\$1 == \"mdResyncPos\" { print \$2 }") > 0 ))'; then
    say "Unraid checks parity after the unclean stop — cancelled (not this cell's)"; parity_cancel
else
    say "note: no parity check after the boot — was it a power cut?"
fi
track_share uso-yes
snap_before
p=$(partials | paste -sd' ' -)
say "after the boot: $(on cache) on cache, $(( $(on disk1) + $(on disk2) )) on the array; .partial: ${p:-none}"
held=()
for f in "$D/moverelli/"{office-run,night,smartmover}.lock; do lock_held "$f" && held+=("${f##*/}"); done
((${#held[@]} == 0)) && cell locks-free 1 "none of her locks held" || cell locks-free 0 "held after the boot: ${held[*]}"
ask moverelli.refresh '{}' 120 >/dev/null
[[ $(jq -r '.job.running // false' "$D/moverelli.json") == false ]] && cell not-running 1 "her page: no run at work" || cell not-running 0 "her page says a run is at work after the boot"
h=$(jq -c '.history[0] // {} | {result, why, started}' "$D/moverelli.json" 2>/dev/null)
say "her last run as her page lists it: $h"
[[ $(jq -r '.result // ""' <<<"$h") != ok ]] && cell history-says 1 "her history: $h" || cell history-says 0 "her history calls the cut run ok: $h"
flow MV-RUN
p=$(partials | paste -sd' ' -)
[[ -z $p ]] && cell partial-cleared 1 "no .partial after her next run" || cell partial-cleared 0 "after her next run still: $p"
c=$(on cache) a=$(( $(on disk1) + $(on disk2) ))
((c == 0 && a == 6)) && cell all-moved 1 "all 6 files on the array now, once each" || cell all-moved 0 "after her next run: $c on cache, $a on the array (of 6)"
check standard
scenario_end
