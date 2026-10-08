#!/bin/bash
# Unraid Secretary Office — supporter keys (for the maintainer only).
#
#   tools/supporter-key.sh [--level <level>] <server-id> "<name>" [YYYY-MM-DD]   prints a new key (date: today)
#   tools/supporter-key.sh --verify <key> [<server-id>]         checks a key, says what it carries
#
# A supporter key unlocks nothing — the office is free and complete. It only says
# thank you: no more reminders, a small thank-you at the team lead. The server ID is
# what the tip jar shows (XXXX-XXXX-XXXX-XXXX); the format is described and checked in
# src/supporter.php. The level is the thank-you's picture, by the tip (any of USD/EUR/CHF):
# coffee (default, any tip) · round (from 20) · cake (from 50) · raise (from 100);
# coffee is written as no level at all, so such a key is one any office version takes.
#
#   USO_SUPPORTER_KEY  the private key (PEM, EC P-256), default
#                      ~/.config/uso-supporter/supporter-private.pem — it stays on the
#                      maintainer's machine: never in the repository, never on a server
#   USO_SUPPORTER_PUB  the public key to check against (PEM file), default the one in
#                      src/supporter.php — a new key is printed only when it verifies there
#
# Needs bash and openssl (plus iconv, tr, wc, sed; perl, if there, for a stricter name check).
set -euo pipefail

private=${USO_SUPPORTER_KEY:-$HOME/.config/uso-supporter/supporter-private.pem}
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

die() { echo "supporter-key: $*" >&2; exit 1; }
usage() {
  sed -n '4,5p' "${BASH_SOURCE[0]}" | sed 's/^# *//' >&2
  echo "levels: coffee (default) | round | cake | raise" >&2
  exit 2
}

work=$(mktemp -d "${TMPDIR:-/tmp}/uso-supporter.XXXXXX")
trap 'rm -rf "$work"' EXIT

