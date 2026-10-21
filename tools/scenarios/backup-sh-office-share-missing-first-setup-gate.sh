#!/bin/bash
# The setup's first step (2026-10-21, the owner on a fresh server: «Set up» opened whole without a backup place, the
# missing share a yellow line among everything else). A fresh server's setup: no office share UnraidSecretaryOffice
# (SH-OFFICE-SHARE-MISSING) and nothing set up (settings.ini aside, put back by the undo — a settings.ini that knows the
# share keeps the engine from proposing it anew; the page then chooses the share it made itself, testSetupFirstPlace).
#   only-step   the setup page from the server's state files shows only the step «First Mr. Backupsy needs a share of
#               his own …» with «Create UnraidSecretaryOffice…» — none of the steps behind it, no «Review…»
#   offer       the plan carries the agent's offer (place_offer): fresh, a pool of Unraid's, awake
#   made        «Create» (backup.place_make with the offer's pool, what the page sends) has Unraid's own «Add Share» make
#               it: shares.ini lists it, its cfg on the flash says only on that pool, no secondary storage
#   again       a second click is refused (place_exists), nothing made twice
#   opens       planned anew, the engine proposes it as the backup place, no offer; the page opens the setup (its steps,
#               not the first step)
# The share made here is removed by the undo only when this scenario made it (before the state puts the base's back).
# Pre-release (~2 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sh-office-share-missing-first-setup-gate
needs zfs
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=UnraidSecretaryOffice
CFG=/boot/config/shares/$S.cfg

# nothing set up (a fresh server's first setup): settings.ini aside; the undo puts it back
_pf_unplace() {
    [[ -f $UB/settings.ini ]] || return 0
    mv "$UB/settings.ini" "$KEEP/settings.ini.pf-before"
}
_pf_replace() { [[ -f $KEEP/settings.ini.pf-before ]] || return 0; mv "$KEEP/settings.ini.pf-before" "$UB/settings.ini"; }
# the share this scenario made: what the office put in it since, then Unraid's own «Delete» (share_remove — whose last
# look, any /mnt/*/<name>, also meets the office's own RAM folder /mnt/addons/UnraidSecretaryOffice: judged here by the
# pools and disks alone)
_pf_remove_made() {
    local d
    for d in $(share_roots "$S"); do
        say "undo: emptying $S on $d (made by this scenario): $(find "/mnt/$d/$S" -mindepth 1 2>/dev/null | head -5 | tr '\n' ' ')"
        find "/mnt/$d/$S" -mindepth 1 -delete || return 1
    done
    share_remove "$S" && return 0
    ! share_exists "$S" && [[ ! -f $CFG && -z $(share_roots "$S") ]]
}

state SH-OFFICE-SHARE-MISSING
share_exists "$S" && { _row state "$S" red "Unraid still lists $S"; scenario_end; }
_pf_unplace && undo_push _pf_replace || _row state settings red "settings.ini not put aside"
snap_before
flow BK-REPLAN

# the plan's offer, and the step alone on the page
offer=$(jq -c '.place_offer // empty' "$KEEP/plan-replan.json" 2>/dev/null)
pool=$(jq -r '.pool // ""' <<<"${offer:-null}")
if [[ -n $offer && $(jq -r '.fresh and (.asleep | not) and .share == "UnraidSecretaryOffice"' <<<"$offer") == true ]]; then
    cell offer 1 "the plan offers $S: $offer"
else
    cell offer 0 "the plan's place_offer: ${offer:-none}"
fi
ask_save_post backup.setup_get '{}' 120 >/dev/null
bubble_look first-step backup
look=$(ls -d "$KEEP"/looks/*-first-step | tail -1)
queue_mac only-step page_text backup/setup "$look/states" "$PAGE_LANGS" want "backup.setup.first.title backup.setup.first.make"
queue_mac only-step page_text backup/setup "$look/states" "$PAGE_LANGS" not "backup.setup.apps backup.setup.retention backup.setup.review"

# «Create UnraidSecretaryOffice…» — what the page sends after its confirmation
if ask backup.place_make "$(jq -cn --arg p "$pool" '{pool: $p}')" 120 >/dev/null; then
    undo_push _pf_remove_made
    use=$(_st_cfg_val "$CFG" shareUseCache 2>/dev/null) at=$(_st_cfg_val "$CFG" shareCachePool 2>/dev/null) two=$(_st_cfg_val "$CFG" shareCachePool2 2>/dev/null)
    want_use=$([[ -n $pool ]] && echo only || echo no)
    if _st_share_seen "$S" 15 && [[ $use == "$want_use" && $at == "$pool" && -z $two ]]; then
        cell made 1 "Unraid made $S: shares.ini lists it, $CFG shareUseCache=$use shareCachePool=${at:-–} shareCachePool2=–, export $(_st_cfg_val "$CFG" shareExport)"
    else
        cell made 0 "after the click: listed $(share_exists "$S" && echo yes || echo no), cfg shareUseCache=${use:-?} shareCachePool=${at:-?} shareCachePool2=${two:-–}"
    fi
    evidence "$CFG" office-share.cfg
    key=$(ask_err backup.place_make "$(jq -cn --arg p "$pool" '{pool: $p}')")
    [[ $key == place_exists ]] && cell again 1 "a second click refused: place_exists" || cell again 0 "a second click: «${key:-made again}»"
else
    cell made 0 "«Create» refused: $(ask_last '.error.key') $(ask_last '.error.params')"
fi

# planned anew: the engine proposes it, the setup opens
flow BK-REPLAN
ds=$(jq -r '.P["general|dumps_share"] // empty' "$KEEP/plan-replan.json" 2>/dev/null)
left=$(jq -c '.place_offer' "$KEEP/plan-replan.json" 2>/dev/null)
[[ $ds == "$S" && $left == null ]] && cell opens 1 "the new plan proposes $S as the backup place, no offer left" \
    || cell opens 0 "the new plan: place «${ds:-none}», offer ${left:-?}"
ask_save_post backup.setup_get '{}' 120 >/dev/null
bubble_look setup-opens backup
look=$(ls -d "$KEEP"/looks/*-setup-opens | tail -1)
queue_mac opens page_text backup/setup "$look/states" "$PAGE_LANGS" want "backup.setup.apps backup.setup.review"
queue_mac opens page_text backup/setup "$look/states" "$PAGE_LANGS" not "backup.setup.first.title"
allow INV-UNRAID-SAME "the cell's click made the share $S through Unraid's own «Add Share» (its cfg on the flash is new); the undo removes it before the state puts the base's back"
check standard
scenario_end
