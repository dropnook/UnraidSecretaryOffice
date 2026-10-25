Mr. Restori, the restore matrix's gaps 6–12 (task/restore-gaps, 2026-10-25): stub states for what the gaps added. Made
from the stub state one folder up; all names invented.

- `ticket/restore.json` — a new server with a restore ticket from PartnerNAS, no backup place yet (gap 10): Apps, VMs
  and Databases say their packages come with the backup place from PartnerNAS, with a link to «Onto a new server».

    node tools/scenarios/page.mjs --states tests/ui/states/restore-gaps/ticket restore/apps --lang en,de --clean \
      --want restore.apps_from_ticket --want restore.ticket_link --not restore.apps_no_packages
    bash tools/ui-clicks.sh --states tests/ui/states/restore-gaps/ticket --only restore

testRestoreGapsPage (tests/restoregaps.js) holds the same rules without a browser.
