#!/bin/bash
# The click test (tools/ui-clicks.mjs) on this Mac: every desk page built from this clone's files, clicked in a headless
# Chrome with a throwaway profile. Arguments go through (--only advisor,backup/setup  --themes dark  --widths 375  -v).
# Exit: 0 green, 1 a failure (the table says which), 2 wrong call, 3 not run - no node, playwright-core or Chrome
# (said in one line; tools/release.sh then skips its step with a note).
set -u
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
if ! command -v node >/dev/null 2>&1; then
    echo "ui clicks: node not found - not run"
    exit 3
fi
exec node "$here/ui-clicks.mjs" "$@"
