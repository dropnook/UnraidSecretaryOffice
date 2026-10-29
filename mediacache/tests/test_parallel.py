"""USO (2026-10-29): parallel copies (parallel_per_disk per source disk, parallel_total over all) made usable for the
office — the progress file carries every file in flight (`active`), MEDIACACHE_STOP stops after the files in flight
(never inside a copy, no new one begins) and the result's counts stay right.

Run:  python3 -m unittest discover -s tests -v
"""
import json
import shutil
import tempfile
import threading
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock

from test_share_root import NO_SESSIONS, Env, FakeZfs, lib, run


class Gate:
    """rsync stand-in: each round of `width` copies meets before copying (a barrier), records what the progress file said
    while they were all in flight, then copies. `on_full` runs once, the first time they are all in flight."""

    def __init__(self, progress_file, width, on_full=None):
        self.file, self.on_full = progress_file, on_full
        self.lock = threading.Lock()
        self.inflight = self.most = 0
        self.seen = []
        self.full_once = False
        self.barrier = threading.Barrier(width, action=self.full, timeout=2)

    def full(self):
        self.seen.append(json.loads(Path(self.file).read_text()))
        if not self.full_once and self.on_full:
            self.full_once = True
            self.on_full()

    def __call__(self, cmd, on_progress=None):
        with self.lock:
            self.inflight += 1
            self.most = max(self.most, self.inflight)
        try:
            self.barrier.wait()
        except threading.BrokenBarrierError:
            pass
        shutil.copy2(cmd[-2], cmd[-1])
        with self.lock:
            self.inflight -= 1
        return 0, ""


