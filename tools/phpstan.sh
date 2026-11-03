#!/bin/bash
# PHPStan over the office's PHP (agent/, src/, public/*.php, tests/run.php — phpstan.neon at the top of the
# repository says which, and at which level; phpstan-baseline.neon holds what was there when it came).
#
#   bash tools/phpstan.sh                       analyse: exit 0 = nothing new, 1 = new findings (PHPStan's own code)
#   bash tools/phpstan.sh --generate-baseline   write phpstan-baseline.neon anew (only after reading what goes in)
#   bash tools/phpstan.sh <more options>        handed to `phpstan analyse` (e.g. --level 6, --error-format=github)
#
# No composer, no vendor/: one pinned phpstan.phar, its SHA-256 checked, cached outside the repository
# (~/.cache/uso-phpstan/ or $USO_PHPSTAN_CACHE). Never part of the plugin's package (plugin/build.sh takes only its own
# folders). Runs on the Mac and on an Unraid server (bash, curl, php 8.4) and in .github/workflows/phpstan.yml.

set -euo pipefail
cd "$(dirname "$0")/.."

version=2.3.4
sha256=a961d12cc71941a31ad6560e6a330347d487612628c3202a718f71a59de21a7c
cache=${USO_PHPSTAN_CACHE:-${XDG_CACHE_HOME:-$HOME/.cache}/uso-phpstan}
phar="$cache/phpstan-$version.phar"

sum() { (sha256sum "$1" 2>/dev/null || shasum -a 256 "$1") | cut -d' ' -f1; }

if [[ ! -f "$phar" || "$(sum "$phar")" != "$sha256" ]]; then
    mkdir -p "$cache"
    tmp=$(mktemp "$cache/phpstan.XXXXXX")
    trap 'rm -f "$tmp"' EXIT
    curl -fsSL --retry 3 -o "$tmp" "https://github.com/phpstan/phpstan/releases/download/$version/phpstan.phar"
    got=$(sum "$tmp")
    [[ "$got" == "$sha256" ]] || { echo "phpstan.phar $version: SHA-256 $got, expected $sha256 — not used" >&2; exit 2; }
    mv "$tmp" "$phar"
    trap - EXIT
fi

exec php "$phar" analyse --configuration=phpstan.neon --memory-limit=2G --no-progress "$@"
