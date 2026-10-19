#!/bin/bash
# tools/scenarios/states-kopia.sh — Kopia and the offsite on Tower, the G states (KP-*) of zustaende.md (H4, wave 1b).
# Sourced by lib.sh; uses states-common.sh and states-apps.sh (ct_remove, rebuild). Tower only (Docker, the «kopia»
# container — imagegenius, the Consultant's template — and a MinIO of its own). Secrets only from scenarios.env, never
# on a command line: passwords and keys go into `docker exec -i` on stdin.
#
#   minio_make            the container uso-minio (community build pgsty/minio — MinIO's own images are gone), data in
#                         appdata/uso-minio, port 9010 on Tower; bucket «uso» with Object Lock (and so versioning).
#                         MINIO_ROOT_PASSWORD is made into scenarios.env when missing
#   minio_stop|minio_start <id>     noted / back          minio_quota <id> [size=100MiB] / minio_unquota <id>
#   kopia_bind            Tower's kopia template binds the image's VOLUMEs /backups and /source (empty folders under
#                         appdata/kopia/volumes) — a re-made container is then the same container (no new anonymous
#                         volume); once, from base.sh step 4
#   kopia_make            the kopia container from its template (Unraid's rebuild_container), started, its server up
#   kopia_stop|kopia_start <id>     docker stop/start, noted
#   kopia_remove <id>     removed, the template stays (ct_remove … keep-template) — undo: kopia_make
#   kopia_use <id> fs|s3|s3-lock    connected to Tower's filesystem repository (the base: /config/test-repo), or to
#                         MinIO (prefix plain/ — or lock/, made with Object Lock GOVERNANCE 7 d); the old one noted;
#                         undo: kopia_use_back <id>. The container is restarted (its server reopens the repository)
#   kopia_disconnect <id> / kopia_connect <id>      `kopia repository disconnect`, the container restarted / connected
#                         back to what it was
#   kopia_empty <id>      the filesystem repository's files moved aside (same folder's parent, instant) — Tower's or USOTest3VM's
#                         throwaway repository only / kopia_unempty <id>
#   kopia_orphans_make <id>   three old sources of units nobody knows (/uso/uso-gone-share, /uso/.apps/uso-gone-app,
#                         /uso/.vms/uso-gone-vm), each with one snapshot of an empty folder (--override-source) under
#                         Kopia's own identity / kopia_orphans_remove <id> (their snapshots deleted, nothing else)
#   kopia_mlock <id> / kopia_unmlock <id>    Kopia's maintenance lock (repository.config.mlock) held — as by a long
#                         maintenance of another process
#
# States: KP-CONTAINER-STOPPED KP-CONTAINER-GONE KP-DISCONNECTED KP-S3-UNREACHABLE KP-S3-QUOTA-FULL KP-OBJECT-LOCK
# KP-EMPTIED KP-ORPHANS KP-LOCKED-REPO KP-MAPPING-CHANGED. Never `maintenance run --full --safety=none` (CLAUDE.md).

ST_KOPIA=kopia
ST_MINIO=uso-minio
ST_MINIO_IMAGE=pgsty/minio:RELEASE.2026-08-04T00-00-00Z
ST_MINIO_PORT=9010
ST_MINIO_USER=uso-minio-root
ST_MINIO_BUCKET=uso
ST_MINIO_DATA=/mnt/user/appdata/uso-minio
ST_KOPIA_APPDATA=/mnt/user/appdata/kopia

declare -F _st_fp_add >/dev/null || _st_fp_add() { [[ " ${ST_FP_EXTRA[*]-} " == *" $1 "* ]] || ST_FP_EXTRA+=("$1"); }
declare -F _st_keep_env >/dev/null || _st_keep_env() {
    local f=${KEEP:-/tmp/uso-keep/states}/state-${1,,}.env kv; shift
    mkdir -p "$(dirname "$f")"
    for kv in "$@"; do printf '%s\n' "$kv" >> "$f"; done
}

