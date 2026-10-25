#!/bin/bash
# Tower's «mixed» base profile made from this Mac (base/tower/profile-mixed.env says what is in it): the default reset,
# the profile's objects (base-mixed.sh), a partner pair Tower → USOTest4 over the LAN the Team Lead's way (Tower offers,
# USOTest4 accepts as a receiver only, Tower pastes the answer with the safety code — each through its own mailbox,
# tools/scenarios/ask.php on each VM), Mr. Backupsy's Apply with the profile's decisions, then the data folder kept as
# <data>.base-mixed/ (base.sh --profile mixed --save) and one real reset onto it (base.sh --profile mixed: its checks,
# the plan's change list).
#
#   bash tools/scenarios/mixed-make.sh [--no-sync] [--pair] [--dry]
#
# --pair: pair anew even when Tower's pairs.json has a pair with USOTest4 that answers. A pair whose USOTest4 half is gone
# (USOTest4's own base reset sets its data folder aside) is paired anew by itself. USOTest4 is touched only through its
# office's own pairing (its pairs.json, its authorized_keys line for the pair, what Tower sends). Under the Tower lock
# (flock -o /tmp/uso-scenarios.lock, on Tower). Exit 0 made, 1 a step failed, 2 wrong call, 4 a VM unreachable.
set -u
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../.." && pwd)
sync=1 pair=0 dry=0
while (($#)); do
    case $1 in
        --no-sync) sync=0 ;;
        --pair) pair=1 ;;
        --dry) dry=1 ;;
        -h|--help) sed -n '2,14p' "$0"; exit 0 ;;
        *) echo "mixed-make: unknown argument $1" >&2; exit 2 ;;
    esac
    shift
