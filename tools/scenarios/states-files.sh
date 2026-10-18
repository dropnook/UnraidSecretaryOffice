#!/bin/bash
# tools/scenarios/states-files.sh — test files on a share's part, written on the disk/pool itself (never through
# /mnt/user, so it is clear which disk holds them), and taken away again (H2). Sourced by lib.sh.
#
#   put_files <id> <disk> <share>[/<dir>] <n> [--age 40d|12h] [--size 1M|4096] [--name <file>]
#             n files f<age>-0001.bin … (random content, owner nobody:users); --age: born, changed and modified that long
#             ago — the clock is set back while they are written (day-1 finding 2; a batch with --age ≤ 4 GiB)
#   put_names <id> <disk> <share>     the awkward names (umlauts, quotes, $, \, leading space/dash/dot, CJK, 255 bytes)
#   put_many  <id> <disk> <share> <n> [dir]   n empty files in <share>/<dir> (default «many»)
#   put_big   <id> <disk> <share> <GB> [name] one file of random data (dd: no sparse file, no zeros a pool compresses away)
#   open_file <id> <path>             a process keeps the file open (read+write) until close_files <id>
#   storeroom_put <id> <disk> <share> Ms. Dustdevil's storeroom in the share with one put-away run (her manifest shape)
#   files_unput <id>                  everything put_* of that state made, removed (files, folders, roots it had to make)
#   disk_of_file <path>               which disk/pool holds a /mnt/user path (getfattr system.LOCATION, no other disk asked)
#
# Every put_* notes what it made in $KEEP_STASH/<id>/files (lines «f path», «d path», «t tree», «r disk share»).

_st_note() { printf '%s\t%s\n' "$2" "$3" >> "$(_st_dir "$1")/files"; }

