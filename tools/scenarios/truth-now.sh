#!/bin/bash
# INV-TRUTH now, outside a scenario: a test VM's state files and its truth (tools/scenarios/truth-facts.php, run there)
# copied into a folder on this Mac, then tools/scenarios/truth.mjs draws the pages from them and compares.
#
#   bash tools/scenarios/truth-now.sh [--host tower|test3|test4] [--keep <dir>] [--refresh] [truth.mjs options…]
#
# --refresh: the desks' fresh looks first (backup, restore: <desk>.refresh through the plugin's mailbox, as opening the
# page asks). --keep <dir>: the copied look stays there. Hosts by address only (never USOPartner or nostromo). Exit as
# truth.mjs (0 nothing red, 1 red, 2 wrong call, 3 no playwright/Chrome), 4 the VM could not be read. Reads only.
set -u
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
host=tower keep='' refresh=0 rest=()
while (($#)); do
    case $1 in
        --host) host=${2:-}; shift 2 ;;
        --keep) keep=${2:-}; shift 2 ;;
        --refresh) refresh=1; shift ;;
        -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
        *) rest+=("$1"); shift ;;
    esac
done
case $host in
    tower) alias=uso-test ip=192.168.7.111 ;;
    test3) alias=uso-test3 ip=192.168.7.197 ;;
    test4) alias=uso-test4 ip=192.168.7.193 ;;
    *) echo "truth-now: unknown host «$host»" >&2; exit 2 ;;
esac
SSH="ssh -o BatchMode=yes -o ConnectTimeout=10"
addrs=$($SSH "$alias" "ip -4 -o addr show | awk '{print \$4}' | cut -d/ -f1" 2>/dev/null) || { echo "truth-now: $host not reachable" >&2; exit 4; }
if grep -qxE '192\.168\.7\.(20|190)' <<<"$addrs" || ! grep -qxF "$ip" <<<"$addrs"; then echo "truth-now: $alias is not $host ($ip) - refused" >&2; exit 2; fi
dir=${keep:-$(mktemp -d "${TMPDIR:-/tmp}/uso-truth.XXXXXX")}
[[ -n $keep ]] || trap 'rm -rf "$dir"' EXIT
mkdir -p "$dir"
$SSH "$alias" 'cat > /tmp/uso-truth-facts.php' < "$here/truth-facts.php" || exit 4
$SSH "$alias" "bash -s -- $refresh" <<'VM' | tar -C "$dir" -xf - || { echo "truth-now: could not read $host" >&2; exit 4; }
set -e
cfg=/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg
P=/usr/local/emhttp/plugins/unraid-secretary-office
D=$(sed -n 's/^DATA_DIR="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$cfg" | head -1)
if [[ $1 == 1 ]]; then
    for d in backup restore; do
        php -r '$P = "/usr/local/emhttp/plugins/unraid-secretary-office"; require "$P/src/bootstrap.php"; require_once "$P/src/mailbox.php";
            try { askAgent($argv[1], [], 120.0); } catch (Throwable $e) {}' -- "$d.refresh" >/dev/null 2>&1 || true
    done
fi
tmp=$(mktemp -d /tmp/uso-truth.XXXXXX)
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/states/post"
find "$D" -maxdepth 1 -type f -name '*.json' -exec cp -p {} "$tmp/states/" \;
[[ -d /tmp/uso-scenarios/post ]] && find /tmp/uso-scenarios/post -maxdepth 1 -type f -name '*.json' -exec cp -p {} "$tmp/states/post/" \;
php /tmp/uso-truth-facts.php > "$tmp/truth.json"
tar -C "$tmp" -cf - .
VM
[[ -n $keep ]] && echo "truth-now: the look kept in $dir" >&2
node "$here/truth.mjs" --look "$dir" ${rest[@]+"${rest[@]}"}
