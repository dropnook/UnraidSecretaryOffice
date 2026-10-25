Ms. Protokolli and the Night Watchman as they really are (the truth review 2026-10: T28, T30, W6, W9, W10).
Made from the stub states one folder up.

- `logs-tour.json` — her first tour: immich_postgres and immich_redis stopped (T28: read anyway, the label «stopped»),
  the syslog read from its newest part with lines of the 24 hours left out (W10: «only the newest part», its tip says why).
- `docker-silent/logs-tour.json` — Docker didn't answer: no container's log read, the page says so (T28), never counted
  as «nothing like that».
- `watchman.json` — /var/log full (T30: the round's warning, the bubble never «All quiet»), an info tip not known yet
  (W6: the posture tile not red), the network's files asleep (W9: its head says so, not the last counts).

    node tools/scenarios/page.mjs --states tests/ui/states/truth-watch logs --lang en,de --clean --want logs.found.sub_first
    node tools/scenarios/page.mjs --states tests/ui/states/truth-watch/docker-silent logs --want logs.found.no_containers
    node tools/scenarios/page.mjs --states tests/ui/states/truth-watch watchman --lang en,de --want watchman.round.syslog_full \
      --want watchman.bubble.syslog_full --not watchman.bubble.quiet --want watchman.watch.net_asleep
    bash tools/ui-clicks.sh --states tests/ui/states/truth-watch --only logs,watchman

testTruthLogsWatch holds the same rules without a browser.
