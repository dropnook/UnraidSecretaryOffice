#!/bin/bash
# A desk's page on a test VM as text, without a browser session on the server (playbook §4): the VM's real state files
# (the data folder's *.json - every desk's state and its parts) and the canned answers a scenario saved
# (/tmp/uso-scenarios/post/*.json, `ask … --save-post`) copied into a throwaway folder on this Mac, then
# tools/scenarios/page.mjs shows the page built from this clone's files with them and prints the text of #sso per language.
#
#   bash tools/scenarios/page.sh [--host tower|test3] <desk>[/<tab>] [--lang de,en] [--json] [--keep]
#   bash tools/scenarios/page.sh --states <folder> <desk>[/<tab>] …     (files copied before - the runner's evidence)
#   … page.mjs's options pass through: --open (folds opened), --tree <tag> (that release's page), --want/--not/--value
#
# Hosts: tower (ssh uso-test) and test3 (ssh uso-test3) only - USOPartner and nostromo are refused by address. --keep
# leaves the copied folder and says where. Exit as page.mjs (0 ok, 1 not drawn, 2 wrong call, 3 no playwright/Chrome),
# 4 the VM could not be read.
set -u
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

host=tower states='' keep=0 rest=()
while (($#)); do
    case $1 in
        --host) host=${2:-}; shift 2 ;;
        --states) states=${2:-}; shift 2 ;;
        --keep) keep=1; shift ;;
        -h|--help) sed -n '2,13p' "$0"; exit 0 ;;
        *) rest+=("$1"); shift ;;
    esac
done
((${#rest[@]})) || { echo "usage: page.sh [--host tower|test3] <desk>[/<tab>] [--lang de,en] [--json] [--keep]" >&2; exit 2; }
command -v node >/dev/null 2>&1 || { echo "page: node not found" >&2; exit 3; }

if [[ -z $states ]]; then
    case $host in
        tower) alias=uso-test ip=192.168.7.111 ;;
        test3) alias=uso-test3 ip=192.168.7.197 ;;
        test4) alias=uso-test4 ip=192.168.7.193 ;;
        *) echo "page: unknown host «$host» (tower, test3 or test4)" >&2; exit 2 ;;
    esac
    # never USOPartner (the release canary) or nostromo (real) - by the address the VM really has
    addrs=$(ssh -o ConnectTimeout=8 -o BatchMode=yes "$alias" "ip -4 -o addr show | awk '{print \$4}' | cut -d/ -f1" 2>/dev/null) \
        || { echo "page: $host ($alias) not reachable" >&2; exit 4; }
    if grep -qxE '192\.168\.7\.(20|190)' <<<"$addrs" || ! grep -qxF "$ip" <<<"$addrs"; then
        echo "page: $alias is not the test VM $host ($ip) - refused" >&2
        exit 2
    fi
    states=$(mktemp -d "${TMPDIR:-/tmp}/uso-page.XXXXXX")
    ((keep)) || trap 'rm -rf "$states"' EXIT
    # the data folder from the plugin's cfg (the VM's own), its *.json and the scenario's canned answers - tar over ssh
    if ! ssh -o BatchMode=yes "$alias" 'set -e
        cfg=/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg
        D=$(sed -n "s/^DATA_DIR=\"\{0,1\}\([^\"]*\)\"\{0,1\}$/\1/p" "$cfg" | head -1)
        [[ -n $D && -d $D ]] || { echo "no data folder ($D)" >&2; exit 1; }
        tmp=$(mktemp -d /tmp/uso-page.XXXXXX)
        trap "rm -rf $tmp" EXIT
        mkdir "$tmp/post"
        find "$D" -maxdepth 1 -type f -name "*.json" -exec cp -p {} "$tmp/" \;
        [[ -d /tmp/uso-scenarios/post ]] && find /tmp/uso-scenarios/post -maxdepth 1 -type f -name "*.json" -exec cp -p {} "$tmp/post/" \;
        tar -C "$tmp" -cf - .' | tar -C "$states" -xf -; then
        echo "page: could not copy the state files from $host" >&2
        exit 4
    fi
    ((keep)) && echo "page: state files kept in $states" >&2
    hostname=$(ssh -o BatchMode=yes "$alias" 'hostname' 2>/dev/null)
    set -- --host "${hostname:-$host}"
else
    set --
fi
node "$here/page.mjs" --states "$states" "$@" "${rest[@]}"