_st_ct_up() { [[ $(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null) == true ]]; }
_st_kx() { timeout "${KX_T:-120}" docker exec "$ST_KOPIA" kopia --no-progress "$@"; }
# _st_kx_secret <stdin lines> -- <kopia args…>: KOPIA_PASSWORD, AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY read from stdin
_st_kx_secret() {
    timeout "${KX_T:-180}" docker exec -i "$ST_KOPIA" sh -c 'IFS= read -r KOPIA_PASSWORD; IFS= read -r AWS_ACCESS_KEY_ID
        IFS= read -r AWS_SECRET_ACCESS_KEY; export KOPIA_PASSWORD AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
        [ -n "$AWS_ACCESS_KEY_ID" ] || unset AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
        exec kopia --no-progress "$@"' sh "$@"
}
_st_kopia_secrets() { # the three lines for _st_kx_secret: repository password, then (s3) the key pair
    printf '%s\n' "$(_st_env KOPIA_REPO_PW)"
    if [[ ${1:-} == s3 ]]; then printf '%s\n%s\n' "$ST_MINIO_USER" "$(_st_env MINIO_ROOT_PASSWORD)"; else printf '\n\n'; fi
}
# the repository Kopia is connected to: "fs <path>" | "s3 <prefix>" | "none"
_st_kopia_where() {
    local j; j=$(KX_T=60 _st_kx repository status --json 2>/dev/null) || { echo none; return; }
    jq -r 'if .storage.type == "filesystem" then "fs \(.storage.config.path)" elif .storage.type == "s3"
        then "s3 \(.storage.config.prefix // "")" else "other \(.storage.type)" end' <<<"$j"
}
_st_kopia_id() { KX_T=60 _st_kx repository status --json 2>/dev/null | jq -r '"\(.clientOptions.username) \(.clientOptions.hostname)"'; }
_st_kopia_server_up() { timeout 10 docker exec "$ST_KOPIA" sh -c 'nc -z 127.0.0.1 51515' >/dev/null 2>&1; }
_st_kopia_restart() {
    timeout 120 docker restart "$ST_KOPIA" >/dev/null || return 1
    _st_until 90 _st_kopia_server_up || { say "kopia: its server isn't up 90 s after the restart"; return 1; }
}
_st_mc() { timeout 60 docker exec "$ST_MINIO" sh -c 'mc alias set local http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null 2>&1 && mc "$@"' sh "$@"; }
_st_tower_ip() { ip -4 -o addr show br0 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | head -1; }

_st_fp_kopia() {
    echo "## kopia"
    timeout 10 docker info >/dev/null 2>&1 || { echo "docker off"; return 0; }
    if _st_ct_up "$ST_KOPIA"; then
        echo "kopia repository: $(_st_kopia_where)"
        KX_T=60 _st_kx snapshot list --all --json 2>/dev/null | jq -r '[.[].source | "\(.userName)@\(.host):\(.path)"] | group_by(.)[] | "source \(.[0]) \(length)"'
        KX_T=30 _st_kx maintenance info --json 2>/dev/null | jq -r '"maintenance owner=\(.owner) full=\(.full.enabled)"'
    else
        echo "kopia: $(docker inspect -f '{{.State.Status}}' "$ST_KOPIA" 2>/dev/null || echo missing)"
    fi
    echo "minio: $(docker inspect -f '{{.State.Status}}' "$ST_MINIO" 2>/dev/null || echo missing)"
    _st_ct_up "$ST_MINIO" && _st_mc quota info "local/$ST_MINIO_BUCKET" 2>/dev/null | sed 's/^/minio quota /' | head -2
    pgrep -f '[u]so-kopia-mlock' >/dev/null && echo "kopia mlock held"
    ls -d "$ST_KOPIA_APPDATA"/.uso-keep-* 2>/dev/null | sed 's/^/aside /'
}
_st_fp_add _st_fp_kopia