done
# shellcheck disable=SC1091
. "$here/base/tower/profile-mixed.env"
SSH="ssh -o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=15 -o ServerAliveCountMax=4"
say() { echo "mixed-make: $*"; }
tower() { $SSH uso-test "$@"; }
partner() { $SSH "$MIXED_PARTNER_ALIAS" "$@"; }
guard() {       # <alias> <ip>: the VM is that test VM, never USOPartner or nostromo
    local addrs
    addrs=$($SSH "$1" "ip -4 -o addr show | awk '{print \$4}' | cut -d/ -f1") || { say "$1 not reachable"; exit 4; }
    if grep -qxE '192\.168\.7\.(20|190)' <<<"$addrs" || ! grep -qxF "$2" <<<"$addrs"; then say "$1 is not $2 - refused"; exit 2; fi
}
guard uso-test 192.168.7.111
guard "$MIXED_PARTNER_ALIAS" "$MIXED_PARTNER_IP"
tag=$(basename "$repo"); [[ $tag == UnraidSecretaryOffice-local ]] && tag=''
tag=${tag//[^A-Za-z0-9_.-]/-}
TREE=/tmp/uso-scenarios/tree${tag:+-$tag}
LOCKED="flock -o -w 1800 /tmp/uso-scenarios.lock"
if ((dry)); then
    say "would: sync the tree to Tower:$TREE, base.sh (default), base-mixed.sh objects, pair with $MIXED_PARTNER_IP when needed,"
    say "would: base-mixed.sh apply <id> (${#MIXED_DECISIONS[@]} keys), base.sh --profile mixed --save, base.sh --profile mixed"
    exit 0
fi
if ((sync)); then
    tower "mkdir -p $TREE" && rsync -a --delete --exclude .git -e "$SSH" "$repo/" "uso-test:$TREE/" || { say "could not copy the tree"; exit 4; }
    say "tree synced to Tower:$TREE"
fi
t0=$SECONDS
# made anew on top of the mixed base when there is one (its pair with USOTest4 kept), else from the default base
start=default
tower "bash $TREE/tools/scenarios/base-mixed.sh has-base" && start=mixed
say "1. the reset to the $start base"
for try in 1 2 3 4 5 6; do
    tower "bash $TREE/tools/scenarios/base.sh --profile $start" > "${TMPDIR:-/tmp}/uso-mixed-base-1.log" 2>&1
    code=$?
    ((code == 2)) && grep -q 'a job of the office runs' "${TMPDIR:-/tmp}/uso-mixed-base-1.log" || break
    say "   the office is at work - again in 30 s"; sleep 30
done
# (its own checks may be red on a mixed base being made anew: only a refusal or a broken step stops here)
grep -q 'refused\|no base yet' "${TMPDIR:-/tmp}/uso-mixed-base-1.log" && { say "the reset was refused (${TMPDIR:-/tmp}/uso-mixed-base-1.log)"; tail -5 "${TMPDIR:-/tmp}/uso-mixed-base-1.log"; exit 1; }
say "2. the profile's objects"
tower "$LOCKED bash $TREE/tools/scenarios/base-mixed.sh objects" || { say "the objects could not be made"; exit 1; }
id=$(tower "bash $TREE/tools/scenarios/base-mixed.sh pair-id" 2>/dev/null)
answers=0
if [[ -n $id ]] && tower "bash -c '. $TREE/tools/scenarios/lib.sh >/dev/null 2>&1; . $TREE/tools/scenarios/base-mixed.sh; mixed_pair_ok $id'"; then answers=1; fi
if ((pair || !answers)); then
    say "3. paired with $MIXED_PARTNER_IP the Team Lead's way${id:+ (the pair $id is silent there)}"
    partner "mkdir -p /tmp/uso-mixed && cat > /tmp/uso-mixed/ask.php" < "$here/ask.php" || exit 4
    body=$(jq -cn '{step: "do", address: "192.168.7.111", port: 22, trust: "mine"}')
    a=$(tower "$LOCKED php $TREE/tools/scenarios/ask.php --wait 60 caretaker.partner_add $(printf %q "$body")") || { say "Tower's offer refused: $a"; exit 1; }
    new=$(jq -r '.id' <<<"$a")
    body=$(jq -cn --arg k "$(jq -r .block <<<"$a")" --arg ip "$MIXED_PARTNER_IP" --argjson r "$MIXED_PARTNER_RECEIVE" \
        '{block: $k, step: "do", confirm: true, receive: $r, send_too: false, address: $ip, port: 22, trust: "mine"}')
    b=$(partner "php /tmp/uso-mixed/ask.php --wait 120 caretaker.partner_accept $(printf %q "$body")") \
        || { say "USOTest4's accept refused: $(jq -c '.error // .' <<<"$b" | head -c 300)"; exit 1; }
    body=$(jq -cn --arg k "$(jq -r .block <<<"$b")" --arg c "$(jq -r .code <<<"$b")" '{block: $k, step: "do", confirm: true, code: $c, receive: null}')
    f=$(tower "$LOCKED php $TREE/tools/scenarios/ask.php --wait 120 caretaker.partner_finish $(printf %q "$body")") || { say "Tower's finish refused: $f"; exit 1; }
    id=$(tower "bash $TREE/tools/scenarios/base-mixed.sh pair-id")
    [[ $id == "$new" ]] || { say "Tower's pairs.json doesn't name the new pair $new at $MIXED_PARTNER_IP (it names «$id»)"; exit 1; }
    say "   the pair $id (Tower sends, USOTest4 keeps: $MIXED_PARTNER_RECEIVE)"
else
    say "3. the pair $id with $MIXED_PARTNER_IP answers — kept"
fi
say "4. Mr. Backupsy's Apply (${#MIXED_DECISIONS[@]} keys)"
tower "$LOCKED bash $TREE/tools/scenarios/base-mixed.sh apply $id" || { say "Apply failed"; exit 1; }
say "5. kept as the profile's base"
tower "bash $TREE/tools/scenarios/base.sh --profile mixed --save" || { say "--save failed"; exit 1; }
say "6. a real reset onto it"
# (the agent just started: its first jobs - a Kopia look, the partner's - make base.sh refuse for a minute)
for try in 1 2 3 4 5 6; do
    tower "bash $TREE/tools/scenarios/base.sh --profile mixed" > "${TMPDIR:-/tmp}/uso-mixed-base-2.log" 2>&1
    code=$?
    ((code == 2)) && grep -q 'a job of the office runs' "${TMPDIR:-/tmp}/uso-mixed-base-2.log" || break
    say "   the office is at work (its first jobs) - again in 30 s"
    sleep 30
done
grep -E 'mixed:|plan check|check standard|FAILED|done in' "${TMPDIR:-/tmp}/uso-mixed-base-2.log" | sed 's/^/   /'
say "made in $(( (SECONDS - t0) / 60 )) min (reset log ${TMPDIR:-/tmp}/uso-mixed-base-2.log)"
exit $((code ? 1 : 0))
