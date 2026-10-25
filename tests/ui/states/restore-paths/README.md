Mr. Restori's ways back of 1.72 (task/restore-paths; the restore matrix of 2026-10, gaps 1, 3 and 5): the stub state one
folder up with Kopia on (its container running, a restore folder mapped), the shares appdata, nextcloud, photos and
Familienfilme offsite, Nextcloud with a source of its own — and canned answers for the new dialogs:

- `post/restore.kopia_list.json` — Kopia's snapshots of Familienfilme (a share no app uses);
- `post/restore.kopia_ls.json` — its top folder in «Browse…» (every path answers the same here);
- `post/restore.preview.json` — one folder of it out of Kopia, next to the original (every preview answers this one);
- `post/restore.versions.json`, `post/restore.versions_kopia.json` — Nextcloud's earlier backups: a local one, one
  brought back from Kopia, and three Kopia keeps (each «Get from Kopia…»).

All names invented (private home videos). Clicked with

    bash tools/ui-clicks.sh --states tests/ui/states/restore-paths --only restore
    node tools/scenarios/page.mjs --states tests/ui/states/restore-paths restore/shares --open --want restore.sh.kopia_button