# --------------------------------------------------------------------------------------------------------- MinIO
minio_make() {
    if docker inspect "$ST_MINIO" >/dev/null 2>&1; then
        _st_ct_up "$ST_MINIO" || docker start "$ST_MINIO" >/dev/null || return 1
    else
        if [[ -z $(_st_env MINIO_ROOT_PASSWORD) ]]; then
            [[ -w $ST_SCENARIOS_ENV ]] || { say "minio_make: no $ST_SCENARIOS_ENV to keep its password in"; return 1; }
            printf 'MINIO_ROOT_PASSWORD=%s\n' "$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')" >> "$ST_SCENARIOS_ENV"
            chmod 600 "$ST_SCENARIOS_ENV"
            say "minio_make: a new MINIO_ROOT_PASSWORD kept in scenarios.env"
        fi
        mkdir -p "$ST_MINIO_DATA" || return 1
        timeout 600 docker pull -q "$ST_MINIO_IMAGE" >/dev/null || { say "minio_make: pull of $ST_MINIO_IMAGE failed"; return 1; }
        docker run -d --name "$ST_MINIO" --restart no -p "$ST_MINIO_PORT:9000" -v "$ST_MINIO_DATA:/data" \
            --env-file <(printf 'MINIO_ROOT_USER=%s\nMINIO_ROOT_PASSWORD=%s\n' "$ST_MINIO_USER" "$(_st_env MINIO_ROOT_PASSWORD)") \
            --label net.unraid.docker.icon=https://raw.githubusercontent.com/minio/minio/master/.github/logo.svg \
            --label uso.scenarios=minio "$ST_MINIO_IMAGE" server /data >/dev/null || { say "minio_make: docker run failed"; return 1; }
    fi
    _st_until 60 _st_mc ls local || { say "minio_make: MinIO doesn't answer"; return 1; }
    _st_mc mb --ignore-existing --with-lock "local/$ST_MINIO_BUCKET" >/dev/null || { say "minio_make: no bucket"; return 1; }
    say "minio_make: $ST_MINIO up, bucket $ST_MINIO_BUCKET (Object Lock) at $(_st_tower_ip):$ST_MINIO_PORT"
}
minio_stop() {
    local dir; dir=$(_st_dir "$1")
    _st_ct_up "$ST_MINIO" || { say "minio_stop: $ST_MINIO isn't running"; return 0; }
    printf 'running\n' > "$dir/minio.was"
    timeout 60 docker stop "$ST_MINIO" >/dev/null
}
minio_start() {
    local dir; dir=$(_st_dir "$1")
    [[ -f $dir/minio.was ]] || return 0
    docker start "$ST_MINIO" >/dev/null && _st_until 60 _st_mc ls local && rm -f "$dir/minio.was"
}
minio_quota() {
    local dir; dir=$(_st_dir "$1")
    _st_mc quota set "local/$ST_MINIO_BUCKET" --size "${2:-100MiB}" >/dev/null || { say "minio_quota: refused"; return 1; }
    printf '%s\n' "${2:-100MiB}" > "$dir/minio.quota"
    say "minio_quota: bucket $ST_MINIO_BUCKET limited to ${2:-100MiB}"
}
minio_unquota() {
    local dir; dir=$(_st_dir "$1")
    [[ -f $dir/minio.quota ]] || return 0
    _st_mc quota clear "local/$ST_MINIO_BUCKET" >/dev/null && rm -f "$dir/minio.quota"
}

