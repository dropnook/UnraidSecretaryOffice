Ms. Moverelli and Jack Streamer as they really are (the truth review 2026-10, the rest: T31 T35 T37 T38 M12). Made from the
stub states one folder up.

- `moverelli.json` — Jack Streamer's list in her rules but not written yet (`jack.pending`): her real runs are refused, so
  Unraid's mover «off» never says «real runs go», the bubble never «All in order» (T35); «Start…» names the joint run and
  keeps «Real run» off with the reason (T38).
- `jack.json` — her limit X 0: set up, without a limit — his setup says what he keeps free, never «Ms. Moverelli isn't set
  up yet» (T37); the next joint run «by age only», never «the pool isn't measured» (M12).
- `fill/` — her real run in its fill (the oldest files across the pool, after every share's own step): a step of its own,
  no «media: 3 of 3 · 11 GB of 9 GB», no percent from the last share's counts (T31).

    node tools/scenarios/page.mjs --states tests/ui/states/truth-mover moverelli --lang en,de --clean \
      --want moverelli.mover.ok_wait --want moverelli.bubble.wait_pending --not moverelli.mover.ok --not moverelli.bubble.ready
    node tools/scenarios/page.mjs --states tests/ui/states/truth-mover jack/setup --lang en,de --clean --not jack.room.none
    node tools/scenarios/page.mjs --states tests/ui/states/truth-mover/fill moverelli --lang en,de --clean \
      --want moverelli.rc.fill_step --not moverelli.rc.share
    bash tools/ui-clicks.sh --states tests/ui/states/truth-mover --only moverelli,jack

testMoverJackTruthRest holds the same rules without a browser.
