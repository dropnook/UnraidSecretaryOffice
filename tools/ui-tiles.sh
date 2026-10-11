#!/bin/bash
# What a tile click shows (tools/ui-tiles.mjs) on this Mac: every tile of a desk page before and after its click, as
# viewport screenshots (dark, de, 1440 and 390 px) with the invented family data of tests/ui/demo, plus log.txt - in a
# headless Chrome with a throwaway profile. Arguments go through (--only restore  --widths 390  --lang en  --out <folder>  -v).
# Exit: 0 every tile shot, 1 a tile failed (log.txt says which), 2 wrong call, 3 not run - no node, playwright-core or
# Chrome (said in one line).
set -u
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
if ! command -v node >/dev/null 2>&1; then
    echo "ui tiles: node not found - not run"
    exit 3
fi
exec node "$here/ui-tiles.mjs" "$@"
