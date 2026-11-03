Ms. Moverelli while Unraid 7.4's own mover is at work (2026-11-03). Made from the stub state one folder up.

- `moverelli.json` — Unraid 7.4.0-rc.2, its mover started by hand with ⟦Mover Progress⟧ on (`mover.unraid_progress`): the
  card of her runs, named as Unraid's mover — all its bytes (the file in flight by its .partial), Ø, time left, the share,
  the file, the end; no «Stop after this file», no log of hers. `moverelli-progress.json` — her poll's next answer (the next
  file, the card's numbers anew).
- `off/` — the same with ⟦Mover Progress⟧ off: no card, her notice «Unraid's mover is at work» and the sentence how to switch
  it on.

    node tools/scenarios/page.mjs --states tests/ui/states/unraid-mover moverelli --lang en,de --clean \
      --want moverelli.rc.unraid_title --want moverelli.rc.unraid_all --not moverelli.mover.progress_off
    node tools/scenarios/page.mjs --states tests/ui/states/unraid-mover/off moverelli --lang en,de --clean \
      --want moverelli.mover.running --want moverelli.mover.progress_off --not moverelli.rc.unraid_title
    bash tools/ui-clicks.sh --states tests/ui/states/unraid-mover --only moverelli

testMoverelliUnraidProgress holds the same rules without a browser.
