#!/bin/bash
# Cell R13 of matrix.md, variant CT-RECREATED: after a run, EmbyServer is re-created under its own name (Unraid's
# rebuild_container: a new container id, the same template). «Restore the templates» (the dialog's preview, kind config)
# names the template's real path (templates-user/my-EmbyServer.xml) and, the file being the same, says so
# (restore_config_same — true here); the drill passes with the package the old container wrote (no image or container
# check fails on the new id). Only a preview: nothing is restored. The renamed variants: restore-ct-renamed-config-drill.
# Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-ct-recreated-config-drill
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
TPLDIR=/boot/config/plugins/dockerMan/templates-user
nc_data_closed
flow BK-RUN-MANUAL mode=backup               # EmbyServer's package, written by the old container
state CT-RECREATED EmbyServer
snap_before
flow RS-SCAN
if ask restore.preview '{"kind":"config","app":"EmbyServer"}' 300 >/dev/null; then
    p=$KEEP/rs-config-EmbyServer.json
    ask_last '.preview' >"$p"
    b=$(jq -r '[.blockers[]?.key] | join(",")' "$p")
    to=$(jq -r '[.options.items[]? | select(.what == "template") | "\(.to) \(.now)"] | join("; ")' "$p")
    [[ $to == "$TPLDIR/my-EmbyServer.xml same" && $b == restore_config_same ]] \
        && cell template-path-same 1 "the template is $TPLDIR/my-EmbyServer.xml, the same as in the package: restore_config_same" \
        || cell template-path-same 0 "template items «${to:-none}», blockers «${b:-none}»"
else
    cell template-path-same 0 "the preview is refused: $(ask_last '.error.key')"
fi
flow RS-DRILL
check standard
check final
scenario_end
