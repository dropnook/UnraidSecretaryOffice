#!/bin/bash
# Does the makers' feedback inbox take reports? (2026-10-21: the inbox repository was archived, every report from an
# office failed, and nobody here knew.) Asks the feedback Worker's GET /api/health - the address is OFFICE_FEEDBACK_URL
# in src/place.php (USO_FEEDBACK_URL for another) - and says it in one line. Nothing is sent but the request.
#
#   bash tools/inbox-health.sh
#
# Exit: 0 open (open:true, no problems) · 1 not open (open:false or problems named: closed_by, problems) · 3 no answer
# (unreachable, or not the Worker's). For tools/release.sh (step 1) and tools/scenarios/nightly.sh (atop nightly.md).
set -u -o pipefail
repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
url=${USO_FEEDBACK_URL:-$(sed -n "s/^const OFFICE_FEEDBACK_URL = '\(https:\/\/[^']*\)';.*/\1/p" "$repo/src/place.php")}
[[ -n "$url" ]] || { echo "no answer: no feedback address in src/place.php"; exit 3; }
body=$(curl -s -m 15 -H 'Accept: application/json' "$url/api/health" 2>/dev/null | tr -d '\n')
open=$(sed -nE 's/.*"open":(true|false).*/\1/p' <<<"$body")
[[ -n "$open" ]] || { echo "no answer: $url/api/health (${body:0:80})"; exit 3; }
by=$(sed -nE 's/.*"closed_by":"?([a-z_]+)"?.*/\1/p' <<<"$body")
problems=$(sed -nE 's/.*"problems":\[([^]]*)\].*/\1/p' <<<"$body" | tr -d '"' | sed 's/,/, /g')
if [[ "$open" == true && -z "$problems" ]]; then
    echo "open: $url takes reports"
    exit 0
fi
echo "NOT OPEN: $url - open: $open, closed by: ${by:-null}, problems: ${problems:-none}"
exit 1
