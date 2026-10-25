The truth review 2026-10, the rest (Mr. Restori, Ms. Snapshotini): stub states where the page once said more than is
there. Made from the stub states one folder up.

- `restore-drill.json` — a drill that ran to its end with nothing failed and nothing proven (every item «not checked»,
  Docker stopped): the tile, the headline and the history say «nothing proven», never «passed»; the apps' rows never
  «proven» (T18).
- `restore.json` — Grafana's folder split over cache (7 snapshots) and disk1 (none): its label warns «none on disk1»,
  never a green «7 local snapshots» (R10).
- `snapshot.json` — two schedules, one failed last time, and the office's cron line for them gone: no «Tutto a posto»,
  no «next run» on the tile or the rows (T20).
- `lose/restore-drill.json` — a drill whose apps reach offsite only through the backup place's share
  (`kopia_package`): «from offsite only its package», never «… from offsite» as if their data went there (T19).

    node tools/scenarios/page.mjs --states tests/ui/states/truth-rest restore/drill --lang en,de --clean \
      --want restore.drill.tile_unproven --want restore.drill.unproven_note --not restore.drill.tile_passed
    node tools/scenarios/page.mjs --states tests/ui/states/truth-rest snapshot --want snapshot.bubble.runner_off \
      --want snapshot.bubble.plans_trouble --not snapshot.bubble.all_well --not snapshot.plan.next
    node tools/scenarios/page.mjs --states tests/ui/states/truth-rest restore/apps --open --want restore.chip.snaps_part
    node tools/scenarios/page.mjs --states tests/ui/states/truth-rest/lose restore/drill --open \
      --want restore.drill.lose_package --not restore.drill.lose_both
    bash tools/ui-clicks.sh --states tests/ui/states/truth-rest --only restore,snapshot

testTruthRestPages and testRestoreDrill hold the same rules without a browser.

Mr. Backupsy (T22–T27, B12, B14, S9), from `backup.json` and `post/backup.setup_get.json` one folder up:

- `backup.json` — Docker stopped (the start dialog says «no dumps, no packages, nothing offsite», never «Dumps, snapshots
  and the offsite copy», T26; the new things say new apps weren't looked for); the schedule due an hour before the look
  with the last backup older (tile «Last backup» alerts «the scheduled backup of … didn't run», T25; «was due …», never
  «next backup … ago», B14); Windows-Druckserver set up in a share not backed up (the VMs tile alerts «1 not backed up»,
  T23); three new VMs no plan has seen (the callout never «only in the local snapshots so far», T22); immich's dump kept
  from an earlier backup (the databases tile alerts, T24); a package with warnings named on the packages tile (B12).
- `post/backup.setup_get.json` — Kopia on, an old source not the office's (`gone`): «Set them to manual» starts unticked
  and its row says «stays as it is»; ticked, the row and the Apply dialog say it goes to «manual» (T27); the backup place's
  pool asleep with «let them sleep»: its share's label «woken anyway», never «left out» (S9).

    node tools/scenarios/page.mjs --states tests/ui/states/truth-rest backup --lang en,de --clean \
      --want backup.stat.missed --want backup.stat.next_due --want backup.stat.vm_unsafe --want backup.stat.db_old \
      --want backup.waiting.callout_unsure --want backup.waiting.apps_unknown --not backup.stat.next
    bash tools/ui-clicks.sh --states tests/ui/states/truth-rest --only backup,backup/setup

tests/backuptiles.js («the truth review's rest») and testBackupTruthRest hold the same rules without a browser.

Ms. Dustdevil, «Where is what» (T17, O7):

- `cleanup-where.json` — no mover schedule while a share writes to its pool first: with Ms. Moverelli hired (the harness
  hires every desk) no tip to set Unraid's mover schedule — she needs it off (T17).
- `cleanup.json` — two unused appdata folders not measured yet: «not measured yet» in her bubble, the facts and the tile,
  never «0 B» (O7).

    node tools/scenarios/page.mjs --states tests/ui/states/truth-rest cleanup/where --open --not cleanup.where.adv.mover.title
    node tools/scenarios/page.mjs --states tests/ui/states/truth-rest cleanup/tidy --lang en,de --want cleanup.d.not_measured
    bash tools/ui-clicks.sh --states tests/ui/states/truth-rest --only cleanup

testWhereProtectionTruth (T16), testWhereMoverAdvice (T17), testCleanupSizesUnknown (O7), testCaretakerAckDrift (O11)
and testArrayStoppedDataDir (O13) hold the rest without a browser.

Mr. Restori, «Kept for <partner>» (2026-10-25, a user: «where are the apps and the VM to restore?»), in `kept/`:

- `kept/restore.json` — PartnerNAS's copies: appdata carrying three apps (its newest moments read, one older not yet, one
  couldn't be mounted), Handy-Tochter carrying none, the VM Schule; Grosseltern's on a pool asleep. The tile «3 units of
  theirs — inside 3 apps and 1 VM», each share row and moment names what it carries, each app of a share a row of its own
  with «Start here…» for it alone; asleep: «contents not read — the pool hive is asleep».

    node tools/scenarios/page.mjs --states tests/ui/states/truth-rest/kept restore/kept:7c1e9a02 --lang en,de --open --clean \
      --want restore.tile.kept_line_inside --want restore.kept.inside --want restore.kept.inside_unread --want restore.kept.inside_failed
    node tools/scenarios/page.mjs --states tests/ui/states/truth-rest/kept restore/kept:5d2b8e11 --open --want restore.kept.inside_asleep
    bash tools/ui-clicks.sh --states tests/ui/states/truth-rest/kept --only restore

testPartnerStart («kept inside») holds the agent's side: read by the look job once per moment, never in a tick.

Mr. Restori, «The flash» (2026-10-25, a user couldn't find the archive): the base stub `restore.json` one folder up has
the archive (tar, «local + partner»: «Download…», where it lives); `flash/snapshot/` — a boot pool kept with snapshots (no
archive, its own Kopia source); `flash/off/` — the flash not backed up.

    node tools/scenarios/page.mjs --states tests/ui/states restore/flash --lang en,de --clean --want restore.flash.download \
      --want restore.flash.where_partner --want restore.flash.single_files
    node tools/scenarios/page.mjs --states tests/ui/states/truth-rest/flash/snapshot restore/flash --want restore.flash.snapshot_text
    node tools/scenarios/page.mjs --states tests/ui/states/truth-rest/flash/off restore/flash --want restore.flash.off_text --not restore.flash.download
    bash tools/ui-clicks.sh --states tests/ui/states/truth-rest/flash/snapshot --only restore

testFlashDownload holds the download's checks (src/api.php apiFlashFile()).
