Mr. Restori, the restore matrix's gaps 6–12 (task/restore-gaps, 2026-10-25): stub states for what the gaps added. Made
from the stub state one folder up; all names invented.

- `ticket/restore.json` — a new server with a restore ticket from PartnerNAS, no backup place yet (gap 10): Apps, VMs
  and Databases say their packages come with the backup place from PartnerNAS, with a link to «Onto a new server».

    node tools/scenarios/page.mjs --states tests/ui/states/restore-gaps/ticket restore/apps --lang en,de --clean \
      --want restore.apps_from_ticket --want restore.ticket_link --not restore.apps_no_packages
    bash tools/ui-clicks.sh --states tests/ui/states/restore-gaps/ticket --only restore

testRestoreGapsPage (tests/restoregaps.js) holds the same rules without a browser.

- `flash-boot/restore.json` — the flash kept as an archive while it lies on a ZFS boot pool with snapshots of its own
  (gap 12): «The flash» names them after the archive — how many of flash/boot, the newest, its read-only path — and says
  single pieces are copied by hand, the boot pool never rolled back; no button.
- `flash-boot-snapshot/restore.json` — the flash kept with the boot pool's snapshots (the engine's snapshot mode): the same
  lines, «those are …» instead of «… too».

    node tools/scenarios/page.mjs --states tests/ui/states/restore-gaps/flash-boot restore/flash --lang en,de --clean \
      --want restore.flash.boot_snaps --want restore.flash.boot_snaps_how --want restore.flash.download
    node tools/scenarios/page.mjs --states tests/ui/states/restore-gaps/flash-boot-snapshot restore/flash --lang en,de --clean \
      --want restore.flash.boot_snaps_these --not restore.flash.download
    bash tools/ui-clicks.sh --states tests/ui/states/restore-gaps/flash-boot --only restore

- `kopia/` (from `restore-paths/`) — Kopia on, Nextcloud with its own source, and a finished Kopia restore of that source
  staged in the restore folder (gap 9): the app's «From offsite» opens the source's own «Restore…» (the guide named as the
  way by hand); the journal entry offers «Now to its place…» for Nextcloud's folder and the share it binds whole, each
  opening step 4's dialog on that Kopia moment. `post/restore.journal.json` is the entry's journal.

    node tools/scenarios/page.mjs --states tests/ui/states/restore-gaps/kopia restore/journal --lang en,de --open --clean \
      --want restore.j.place_title --want restore.j.place_go
    node tools/scenarios/page.mjs --states tests/ui/states/restore-gaps/kopia restore/apps --open --want restore.kp.guide_hand --not restore.kp.guide
    bash tools/ui-clicks.sh --states tests/ui/states/restore-gaps/kopia --only restore