# _st_mkdirs <id> <path> — mkdir -p, noting each folder it made (owner nobody:users like Unraid's)
_st_mkdirs() {
    local id=$1 p=$2 todo=()
    while [[ ! -d $p ]]; do todo=("$p" "${todo[@]}"); p=${p%/*}; done
    for p in "${todo[@]}"; do
        mkdir "$p" || return 1
        chown nobody:users "$p"; chmod 0777 "$p"
        _st_note "$id" d "$p"
    done
}
# _st_part <id> <disk> <share/dir> — the folder on that disk, made (root the shfs way, noted when made)
_st_part() {
    local id=$1 d=$2 sd=$3 share=${3%%/*}
    mountpoint -q "/mnt/$d" || { say "needs disk/pool $d: not on this server"; return 2; }
    if [[ ! -e /mnt/$d/$share ]]; then
        _st_root "$d" "$share" || return 1
        _st_note "$id" r "$d"$'\t'"$share"
    fi
    _st_mkdirs "$id" "/mnt/$d/$sd"
}
_st_secs() { # 40d | 12h | 30m | 90 → seconds
    case $1 in *d) echo $(( ${1%d} * 86400 )) ;; *h) echo $(( ${1%h} * 3600 )) ;; *m) echo $(( ${1%m} * 60 )) ;; *) echo "$1" ;; esac
}
_st_bytes() { case $1 in *G) echo $(( ${1%G} << 30 )) ;; *M) echo $(( ${1%M} << 20 )) ;; *K|*k) echo $(( ${1%[Kk]} << 10 )) ;; *) echo "$1" ;; esac; }

put_files() {
    local id=$1 d=$2 sd=$3 n=$4 age='' size=1M name='' i f; shift 4
    while (($#)); do
        case $1 in --age) age=$2; shift 2 ;; --size) size=$2; shift 2 ;; --name) name=$2; shift 2 ;; *) say "put_files: $1?"; return 1 ;; esac
    done
    local bytes; bytes=$(_st_bytes "$size")
    [[ $n =~ ^[0-9]+$ && $bytes =~ ^[0-9]+$ ]] || { say "put_files: n=$n size=$size?"; return 1; }
    _st_part "$id" "$d" "$sd" || return
    local dir=/mnt/$d/$sd list=()
    for ((i = 1; i <= n; i++)); do
        if [[ -n $name ]]; then f=$dir/$name; ((n > 1)) && f=$dir/${name%.*}-$(printf %04d "$i").${name##*.}
        else f=$dir/f${age:+$age}-$(printf %04d "$i").bin; fi
        [[ -e $f ]] && { say "put_files: $f exists already"; return 1; }
        list+=("$f")
    done
    # owner and mode inside the window too: a chown afterwards would make the ctime new
    _st_write() {
        local f; for f in "${list[@]}"; do head -c "$bytes" /dev/urandom > "$f" || return 1; done
        chown nobody:users "${list[@]}"; chmod 0666 "${list[@]}"
    }
    if [[ -n $age ]]; then
        (( n * bytes <= 4 << 30 )) || { say "put_files: a batch with --age stays ≤ 4 GiB"; return 1; }
        clock_back "$(_st_secs "$age")" _st_write || return 1
    else
        _st_write || return 1
    fi
    for f in "${list[@]}"; do _st_note "$id" f "$f"; done
    say "put_files: $n × $size on $d/$sd${age:+, $age old}"
}

put_names() {
    local id=$1 d=$2 s=$3 f long
    _st_part "$id" "$d" "$s" || return
    long=$(printf 'n%.0s' {1..251}).txt
    local names=('Ümläut Ärger.txt' "it's here.mkv" '"quoted".txt' 'dollar$sign' 'back\slash' ' leading space' '-dash' '.hidden'
                 '名前.txt' "$long" 'tab	in name.txt' 'semi;colon & amp.txt')
    for f in "${names[@]}"; do
        [[ -e /mnt/$d/$s/$f ]] && continue
        head -c 4096 /dev/urandom > "/mnt/$d/$s/$f" || return 1
        chown nobody:users "/mnt/$d/$s/$f"
        _st_note "$id" f "/mnt/$d/$s/$f"
    done
    say "put_names: ${#names[@]} awkward names in $d/$s"
}

put_many() {
    local id=$1 d=$2 s=$3 n=$4 sub=${5:-many}
    _st_part "$id" "$d" "$s" || return
    [[ -e /mnt/$d/$s/$sub ]] && { say "put_many: /mnt/$d/$s/$sub exists already"; return 1; }
    mkdir "/mnt/$d/$s/$sub" && _st_note "$id" t "/mnt/$d/$s/$sub" || return 1
    (cd "/mnt/$d/$s/$sub" && seq -f 'f%06g' 1 "$n" | xargs -P 8 -n 500 touch) || return 1
    chown -R nobody:users "/mnt/$d/$s/$sub"
    say "put_many: $n files in $d/$s/$sub"
}

put_big() {
    local id=$1 d=$2 s=$3 gb=$4 name=${5:-big.bin}
    _st_part "$id" "$d" "$s" || return
    local f=/mnt/$d/$s/$name
    [[ -e $f ]] && { say "put_big: $f exists already"; return 1; }
    (( $(df --output=avail -B1G "/mnt/$d" | tail -1) > gb + 2 )) || { say "put_big: no room for $gb GB on $d"; return 1; }
    _st_note "$id" f "$f"
    dd if=/dev/urandom of="$f" bs=1M count=$((gb * 1024)) status=none || return 1
    chown nobody:users "$f"
    say "put_big: $gb GB in $d/$s/$name"
}

open_file() {
    local id=$1 path=$2 pid
    [[ -e $path ]] || { say "open_file: no $path"; return 1; }
    pid=$(_st_bg bash -c 'exec 3<>"$1"; exec -a uso-open-hold sleep 3600' _ "$path")
    _st_until 5 bash -c "ls -l /proc/$pid/fd 2>/dev/null | grep -qF -- '$path'" || { say "open_file: $path not held open"; kill "$pid" 2>/dev/null; return 1; }
    printf '%s\n' "$pid" >> "$(_st_dir "$id")/open.pids"
    say "open_file: $path held open (pid $pid)"
}
close_files() {
    local f p; f=$(_st_dir "$1")/open.pids
    [[ -f $f ]] || return 0
    while read -r p; do
        [[ $(cat "/proc/$p/cmdline" 2>/dev/null | tr '\0' ' ') == uso-open-hold* ]] && kill "$p"
        _st_until 5 test ! -d "/proc/$p" || return 1
    done < "$f"
    rm -f "$f"
}

storeroom_put() {
    local id=$1 d=$2 s=$3 run
    _st_part "$id" "$d" "$s" || return
    local root=/mnt/$d/$s/.UnraidSecretaryOffice-trash stamp=20260901-120000
    [[ -e $root ]] && { say "storeroom_put: $root exists already"; return 1; }
    run=$root/$stamp
    mkdir -p "$run/appdata/uso-old-app/config" && _st_note "$id" t "$root" || return 1
    head -c 65536 /dev/urandom > "$run/appdata/uso-old-app/config/settings.db"
    printf 'old app\n' > "$run/appdata/uso-old-app/config/notes.txt"
    jq -n --arg from "/mnt/user/$s/uso-old-app" --arg host "$(hostname)" '{
        written_by: "Unraid Secretary Office (Ms. Dustdevil)",
        note: "Put back by renaming each \"as\" (relative to this folder) to \"from\".",
        version: 1, time: 1788264000, host: $host,
        items: [{kind: "appdata", name: "uso-old-app", label: "uso-old-app", from: $from, as: "appdata/uso-old-app"}]}' > "$run/manifest.json"
    chown -R nobody:users "$root"
    say "storeroom_put: one put-away run in $d/$s/.UnraidSecretaryOffice-trash"
}

files_unput() {
    local f; f=$(_st_dir "$1")/files
    [[ -f $f ]] || return 0
    local kind a b rc=0 ds
    while IFS=$'\t' read -r kind a b; do
        case $kind in
            f) rm -f -- "$a" ;;
            t) rm -rf -- "$a" ;;
            d) rmdir -- "$a" 2>/dev/null || { [[ -d $a ]] && { say "files_unput: $a holds something else — left"; rc=1; }; } ;;
            r) [[ -d /mnt/$a/$b ]] || continue
               if [[ -n $(ls -A "/mnt/$a/$b") ]]; then say "files_unput: /mnt/$a/$b holds something else — left"; rc=1; continue; fi
               ds=$(_st_dataset_of "/mnt/$a/$b")
               if [[ -n $ds ]]; then zfs destroy "$ds" || rc=1; else rmdir "/mnt/$a/$b" || rc=1; fi ;;
        esac
    done < <(tac "$f")
    ((rc == 0)) && rm -f "$f"
    return $rc
}

disk_of_file() { getfattr -n system.LOCATION --only-values --absolute-names "$1" 2>/dev/null; }