b64url() { openssl base64 -A | tr -- '+/' '-_' | tr -d '='; }
# base64url (no padding) on stdin → the bytes; fails on anything else
b64url_decode() {
  local s
  s=$(tr -- '-_' '+/')
  [[ $s =~ ^[A-Za-z0-9+/]+$ ]] || return 1
  case $(( ${#s} % 4 )) in 1) return 1;; 2) s+='==';; 3) s+='=';; esac
  printf '%s' "$s" | openssl base64 -d -A
}

# the public key the office checks against
public_key() {
  if [[ -n ${USO_SUPPORTER_PUB:-} ]]; then
    [[ -r $USO_SUPPORTER_PUB ]] || die "USO_SUPPORTER_PUB: $USO_SUPPORTER_PUB can't be read"
    cp "$USO_SUPPORTER_PUB" "$work/public.pem"
  else
    local src="$here/../src/supporter.php"
    [[ -r $src ]] || die "$src not found — run the tool from the repository or set USO_SUPPORTER_PUB"
    sed -n '/-----BEGIN PUBLIC KEY-----/,/-----END PUBLIC KEY-----/p' "$src" > "$work/public.pem"
  fi
  openssl pkey -pubin -in "$work/public.pem" -noout 2>/dev/null || die "no usable public key"
  printf '%s' "$work/public.pem"
}

# 1–60 printable characters, valid UTF-8, no space at either end — like officeSupporterNameOk()
name_ok() {
  local n=$1 chars
  [[ -n $n ]] || return 1
  [[ $n == "${n#[[:space:]]}" && $n == "${n%[[:space:]]}" ]] || return 1
  printf '%s' "$n" | iconv -f UTF-8 -t UTF-8 >/dev/null 2>&1 || return 1
  ( LC_ALL=C; [[ $n != *[[:cntrl:]]* && $n != *$'\xc2'[$'\x80'-$'\x9f']* ]] ) || return 1
  chars=$(printf '%s' "$n" | LC_ALL=C tr -d '\200-\277' | wc -c)
  (( chars <= 60 )) || return 1
  if command -v perl >/dev/null 2>&1; then
    perl -CA -e 'exit($ARGV[0] =~ /\A[^\p{C}\p{Zl}\p{Zp}]+\z/ ? 0 : 1)' -- "$n" || return 1
  fi
}

# YYYY-MM-DD and a day the calendar has
date_ok() {
  [[ $1 =~ ^([0-9]{4})-([0-9]{2})-([0-9]{2})$ ]] || return 1
  local y=$((10#${BASH_REMATCH[1]})) m=$((10#${BASH_REMATCH[2]})) d=$((10#${BASH_REMATCH[3]})) last=31
  (( m >= 1 && m <= 12 && d >= 1 )) || return 1
  case $m in 4|6|9|11) last=30;; 2) last=28; (( (y % 4 == 0 && y % 100 != 0) || y % 400 == 0 )) && last=29;; esac
  (( d <= last ))
}

# <key> [<server-id>] — 0 when the signature holds (and the key is for that server, if given)
verify() {
  local key want=${2:-} pub payload id
  key=$(printf '%s' "$1" | tr -d '[:space:]')
  want=$(printf '%s' "$want" | tr 'a-f' 'A-F')
  [[ ${#key} -le 1000 && $key =~ ^USO1\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$ ]] \
    || { echo "not a supporter key (USO1.<payload>.<signature>)" >&2; return 1; }
  local p=${BASH_REMATCH[1]} s=${BASH_REMATCH[2]}
  pub=$(public_key) || return 1
  printf '%s' "USO1.$p" > "$work/verify.msg"
  printf '%s' "$s" | b64url_decode > "$work/verify.sig" 2>/dev/null || { echo "signature: not base64url" >&2; return 1; }
  if ! openssl dgst -sha256 -verify "$pub" -signature "$work/verify.sig" "$work/verify.msg" >/dev/null 2>&1; then
    echo "signature: NOT valid for the office's public key" >&2
    return 1
  fi
  payload=$(printf '%s' "$p" | b64url_decode 2>/dev/null) || { echo "payload: not base64url" >&2; return 1; }
  echo "signature: valid"
  echo "payload:   $payload"
  [[ $payload =~ ^\{\"v\":1,\"id\":\"([0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4})\",\"name\":\".*\",\"date\":\"[0-9]{4}-[0-9]{2}-[0-9]{2}\"(,\"l\":\"(round|cake|raise)\")?\}$ ]] \
    || { echo "payload: not in the office's shape" >&2; return 1; }
  id=${BASH_REMATCH[1]}
  echo "level:     ${BASH_REMATCH[3]:-coffee}"
  if [[ -n $want && $id != "$want" ]]; then
    echo "server:    for $id, not for $want" >&2
    return 1
  fi
}

sign() {
  local id name=$2 date=${3:-} payload head key extra=
  id=$(printf '%s' "$1" | tr 'a-f' 'A-F')
  [[ -n $date ]] || date=$(date +%F)
  [[ $id =~ ^[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$ ]] || die "the server ID looks like XXXX-XXXX-XXXX-XXXX (16 hex digits, from the tip jar)"
  name_ok "$name" || die "the name: 1–60 printable characters, no space at either end"
  date_ok "$date" || die "the date: YYYY-MM-DD"
  case $level in
    coffee) ;;                                     # no l: a coffee key, the same bytes as before levels
    round|cake|raise) extra=",\"l\":\"$level\"" ;;
    *) die "the level: coffee, round, cake or raise" ;;
  esac
  [[ -r $private ]] || die "no private key at $private (USO_SUPPORTER_KEY)"
  local esc=${name//\\/\\\\}
  esc=${esc//\"/\\\"}
  payload="{\"v\":1,\"id\":\"$id\",\"name\":\"$esc\",\"date\":\"$date\"$extra}"
  head="USO1.$(printf '%s' "$payload" | b64url)"
  printf '%s' "$head" > "$work/sign.msg"
  openssl dgst -sha256 -sign "$private" -out "$work/sign.sig" "$work/sign.msg" 2>/dev/null || die "signing failed (is $private an EC P-256 private key?)"
  key="$head.$(b64url < "$work/sign.sig")"
  verify "$key" "$id" >/dev/null || die "the new key doesn't verify against the office's public key — the wrong private key?"
  printf '%s\n' "$key"
}

level=coffee
if [[ ${1:-} == --level ]]; then
  (( $# >= 2 )) || usage
  level=$2
  shift 2
fi

case ${1:-} in
  --verify) (( $# >= 2 && $# <= 3 )) && [[ $level == coffee ]] || usage; verify "$2" "${3:-}" ;;
  ''|-h|--help) usage ;;
  -*) usage ;;
  *) (( $# >= 2 && $# <= 3 )) || usage; sign "$@" ;;
esac
