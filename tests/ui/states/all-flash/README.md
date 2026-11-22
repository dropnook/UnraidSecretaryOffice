A server whose disks don't spin (all flash: SATA SSDs in the array, NVMe parity and pools — no HDD). Made from the stub
states one folder up (jq: `wakes.hdds` and `wakes.wakes` empty).

- `watchman.json` — the Night Watchman's «Who woke my disks?» (Ms. Protocolli's up to 1.95): his tile is there and
  says that no disk spins here; its section (a click) says it in one line — no switch, no filter bar, no empty list.

    node tools/scenarios/page.mjs --states tests/ui/states/all-flash watchman --lang en,de --want watchman.wakes.no_hdd
    bash tools/ui-clicks.sh --states tests/ui/states/all-flash --only watchman

tests/watchwakes.js (testWatchWakesPage) holds the same rules without a browser.
