A server whose disks don't spin (all flash: SATA SSDs in the array, NVMe parity and pools — no HDD). Made from the stub
states one folder up (jq: `wakes.hdds` and `wakes.wakes` empty).

- `logs.json` — Ms. Protocolli's «Who woke my disks?»: her tile is there and says in one line that no disk spins here;
  its section (a click) says the same — no switch, no filter bar, no empty list.
- `logs-tour.json` — her tour as one folder up.

    node tools/scenarios/page.mjs --states tests/ui/states/all-flash logs --lang en,de --want logs.wakes.no_hdd
    bash tools/ui-clicks.sh --states tests/ui/states/all-flash --only logs

tests/logsfilters.js (testLogsFiltersPage) holds the same rules without a browser.
