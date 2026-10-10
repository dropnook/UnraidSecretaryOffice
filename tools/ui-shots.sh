#!/bin/bash
# Screenshots for the product page and the forum (tools/ui-shots.mjs) on this Mac: desk pages with the invented family
# data of tests/ui/demo inside Unraid's look, in a headless Chrome with a throwaway profile. Arguments go through
# (--routes emby  --themes black,white  --langs de,en  --widths 1440,390  --shot clip:.jo-prog  --out <folder>  -v).
# Exit: 0 all shots taken, 1 a shot failed (the list says which), 2 wrong call, 3 not run - no node, playwright-core or
# Chrome (said in one line).
set -u
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
if ! command -v node >/dev/null 2>&1; then
    echo "ui shots: node not found - not run"
    exit 3
fi
exec node "$here/ui-shots.mjs" "$@"