class ParallelFillTests(unittest.TestCase):
    def setUp(self):
        self.env = env = Env("btrfs")
        (env.root / "mnt" / "disk2").mkdir()
        self.tmp = Path(tempfile.mkdtemp(dir=env.root))
        self.progress = self.tmp / "progress.json"
        self.stop = self.tmp / "stop"
        self.patches = [mock.patch.object(lib, "MOUNTS_FILE", str(env.mounts)),
                        mock.patch.object(lib.subprocess, "run", FakeZfs(env)),
                        mock.patch.object(lib, "PROGRESS_FILE", str(self.progress)),
                        mock.patch.object(run, "PROGRESS_FILE", str(self.progress)),
                        mock.patch.object(run, "STOP_FILE", str(self.stop))]
        for p in self.patches:
            p.start()

    def tearDown(self):
        for p in self.patches:
            p.stop()

    def disk_file(self, disk, rel, user):
        """A file on an array disk, seen through /mnt/user0 too (as shfs shows it): its OnDeckFile, queued by `user`"""
        f = self.env.array_file(rel)
        d = self.env.root / "mnt" / disk / rel
        d.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(self.env.array / rel, d)
        f.user, f.ranks, f.title, f.source = user, {user: 0}, rel, "resume"
        return f

    def runner(self, files, per_disk, total, users=("p:anna", "p:ben")):
        self.env.cfg.update(parallel_per_disk=per_disk, parallel_total=total)
        r = run.Runner(self.env.cfg, "run")
        planner = SimpleNamespace(users={k: SimpleNamespace(key=k, name=k[2:].title(), server="Emby") for k in users})
        r.progress.plan_fill(planner, files)
        r.progress.phase("fill")
        return r

    def files(self):
        return [self.disk_file("disk1", "Filme/A/a.mkv", "p:anna"), self.disk_file("disk2", "Filme/B/b.mkv", "p:ben"),
                self.disk_file("disk1", "Filme/C/c.mkv", "p:anna"), self.disk_file("disk2", "Filme/D/d.mkv", "p:ben")]

    def test_every_file_in_flight_in_the_progress(self):
        files = self.files()
        r = self.runner(files, 1, 2)
        gate = Gate(self.progress, 2)
        with mock.patch.object(run, "run_rsync", side_effect=gate):
            r.fill(self.env.loc(), files, NO_SESSIONS)
        self.assertEqual(gate.most, 2, "one copy per disk, two disks: two at once")
        self.assertTrue(gate.seen)
        for p in gate.seen:
            self.assertEqual(len(p["active"]), 2)
            self.assertEqual(p["current"], p["active"][0], "current stays the first in flight (older readers)")
            self.assertEqual({a["phase"] for a in p["active"]}, {"fill"})
            for a in p["active"]:
                self.assertTrue(a["target"].startswith(str(self.env.cache)))
                self.assertIn(a["user"], ("p:anna", "p:ben"))
                self.assertIsInstance(a["q"], int)
            # one from each disk: a and b first (the queue's order per disk)
        self.assertEqual(sorted(Path(a["rel"]).name for a in gate.seen[0]["active"]), ["a.mkv", "b.mkv"])
        self.assertEqual((r.copied, r.copied_bytes, r.fill_planned), (4, 4000, 4))
        done = json.loads(self.progress.read_text())
        self.assertEqual(done["active"], [])
        self.assertIsNone(done["current"])
        self.assertEqual(done["pos"], 4)
        self.assertEqual([(u["files"], u["done_files"], u["done_bytes"]) for u in done["users"]], [(2, 2, 2000), (2, 2, 2000)])
        self.assertTrue(all(u["ended"] for u in done["users"]))
        self.assertEqual(len(done["recent"]), 4)
        self.assertTrue(all((self.env.cache / f.rel).is_file() and not (self.env.array / f.rel).exists() for f in files))

    def test_two_per_disk(self):
        files = self.files()
        r = self.runner(files, 2, 4)
        gate = Gate(self.progress, 4)
        with mock.patch.object(run, "run_rsync", side_effect=gate):
            r.fill(self.env.loc(), files, NO_SESSIONS)
        self.assertEqual(gate.most, 4)
        self.assertEqual(len(gate.seen[0]["active"]), 4)
        self.assertEqual(r.copied, 4)

    def test_total_caps_the_disks(self):
        files = self.files()
        r = self.runner(files, 2, 1)
        gate = Gate(self.progress, 1)          # the total says one at a time
        with mock.patch.object(run, "run_rsync", side_effect=gate):
            r.fill(self.env.loc(), files, NO_SESSIONS)
        self.assertEqual(gate.most, 1)
        self.assertEqual(r.copied, 4)

    def test_stop_after_the_files_in_flight(self):
        files = self.files()
        r = self.runner(files, 1, 2)
        gate = Gate(self.progress, 2, on_full=lambda: self.stop.write_text("{}"))
        with mock.patch.object(run, "run_rsync", side_effect=gate):
            r.fill(self.env.loc(), files, NO_SESSIONS)
        self.assertTrue(r.stopped)
        self.assertEqual((r.copied, r.copied_bytes), (2, 2000), "the two in flight finished, no new one began")
        self.assertEqual(sorted(f.rel.name for f in files if (self.env.cache / f.rel).is_file()), ["a.mkv", "b.mkv"])
        self.assertTrue(all((self.env.array / f.rel).is_file() for f in files[2:]), "the rest stays on the array, whole")
        self.assertFalse(any(p.name.startswith(".") for p in (self.env.cache / "Filme").rglob("*")))
        done = json.loads(self.progress.read_text())
        self.assertEqual(done["active"], [])
        self.assertEqual([(u["files"], u["done_files"]) for u in done["users"]], [(2, 1), (2, 1)], "planned stays: halted, not done")

    def test_out_of_order_queue(self):
        """A file done while an earlier one still copies: its queue entry is nobody's now, pos counts from the front"""
        files = self.files()[:2]
        r = self.runner(files, 1, 0)
        p = r.progress
        p.start("fill", files[0].rel, files[0].size, self.env.cache / files[0].rel, files[0])
        p.start("fill", files[1].rel, files[1].size, self.env.cache / files[1].rel, files[1])
        p.done(files[1].size, files[1])
        d = json.loads(self.progress.read_text())
        self.assertEqual((d["pos"], d["queue"][1]), (0, [-1, 0]))
        self.assertEqual([a["rel"] for a in d["active"]], [str(files[0].rel)])
        p.done(files[0].size, files[0])
        d = json.loads(self.progress.read_text())
        self.assertEqual((d["pos"], d["active"], d["current"]), (2, [], None))
        self.assertEqual([r["name"] for r in d["recent"]], [str(files[1].rel), str(files[0].rel)])


if __name__ == "__main__":
    unittest.main()
