The backup protection as it really is (the truth review 2026-10, patterns P1 and P2): stub states where settings.ini says
more than the results show. Made from the stub states one folder up; each label must say what is there, with its reason
beside it, never a green label alone.

- `backup.json` — Mr. Backupsy: the stub's partner skip of appdata (T05: «not at PartnerNAS: skipped», no «local +
  partner»), a new share syslog (drift `share_new`, T07: a row «not backed up», never «all protected»), isos on a file
  system without snapshots (drift `share_snapshot_no_fs`, T08: «not backed up», «no snapshot possible here»), speedtest
  set offsite with no copy yet (T09: «only local», «set: offsite — no copy yet»); the storage taking writes again.
- `held/backup.json` — the repository suddenly empty (`kopia_held`, T02): no row offsite, the Kopia and flash tiles alert,
  the bubble never «… saved offsite». The stub one folder up has the storage taking nothing (`kopia_writes` stuck, T10).
- `restore.json` — Mr. Restori: Grafana's folder a dataset of its own (T06: no «at PartnerNAS» from appdata's copy),
  prometheus on XFS (T08), immich's package set offsite with no copy yet (T09).
- `cleanup-where.json` (+ `cleanup.json` as it is) — «Where is what»: appdata «local + partner» (T15), isos without
  snapshots.

The notes (`protection_note`, `backup_note`) are what the agent writes (backupProtectionNote()); an older agent writes
none and the label is the level alone.

    node tools/scenarios/page.mjs --states tests/ui/states/truth backup --lang en,de --clean \
      --want office.protect.why.new_share --want office.protect.why.no_snaps --want office.protect.why.kopia_never \
      --want office.protect.why.partner_skipped
    node tools/scenarios/page.mjs --states tests/ui/states/truth/held backup --want backup.stat.kopia_held \
      --want backup.bubble.kopia_held --not backup.bubble.last_ok_kopia
    node tools/scenarios/page.mjs --states tests/ui/states/truth restore/dbs --open --want office.protect.why.kopia_never
    node tools/scenarios/page.mjs --states tests/ui/states/truth cleanup/where --open --want office.protect.partner
    bash tools/ui-clicks.sh --states tests/ui/states/truth --only backup,restore,cleanup

`inv-truth.json` is an invented server truth beside these state files for the scenarios' INV-TRUTH tool: `node
tools/scenarios/truth.mjs --self-test` must find exactly the lies listed in its `expect`, note each of its `notes` (Scans:
a share newer than the stub's drift check, not on the page yet), and pass each of its `cases` — a run of its own with the
truth patched and options of its own («a tag's page»: `--tree HEAD`, every lie a note) (tools/scenarios/README.md
«INV-TRUTH»).

tests/backuptiles.js (INV-TRUTH) and testBackupTruthProtection, testRestoreTruthChips, testSetupOwnDatasetApply hold
the same rules without a browser.