# --------------------------------------------------------------------------------------------- the kopia container
_st_kopia_tpl() { printf '%s/my-%s.xml' "$ST_TEMPLATES" "$ST_KOPIA"; }
kopia_bind() {
    local tpl t add='' v; tpl=$(_st_kopia_tpl)
    [[ -f $tpl ]] || { say "kopia_bind: no template $tpl"; return 1; }
    for v in backups source; do
        grep -q "Target=\"/$v\"" "$tpl" && continue
        mkdir -p "$ST_KOPIA_APPDATA/volumes/$v" || return 1
        add+="  <Config Name=\"Path: /$v\" Target=\"/$v\" Default=\"$ST_KOPIA_APPDATA/volumes/$v\" Mode=\"rw\" Description=\"The image's volume /$v, bound (scenarios: no anonymous volume)\" Type=\"Path\" Display=\"advanced\" Required=\"false\" Mask=\"false\">$ST_KOPIA_APPDATA/volumes/$v</Config>\n"
    done
    [[ -z $add ]] && { say "kopia_bind: /backups and /source are bound already"; return 0; }
    t=$tpl.uso-new
    awk -v add="$add" '/<\/Container>/ { printf "%s", add } { print }' "$tpl" > "$t" && mv "$t" "$tpl" || return 1
    local old; old=$(docker inspect -f '{{range .Mounts}}{{if eq .Type "volume"}}{{.Name}} {{end}}{{end}}' "$ST_KOPIA" 2>/dev/null)
    kopia_make || return 1
    for v in $old; do
        [[ -z $(ls -A "/var/lib/docker/volumes/$v/_data" 2>/dev/null) ]] && docker volume rm "$v" >/dev/null 2>&1
    done
    say "kopia_bind: /backups and /source bound under $ST_KOPIA_APPDATA/volumes (the old empty volumes removed)"
}
kopia_make() {
    local tpl; tpl=$(_st_kopia_tpl)
    [[ -f $tpl ]] || { say "kopia_make: no template $tpl"; return 1; }
    if docker inspect "$ST_KOPIA" >/dev/null 2>&1; then timeout 60 docker rm -f "$ST_KOPIA" >/dev/null || return 1; fi
    _st_clean "$ST_REBUILD" "$ST_KOPIA" >/dev/null 2>&1
    docker inspect "$ST_KOPIA" >/dev/null 2>&1 || { say "kopia_make: Unraid's rebuild_container made no $ST_KOPIA"; return 1; }
    _st_ct_up "$ST_KOPIA" || docker start "$ST_KOPIA" >/dev/null || return 1
    _st_until 90 _st_kopia_server_up || { say "kopia_make: its server isn't up"; return 1; }
    say "kopia_make: $ST_KOPIA made from its template, connected to: $(_st_kopia_where)"
}
kopia_stop() {
    local dir; dir=$(_st_dir "$1")
    _st_ct_up "$ST_KOPIA" || { say "kopia_stop: $ST_KOPIA isn't running"; return 0; }
    printf 'running\n' > "$dir/kopia.was"
    timeout 60 docker stop "$ST_KOPIA" >/dev/null
}
kopia_start() {
    local dir; dir=$(_st_dir "$1")
    [[ -f $dir/kopia.was ]] || return 0
    docker start "$ST_KOPIA" >/dev/null && _st_until 90 _st_kopia_server_up && rm -f "$dir/kopia.was"
}
kopia_remove() {
    local dir; dir=$(_st_dir "$1")
    docker inspect -f '{{range .Mounts}}{{.Type}} {{end}}' "$ST_KOPIA" 2>/dev/null | grep -qw volume \
        && { say "kopia_remove: kopia has anonymous volumes (a re-made one wouldn't be the same) — kopia_bind first (base.sh step 4)"; return 1; }
    printf 'removed\n' > "$dir/kopia.removed"
    timeout 60 docker rm -f "$ST_KOPIA" >/dev/null || return 1
    say "kopia_remove: $ST_KOPIA removed, its template stays"
}
kopia_unremove() {
    local dir; dir=$(_st_dir "$1")
    [[ -f $dir/kopia.removed ]] || return 0
    docker inspect "$ST_KOPIA" >/dev/null 2>&1 || kopia_make || return 1
    rm -f "$dir/kopia.removed"
}

