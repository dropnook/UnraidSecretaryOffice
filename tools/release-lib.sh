#!/bin/bash
# Sourced by tools/release.sh (and by tests/run.php's testRelease* tests): the parts of a release run that are worth a
# test of their own - the inputs' hashes, the state file, the click test's one retry.
#
#   rl_tree <repo>                      the tree hash of the working tree as it is (tracked and untracked files, .gitignore
#                                       respected) - git's own index is never touched (a throwaway one beside)
#   rl_hash <repo> <tree> <step> [word …]   a short hash of the step's input files in <tree> (rl_list) and the words
#                                       (the version a step runs with, the last tag …)
#   rl_list <repo> <tree> <step>        the step's input files (git ls-tree lines) - the table in tools/release.sh's head:
#                                       build, suite: all; clicks: public/ tests/ui/ tools/ui-* src/; scenarios: all but docs/
#   rl_state_get <file> <step> <hash>   «<when><TAB><what>» of the step's line when it was green with that hash, else 1
#   rl_state_put <file> <step> <hash> <result> <what>   the step's line replaced (one a step, the file rewritten whole)
#   rl_clicks <dir> [args …]            the click test with its one retry: <dir>/out (all it printed), <dir>/rc (0 green,
#                                       1 red, 3 not run), <dir>/summary, <dir>/flaky («flaky: <page> (<theme> <width>)»
#                                       lines). The command: USO_UI_CLICKS (default «bash tools/ui-clicks.sh»).
#                                       USO_RELEASE_FLAKY=<route> forces one page of <route> red in the first run only
#                                       (green on the retry: the flaky path); <route>:twice in both (red twice).
#
# The state file (tools/release.sh: <notes_dir>/releases/<x.y.z>.state, beside the log), one line a step:
#   <step><TAB><inputs hash><TAB><YYYY-MM-DD HH:MM:SS><TAB>green|red<TAB><what it said>

rl_sha() {
    if command -v shasum >/dev/null 2>&1; then shasum -a 256; else sha256sum; fi | cut -c1-16
}

rl_tree() {
    local repo=$1 gd tmp tree
    gd=$(git -C "$repo" rev-parse --absolute-git-dir 2>/dev/null) || return 1
    tmp=$(mktemp -d "${TMPDIR:-/tmp}/uso-release-index.XXXXXX") || return 1
    # the real index copied: its stat data spares hashing every unchanged file again
    [[ -f $gd/index ]] && cp "$gd/index" "$tmp/index"
    if GIT_INDEX_FILE=$tmp/index git -C "$repo" add -A . >/dev/null 2>&1; then
        tree=$(GIT_INDEX_FILE=$tmp/index git -C "$repo" write-tree 2>/dev/null)
    fi
    rm -rf "$tmp"
    [[ -n ${tree:-} ]] && echo "$tree"
}

rl_list() {
    git -C "$1" ls-tree -r "$2" | awk -F'\t' -v s="$3" '
        s == "clicks"    { if ($2 ~ /^(public|tests\/ui|src)\// || $2 ~ /^tools\/ui-/) print; next }
        s == "scenarios" { if ($2 !~ /^docs\//) print; next }
        s == "build" || s == "suite" { print; next }
        { exit 1 }'
}

rl_hash() {
    local repo=$1 tree=$2 step=$3 list
    shift 3
    list=$(rl_list "$repo" "$tree" "$step") || return 1
    [[ -n $list ]] || return 1
    { printf '%s\n' "$list"; printf 'word %s\n' "$@"; } | rl_sha
}

rl_state_get() {
    local file=$1 step=$2 hash=$3 line
    [[ -r $file ]] || return 1
    line=$(awk -F'\t' -v s="$step" '$1 == s' "$file" | tail -n 1)
    [[ -n $line ]] || return 1
    IFS=$'\t' read -r _ h when result what <<<"$line"
    [[ $h == "$hash" && $result == green ]] || return 1
    printf '%s\t%s\n' "$when" "$what"
}

rl_state_put() {
    local file=$1 step=$2 hash=$3 result=$4 what=${5//$'\t'/ } tmp
    what=${what//$'\n'/ }
    tmp=$(mktemp "$file.XXXXXX") || return 1
    { [[ -r $file ]] && awk -F'\t' -v s="$step" '$1 != s' "$file"
      printf '%s\t%s\t%s\t%s\t%s\n' "$step" "$hash" "$(date '+%Y-%m-%d %H:%M:%S')" "$result" "$what"; } > "$tmp"
    mv "$tmp" "$file"
}

rl_clicks() {
    local d=$1 cmd=${USO_UI_CLICKS:-bash tools/ui-clicks.sh} force=${USO_RELEASE_FLAKY:-} f1='' f2='' rc rc2 failed routes
    shift
    mkdir -p "$d" && : > "$d/flaky"
    if [[ -n $force ]]; then f1=${force%%:*}; [[ $force == *:twice ]] && f2=$f1; fi
    UC_FORCE_FAIL=$f1 $cmd "$@" > "$d/first" 2>&1
    rc=$?
    { echo "\$ bash tools/ui-clicks.sh $*${f1:+   (USO_RELEASE_FLAKY: one page of $f1 forced red)}"; cat "$d/first"; } > "$d/out"
    grep -E '^ui clicks:' "$d/first" | tail -n 1 > "$d/summary"
    if ((rc == 1)); then
        failed=$(sed -n 's/^ui clicks failed: //p' "$d/first")
        if [[ -z $failed ]]; then
            echo "(no «ui clicks failed:» line - nothing to run again)" >> "$d/out"
        else
            routes=$(cut -d'|' -f1 <<<"$failed" | sort -u | paste -sd, -)
            { echo; echo "\$ bash tools/ui-clicks.sh --only $routes   (once more: the red page(s) alone)${f2:+   (USO_RELEASE_FLAKY: forced red again)}"; } >> "$d/out"
            UC_FORCE_FAIL=$f2 $cmd --only "$routes" > "$d/second" 2>&1
            rc2=$?
            cat "$d/second" >> "$d/out"
            if ((rc2 == 0)); then
                rc=0
                awk -F'|' '{printf "flaky: %s (%s %s)\n", $2, $3, $4}' <<<"$failed" > "$d/flaky"
                printf '%s (%s page(s) red once, green again alone - flaky)\n' \
                    "$(cat "$d/summary")" "$(wc -l < "$d/flaky" | tr -d ' ')" > "$d/summary"
            else
                printf '%s; red again alone: %s\n' "$(cat "$d/summary")" \
                    "$(sed -n 's/^ui clicks failed: //p' "$d/second" | awk -F'|' '{printf "%s%s (%s %s)", (NR > 1 ? ", " : ""), $2, $3, $4}')" > "$d/summary"
            fi
        fi
    fi
    rm -f "$d/first" "$d/second"
    echo "$rc" > "$d/rc"
    return "$rc"
}
