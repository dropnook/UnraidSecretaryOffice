"""USO (2026-10-29): a file two persons have on deck counts at each of them — their planned and done in the progress,
their rank («ahead»), what their budgets leave out — and is copied once.

Run:  python3 -m unittest discover -s tests -v
"""
import copy
import json
import tempfile
import unittest
from pathlib import Path
from unittest import mock

from test_share_root import NO_SESSIONS, Env, FakeZfs, fake_rsync, lib, run


def group(user, rel, size, category="movie", ahead=0, title=None):
    f = run.OnDeckFile(Path(rel), size, False, "test", user, "resume", title or rel, ahead, f"id|{rel}")
    return run.Group([f], category, f"{category}:{rel}", "Weiterschauen", None, title or rel, "test")


class SharedFileTests(unittest.TestCase):
    def setUp(self):
        self.env = env = Env("btrfs")
        self.tmp = Path(tempfile.mkdtemp(dir=env.root))
        self.progress = self.tmp / "progress.json"
        self.patches = [mock.patch.object(lib, "MOUNTS_FILE", str(env.mounts)),
                        mock.patch.object(lib.subprocess, "run", FakeZfs(env)),
                        mock.patch.object(lib, "PROGRESS_FILE", str(self.progress)),
                        mock.patch.object(run, "PROGRESS_FILE", str(self.progress)),
                        mock.patch.object(run, "run_rsync", side_effect=fake_rsync)]
        for p in self.patches:
            p.start()

    def tearDown(self):
        for p in self.patches:
            p.stop()

    def planner(self, budget=0):
        cfg = copy.deepcopy(self.env.cfg)
        cfg.update(instances=[], valid_users=[], cache_budget_bytes=budget, movie_share_percent=50, user_budgets={})
        p = run.Planner(cfg)
        mother, father = run.UserPlan("p:mutter", "Mutter"), run.UserPlan("p:vater", "Vater")
        # both have «Ferien 1998» on deck: Mother started it, for Father it is next up; each has one of their own
        mother.movies = [group("p:mutter", "Filme/Ferien 1998/f.mkv", 3000, ahead=0), group("p:mutter", "Filme/Taufe/t.mkv", 1000)]
        father.movies = [group("p:vater", "Filme/Velotour/v.mkv", 2000), group("p:vater", "Filme/Ferien 1998/f.mkv", 3000, ahead=1)]
        p.users = {u.key: u for u in (mother, father)}
        return p

    def test_planned_and_done_at_each_copied_once(self):
        p = self.planner()
        files = p.run()
        for f in files:
            self.env.array_file(str(f.rel), data=b"x" * f.size)
        shared = next(f for f in files if f.rel.name == "f.mkv")
        self.assertEqual(shared.ranks, {"p:mutter": 0, "p:vater": 1}, "its rank at each")
        self.assertEqual(run.owners(shared), ["p:mutter", "p:vater"])
        self.assertEqual(len(files), 3, "copied once")
        r = run.Runner(self.env.cfg, "run")
        r.progress.plan_fill(p, files)
        r.progress.write()
        d = json.loads(self.progress.read_text())
        self.assertEqual([(u["name"], u["files"], u["bytes"]) for u in d["users"]], [("Mutter", 2, 4000), ("Vater", 2, 5000)])
        self.assertEqual((d["fill"]["files"], d["fill"]["bytes"]), (3, 6000), "the fill as a whole: each file once")
        i = next(n for n, f in enumerate(files) if f is shared)
        self.assertEqual(d["queue"][i][0], 0, "the queue names it at the first")
        self.assertEqual(d["shared"], [[i, [1]]], "… and at the other")
        r.progress.phase("fill")
        seen = []
        orig = r.progress.start

        def start(*a, **k):
            orig(*a, **k)
            seen.append(json.loads(self.progress.read_text())["active"][-1])
        with mock.patch.object(r.progress, "start", side_effect=start):
            r.fill(self.env.loc(), files, NO_SESSIONS)
        self.assertEqual(r.copied, 3)
        self.assertEqual(next(a["users"] for a in seen if a["rel"].endswith("f.mkv")), ["p:mutter", "p:vater"])
        d = json.loads(self.progress.read_text())
        self.assertEqual([(u["done_files"], u["done_bytes"]) for u in d["users"]], [(2, 4000), (2, 5000)])
        self.assertEqual((d["fill"]["done_files"], d["fill"]["done_bytes"]), (3, 6000))
        self.assertEqual(d["shared"], [], "done: nobody waits for it in the queue")
        self.assertTrue(all(u["ended"] for u in d["users"]) and d["fill"]["ended"])
        self.assertEqual(len(d["recent"]), 3)

    def test_dropped_at_each(self):
        p = self.planner()
        files = p.run()
        shared = next(f for f in files if f.rel.name == "f.mkv")
        r = run.Runner(self.env.cfg, "run")
        r.progress.plan_fill(p, files)
        r.progress.write()
        r.progress.drop(shared)
        d = json.loads(self.progress.read_text())
        self.assertEqual([(u["files"], u["bytes"]) for u in d["users"]], [(1, 1000), (1, 2000)])
        self.assertEqual((d["fill"]["files"], d["fill"]["bytes"]), (2, 3000))

    def test_not_in_budget_at_each(self):
        p = self.planner()
        p.budget_mode = True
        big = lambda u: group(u, "Filme/Big/b.mkv", 9000)          # noqa: E731
        p.users["p:mutter"].skipped = [big("p:mutter"), group("p:mutter", "Serien/S/e5.mkv", 500, "series")]
        p.users["p:vater"].skipped = [big("p:vater"), group("p:vater", "Filme/Taufe/t.mkv", 1000)]
        p.files = {"Filme/Taufe/t.mkv": object()}       # Mother's budget takes «Taufe» along
        nib = p.not_in_budget()
        self.assertEqual({k: nib[k] for k in ("items", "films", "episodes", "bytes")}, {"items": 2, "films": 1, "episodes": 1, "bytes": 9500},
                         "in all: each entry once")
        self.assertEqual(nib["persons"], [{"name": "Mutter", "items": 2, "films": 1, "episodes": 1, "bytes": 9500},
                                          {"name": "Vater", "items": 1, "films": 1, "episodes": 0, "bytes": 9000}],
                         "per person: the big film at both, «Taufe» at neither (on the pool anyway)")

    def test_give_back_counts_each(self):
        p = self.planner()
        files = p.run()
        shared = next(f for f in files if f.rel.name == "f.mkv")
        self.assertEqual(p.names(shared), "Mutter, Vater")
        self.assertEqual(shared.ahead(), 0, "ahead: for whoever watches it soonest")


if __name__ == "__main__":
    unittest.main()