# ------------------------------------------------------------------------------------------------- the repository
# _st_kopia_connect fs <path> | s3 <prefix> [lock] — connected (made first when the S3 prefix holds none), restarted
_st_kopia_connect() {
    local kind=$1 where=$2 lock=${3:-} u h ip args
    read -r u h < <(_st_kopia_id); u=${u:-root}; [[ $h == null || -z $h ]] && h=kopia; [[ $u == null ]] && u=root
    KX_T=60 _st_kx repository disconnect >/dev/null 2>&1
    if [[ $kind == fs ]]; then
        _st_kopia_secrets fs | _st_kx_secret repository connect filesystem --path="$where" \
            --override-username="$u" --override-hostname="$h" >/dev/null 2>&1 || { say "kopia: no connection to $where"; return 1; }
    else
        _st_ct_up "$ST_MINIO" || minio_make || return 1
        ip=$(_st_tower_ip)
        args=(s3 --bucket="$ST_MINIO_BUCKET" --endpoint="$ip:$ST_MINIO_PORT" --disable-tls --prefix="$where")
        if ! _st_mc stat "local/$ST_MINIO_BUCKET/${where}kopia.repository" >/dev/null 2>&1; then
            local ret=()
            [[ $lock == lock ]] && ret=(--retention-mode=GOVERNANCE --retention-period=168h)
            _st_kopia_secrets s3 | _st_kx_secret repository create "${args[@]}" "${ret[@]}" \
                --override-username="$u" --override-hostname="$h" >/dev/null 2>&1 || { say "kopia: no repository made at s3:$where"; return 1; }
            say "kopia: a repository made in MinIO (prefix $where${lock:+, Object Lock GOVERNANCE 7 d, extended at full maintenance})"
        else
            _st_kopia_secrets s3 | _st_kx_secret repository connect "${args[@]}" \
                --override-username="$u" --override-hostname="$h" >/dev/null 2>&1 || { say "kopia: no connection to s3:$where"; return 1; }
        fi
        # the Consultant's Object Lock guide: Kopia extends the locks only when told so (off in a new repository)
        if [[ $lock == lock ]]; then
            KX_T=60 _st_kx maintenance set --extend-object-locks=true >/dev/null 2>&1 || say "kopia: --extend-object-locks refused"
        fi
    fi
    _st_kopia_restart
}
kopia_use() {
    local id=$1 to=$2 dir now; dir=$(_st_dir "$id")
    _st_ct_up "$ST_KOPIA" || { say "needs kopia: the container doesn't run"; return 2; }
    now=$(_st_kopia_where)
    [[ -f $dir/kopia.where ]] || printf '%s\n' "$now" > "$dir/kopia.where"
    case $to in
        fs) [[ $now == fs* ]] && return 0; _st_kopia_connect fs "${3:-/config/test-repo}" ;;
        s3) [[ $now == "s3 plain/" ]] && return 0; _st_kopia_connect s3 plain/ ;;
        s3-lock) [[ $now == "s3 lock/" ]] && return 0; _st_kopia_connect s3 lock/ lock ;;
        *) say "kopia_use: fs|s3|s3-lock, not $to"; return 1 ;;
    esac || return 1
    say "kopia_use: $ST_KOPIA connected to $(_st_kopia_where) (was: $now)"
}
kopia_use_back() {
    local dir was; dir=$(_st_dir "$1")
    [[ -f $dir/kopia.where ]] || return 0
    was=$(cat "$dir/kopia.where")
    if [[ $(_st_kopia_where) != "$was" ]]; then
        case $was in
            fs\ *) _st_kopia_connect fs "${was#fs }" ;;
            "s3 lock/") _st_kopia_connect s3 lock/ lock ;;
            s3\ *) _st_kopia_connect s3 "${was#s3 }" ;;
            none) KX_T=60 _st_kx repository disconnect >/dev/null 2>&1; _st_kopia_restart ;;
        esac || { say "kopia_use_back: not back to $was"; return 1; }
    fi
    rm -f "$dir/kopia.where"
}
kopia_disconnect() {
    local id=$1 dir; dir=$(_st_dir "$id")
    _st_kopia_where > "$dir/kopia.where"
    KX_T=60 _st_kx repository disconnect >/dev/null 2>&1 || { say "kopia_disconnect: refused"; return 1; }
    _st_kopia_restart || return 1
    [[ $(_st_kopia_where) == none ]] || { say "kopia_disconnect: still connected"; return 1; }
    say "kopia_disconnect: $ST_KOPIA runs, its repository not connected (was: $(cat "$dir/kopia.where"))"
}
kopia_connect() { kopia_use_back "$1"; }

