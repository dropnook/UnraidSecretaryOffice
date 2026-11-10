#!/usr/bin/env python3
"""
USO (2026-11-09, tests only; tests/shadow/diff.php --run): MediaCache's real run, exactly mediacache_run.py's main(), with plex.tv's
address taken from MEDIACACHE_SHADOW_PLEX_TV (the stand-in servers) — the one thing the comparison of the real runs needs that the
script itself can't be told. Nothing in mediacache_lib.py or mediacache_run.py is changed for it.
"""
import os
import sys

sys.path.insert(0, sys.argv.pop(1))
import mediacache_lib as lib  # noqa: E402

if os.environ.get("MEDIACACHE_SHADOW_PLEX_TV"):
    lib.PlexApi.PLEX_TV = os.environ["MEDIACACHE_SHADOW_PLEX_TV"]
import mediacache_run  # noqa: E402

sys.exit(mediacache_run.main())
