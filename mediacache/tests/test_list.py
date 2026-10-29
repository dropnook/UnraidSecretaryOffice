"""USO (2026-10-29, wave 1's H4): the exclude list is never made empty when nothing says what is on the cache — a real
run whose plan is incomplete (a media server that can't be asked) makes no list where there was none (an empty one gives
everything on the cache free to the office's mover); the trial runs (report, dry) never write it, and the first real
run after them does; a release without a list makes none.

Run:  python3 -m unittest discover -s tests -v
"""
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

from test_share_root import Env, FakeZfs, lib, run


class Stand:
    """Planner stand-in: no files on deck, `errors` as given (an incomplete plan)"""

    def __init__(self, errors):
        self.errors, self.users, self.files, self.budget_mode = errors, {}, {}, False

    def run(self):
        return []

    def not_in_budget(self):
        return None


class ListTests(unittest.TestCase):
    def setUp(self):
        self.env = env = Env("btrfs")
        env.cfg["instances"] = [{"servername": "Emby", "_mappings": {}, "_skip": set()}]
        self.list = Path(tempfile.mkdtemp(dir=env.root)) / "mediacache_exclude.txt"
        self.patches = [mock.patch.object(lib, "MOUNTS_FILE", str(env.mounts)),
                        mock.patch.object(lib.subprocess, "run", FakeZfs(env)),
                        mock.patch.object(lib, "EXCLUDE_FILE", self.list),
                        mock.patch.object(run, "EXCLUDE_FILE", self.list),
                        mock.patch.object(run, "unraid_mover_running", return_value=False),
                        mock.patch.object(run.Runner, "report", lambda *a, **k: None)]
        for p in self.patches:
            p.start()

    def tearDown(self):
        for p in self.patches:
            p.stop()

    def execute(self, mode, errors=0, sessions_ok=True):
        with mock.patch.object(run, "Planner", lambda cfg: Stand(errors)), \
             mock.patch.object(run, "collect_sessions", return_value=((set(), set()), sessions_ok)), \
             self.assertLogs("MediaCache", "INFO") as logs:
            rc = run.Runner(self.env.cfg, mode).execute()
        return rc, "\n".join(logs.output)

    def test_incomplete_first_run_makes_no_list(self):
        for errors, ok in ((1, True), (0, False)):
            with self.subTest(errors=errors, sessions_ok=ok):
                rc, out = self.execute("run", errors, ok)
                self.assertEqual(rc, 0)
                self.assertFalse(self.list.exists(), "no list from an incomplete plan")
                self.assertIn("noch keine Exclude-Liste", out)

    def test_incomplete_run_keeps_a_list_there(self):
        f = self.env.cache / "Filme" / "A" / "a.mkv"
        f.parent.mkdir(parents=True)
        f.write_bytes(b"x")
        self.list.write_text(f"{f}\n")
        self.execute("run", errors=1)
        self.assertEqual(self.list.read_text(), f"{f}\n", "the list as it was")

    def test_trials_write_nothing_the_real_run_after_them_does(self):
        for mode in ("report", "dry"):
            self.execute(mode)
            self.assertFalse(self.list.exists(), f"{mode}: no list")
        self.execute("run")
        self.assertTrue(self.list.exists(), "the first real run after the trials writes it")
        self.assertEqual(self.list.read_text(), "", "nothing on deck: empty, and his own")

    def test_release_without_a_list_makes_none(self):
        with mock.patch.object(run, "collect_sessions", return_value=((set(), set()), True)), self.assertLogs("MediaCache", "INFO"):
            run.Runner(self.env.cfg, "release").execute()
        self.assertFalse(self.list.exists())


if __name__ == "__main__":
    unittest.main()
