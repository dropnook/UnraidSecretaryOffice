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

- `partner/` (the base stub) — PartnerNAS keeps share appdata and the backup place (gap 8): each app's row has «From a
  partner» (what is kept there, «Bring back from PartnerNAS…», the package in the backup place there), its label «at
  PartnerNAS» brings it back on a click; the dialog says first that the whole unit comes (234 MB, every app's folders in
  it). `post/restore.preview.json` is the pull's plan.

    node tools/scenarios/page.mjs --states tests/ui/states/restore-gaps/partner restore/apps --lang en,de --open --clean \
      --want restore.from_partner --want restore.pa.fetch --want restore.pa.package
    bash tools/ui-clicks.sh --states tests/ui/states/restore-gaps/partner --only restore

- `partner-pulled/` — the base stub with a finished pull of share appdata from PartnerNAS, still here (gaps 6 and 8): its
  journal entry offers «Now to its place…» for every app's folder in it and the share, and «Swap it in…» (the share's
  dataset aside whole, put back possible); each app's «From a partner» says «here since …» with «Restore…».
  `post/restore.journal.json` is the pull's journal.
- `libvirt-off/` — the base stub with the VM service off (gap 7): «Everything back into libvirt.img» says first that it
  replaces every VM definition, then «Restore all…» (in the base stub, the VM service on: the button waits).

    node tools/scenarios/page.mjs --states tests/ui/states/restore-gaps/partner-pulled restore/journal --lang en,de --open --clean \
      --want restore.j.swap --want restore.j.place_go
    node tools/scenarios/page.mjs --states tests/ui/states/restore-gaps/libvirt-off restore/vms --lang en,de --open --clean \
      --want restore.vm.all_replaces --want restore.vm.all_button --want restore.vm.all_2_text
    bash tools/ui-clicks.sh --states tests/ui/states/restore-gaps/partner-pulled --only restore
    bash tools/ui-clicks.sh --states tests/ui/states/restore-gaps/libvirt-off --only restore