_st_kopia_repo_host() { # the host folder of the filesystem repository (via the container's /config bind), or nothing
    local p; p=$(_st_kopia_where); [[ $p == fs\ /config/* ]] || return 1
    printf '%s/%s' "$ST_KOPIA_APPDATA" "${p#fs /config/}"
}
kopia_empty() {
    local id=$1 dir repo aside; dir=$(_st_dir "$id")
    host_is tower || host_is test3 || { say "kopia_empty: the throwaway repositories of Tower and USOTest3VM only"; return 2; }
    repo=$(_st_kopia_repo_host) || { say "kopia_empty: Kopia isn't on the filesystem repository"; return 1; }
    aside=$ST_KOPIA_APPDATA/.uso-keep-${id,,}
    [[ -e $aside ]] && { say "kopia_empty: $aside exists"; return 1; }
    mkdir -p "$aside" && printf '%s\n%s\n' "$repo" "$aside" > "$dir/kopia.emptied" || return 1
    find "$repo" -mindepth 1 -maxdepth 1 -exec mv -t "$aside" -- {} + || return 1
    say "kopia_empty: $repo emptied ($(ls -A "$aside" | wc -l) entries aside in $aside)"
}
kopia_unempty() {
    local dir repo aside; dir=$(_st_dir "$1")
    [[ -f $dir/kopia.emptied ]] || return 0
    { read -r repo; read -r aside; } < "$dir/kopia.emptied"
    find "$repo" -mindepth 1 -maxdepth 1 -newer "$dir/kopia.emptied" -print 2>/dev/null | head -1 | grep -q . \
        && say "kopia_unempty: Kopia wrote into the empty repository meanwhile — those blobs are dropped"
    find "$repo" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
    find "$aside" -mindepth 1 -maxdepth 1 -exec mv -t "$repo" -- {} + && rmdir "$aside" || return 1
    _st_kopia_restart && rm -f "$dir/kopia.emptied"
}

ST_KOPIA_ORPHANS=(/uso/uso-gone-share /uso/.apps/uso-gone-app /uso/.vms/uso-gone-vm)
kopia_orphans_make() {
    local id=$1 dir s; dir=$(_st_dir "$id")
    _st_ct_up "$ST_KOPIA" && [[ $(_st_kopia_where) != none ]] || { say "needs kopia connected"; return 2; }
    timeout 20 docker exec "$ST_KOPIA" mkdir -p /tmp/uso-orphan || return 1
    for s in "${ST_KOPIA_ORPHANS[@]}"; do
        printf '%s\n' "$s" >> "$dir/kopia.orphans"
        _st_kx snapshot create /tmp/uso-orphan --override-source="$s" --description="uso scenario: an old source" >/dev/null 2>&1 \
            || { say "kopia_orphans_make: no snapshot for $s"; return 1; }
    done
    timeout 20 docker exec "$ST_KOPIA" rmdir /tmp/uso-orphan
    say "kopia_orphans_make: old sources ${ST_KOPIA_ORPHANS[*]} (one snapshot each)"
}
kopia_orphans_remove() {
    local dir s src rc=0; dir=$(_st_dir "$1")
    [[ -f $dir/kopia.orphans ]] || return 0
    while read -r s; do
        [[ -z $s ]] && continue
        for src in $(_st_kx snapshot list --all --json 2>/dev/null | jq -r --arg p "$s" '[.[].source | select(.path == $p) | "\(.userName)@\(.host):\(.path)"] | unique[]'); do
            _st_kx snapshot delete --all-snapshots-for-source "$src" --delete >/dev/null 2>&1 || rc=1
        done
    done < "$dir/kopia.orphans"
    ((rc == 0)) && rm -f "$dir/kopia.orphans"
    return $rc
}

kopia_mlock() {
    local id=$1 f
    f=$(readlink -f "$ST_KOPIA_APPDATA" 2>/dev/null)/repository.config.mlock
    [[ $f == /mnt/user/* ]] && f=/mnt/$(getfattr --absolute-names --only-values -n system.LOCATION "$ST_KOPIA_APPDATA/repository.config" 2>/dev/null)/${ST_KOPIA_APPDATA#/mnt/user/}/repository.config.mlock
    [[ -d $(dirname "$f") ]] || { say "kopia_mlock: no $(dirname "$f")"; return 1; }
    [[ -f $f ]] || { : > "$f" && printf '%s\n' "$f" > "$(_st_dir "$id")/mlock.made"; }    # Kopia makes it at its first lock
    _st_bg bash -c 'exec -a uso-kopia-mlock flock "$1" sleep "$2"' _ "$f" "${USO_MLOCK_MAX:-1800}" > "$(_st_dir "$id")/mlock.pid"
    _st_until 10 bash -c "! flock -n '$f' true" || { say "kopia_mlock: the lock isn't held"; return 1; }
    say "kopia_mlock: Kopia's maintenance lock $f held (as by another maintenance)"
}
kopia_unmlock() {
    local dir; dir=$(_st_dir "$1")
    [[ -f $dir/mlock.pid ]] || return 0
    pkill -f '[u]so-kopia-mlock' 2>/dev/null; pkill -f 'flock .*repository.config.mlock sleep' 2>/dev/null
    _st_until 10 bash -c '! pgrep -f "[u]so-kopia-mlock"' || return 1
    [[ -f $dir/mlock.made ]] && { rm -f "$(cat "$dir/mlock.made")" "$dir/mlock.made"; }
    rm -f "$dir/mlock.pid"
}

# ------------------------------------------------------------------------------------------------------------ states
_st_kopia_need() { _st_need docker _st_docker_up || return; _st_need "the kopia container" docker inspect "$ST_KOPIA" || return; }

state_kp_container_stopped() { _st_kopia_need || return; kopia_stop KP-CONTAINER-STOPPED && _st_keep_env KP-CONTAINER-STOPPED CONTAINER=$ST_KOPIA; }
undo_kp_container_stopped() { kopia_start KP-CONTAINER-STOPPED; }

state_kp_container_gone() { _st_kopia_need || return; kopia_remove KP-CONTAINER-GONE && _st_keep_env KP-CONTAINER-GONE CONTAINER=$ST_KOPIA TEMPLATE="$(_st_kopia_tpl)"; }
undo_kp_container_gone() { kopia_unremove KP-CONTAINER-GONE; }

state_kp_disconnected() { _st_kopia_need || return; kopia_disconnect KP-DISCONNECTED && _st_keep_env KP-DISCONNECTED WAS="$(cat "$(_st_dir KP-DISCONNECTED)/kopia.where")"; }
undo_kp_disconnected() { kopia_connect KP-DISCONNECTED; }

# KP-S3-UNREACHABLE — Kopia on MinIO, MinIO stopped
state_kp_s3_unreachable() {
    _st_kopia_need || return
    kopia_use KP-S3-UNREACHABLE s3 && minio_stop KP-S3-UNREACHABLE || return 1
    _st_keep_env KP-S3-UNREACHABLE STORAGE=s3 ENDPOINT="$(_st_tower_ip):$ST_MINIO_PORT"
}
undo_kp_s3_unreachable() { minio_start KP-S3-UNREACHABLE && kopia_use_back KP-S3-UNREACHABLE; }

# KP-S3-QUOTA-FULL [size] — Kopia on MinIO, the bucket's hard quota at (or under) what it holds
state_kp_s3_quota_full() {
    _st_kopia_need || return
    kopia_use KP-S3-QUOTA-FULL s3 && minio_quota KP-S3-QUOTA-FULL "${1:-1MiB}" || return 1
    _st_keep_env KP-S3-QUOTA-FULL STORAGE=s3 QUOTA="${1:-1MiB}"
}
undo_kp_s3_quota_full() { minio_unquota KP-S3-QUOTA-FULL && kopia_use_back KP-S3-QUOTA-FULL; }

# KP-OBJECT-LOCK [ok|extend|lapse|late|never] — Kopia on MinIO's locked repository (GOVERNANCE 7 d); the variants are
# what time can't make: the engine's own look (backup.sh --kopia-lock → state/kopia-lock.json) rewritten as if the
# extension were off (extend), the locks about to run out (lapse), the last extension late (late) or never (never) —
# kept until the office's next look (BACKUP_LOCK_EVERY) asks Kopia again
state_kp_object_lock() {
    local v=${1:-ok} f dir now j
    _st_kopia_need || return
    dir=$(_st_dir KP-OBJECT-LOCK); f=$UB/state/kopia-lock.json
    kopia_use KP-OBJECT-LOCK s3-lock || return 1
    if [[ -f $f ]]; then cp -p "$f" "$dir/kopia-lock.json"; else printf 'none\n' > "$dir/kopia-lock.none"; fi
    _st_clean bash "$P/backup/backup.sh" --kopia-lock >/dev/null 2>&1
    jq -e '.ok == true and .lock != null' "$f" >/dev/null 2>&1 || { say "KP-OBJECT-LOCK: the engine's look found no lock ($(jq -c '{ok, why, lock}' "$f" 2>/dev/null))"; return 1; }
    now=$(date +%s)
    case $v in
        ok) ;;
        extend) j='.maintenance.full_enabled = false' ;;
        lapse) j=".maintenance.extend_runs = [(\$now - .lock.period_s + 3600 | todate)]" ;;
        late) j=".maintenance.extend_runs = [(\$now - 37 * 3600 | todate)] | .maintenance.full_runs = .maintenance.extend_runs" ;;
        never) j=".maintenance.extend_runs = [] | .maintenance.full_runs = [] | .maintenance.next_full = ((\$now - 14 * 3600) | todate)" ;;
        *) say "KP-OBJECT-LOCK: ok|extend|lapse|late|never, not $v"; return 1 ;;
    esac
    if [[ -n ${j:-} ]]; then
        jq -c --argjson now "$now" "$j | .time = \$now" "$f" > "$f.uso-new" && chmod 600 "$f.uso-new" && mv "$f.uso-new" "$f" || return 1
    fi
    _st_keep_env KP-OBJECT-LOCK VARIANT="$v" LOCK_MODE=GOVERNANCE LOCK_PERIOD_S=604800 \
        EXPECT="$(case $v in ok) echo none ;; *) echo "kopia_lock_$v" ;; esac)"
    say "KP-OBJECT-LOCK: $v (state/kopia-lock.json: $(jq -c '{lock, extend: .maintenance.extend, full: .maintenance.full_enabled, last: .maintenance.extend_runs[-1]}' "$f"))"
}
undo_kp_object_lock() {
    local dir f; dir=$(_st_dir KP-OBJECT-LOCK); f=$UB/state/kopia-lock.json
    if [[ -f $dir/kopia-lock.json ]]; then cp -p "$dir/kopia-lock.json" "$f" && rm -f "$dir/kopia-lock.json"
    elif [[ -f $dir/kopia-lock.none ]]; then rm -f "$f" "$dir/kopia-lock.none"; fi
    kopia_use_back KP-OBJECT-LOCK
}

state_kp_emptied() {
    _st_kopia_need || return
    kopia_empty KP-EMPTIED || return
    _st_keep_env KP-EMPTIED REPO="$(head -1 "$(_st_dir KP-EMPTIED)/kopia.emptied")"
}
undo_kp_emptied() { kopia_unempty KP-EMPTIED; }

state_kp_orphans() {
    _st_kopia_need || return
    kopia_orphans_make KP-ORPHANS || return
    _st_keep_env KP-ORPHANS SOURCES="${ST_KOPIA_ORPHANS[*]}" IDENTITY="$(_st_kopia_id | tr ' ' @)"
}
undo_kp_orphans() { kopia_orphans_remove KP-ORPHANS; }

state_kp_locked_repo() { _st_kopia_need || return; kopia_mlock KP-LOCKED-REPO && _st_keep_env KP-LOCKED-REPO MLOCK=held; }
undo_kp_locked_repo() { kopia_unmlock KP-LOCKED-REPO; }

# KP-MAPPING-CHANGED [/uso2] — the container made anew with the snapshots at another container path: new sources
state_kp_mapping_changed() {
    local to=${1:-/uso2} tpl dir; tpl=$(_st_kopia_tpl); dir=$(_st_dir KP-MAPPING-CHANGED)
    _st_kopia_need || return
    grep -q 'Target="/uso"' "$tpl" || { say "KP-MAPPING-CHANGED: the template has no /uso"; return 1; }
    docker inspect -f '{{range .Mounts}}{{.Type}} {{end}}' "$ST_KOPIA" | grep -qw volume \
        && { say "KP-MAPPING-CHANGED: kopia has anonymous volumes — kopia_bind first"; return 1; }
    cp -p "$tpl" "$dir/my-kopia.xml" || return 1
    sed -i "s|Target=\"/uso\"|Target=\"$to\"|" "$tpl" && kopia_make || return 1
    _st_keep_env KP-MAPPING-CHANGED FROM=/uso TO="$to"
}
undo_kp_mapping_changed() {
    local dir; dir=$(_st_dir KP-MAPPING-CHANGED)
    [[ -f $dir/my-kopia.xml ]] || return 0
    cp -p "$dir/my-kopia.xml" "$(_st_kopia_tpl)" && kopia_make && rm -f "$dir/my-kopia.xml"
}
