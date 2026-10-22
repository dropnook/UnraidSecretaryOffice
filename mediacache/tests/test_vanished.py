"""USO «vanished»: another program mirroring into the share with rsync --delete removes rsync's temporary file in the
target before its rename (seen on a real server, 2026-10-22: two of 370 files lost that way in one fill). Such a copy
is tried once more; a second loss, or any other rsync error, stays an error — counted per file in the result.

Run:  python3 -m unittest discover -s tests -v
"""
import shutil
import unittest
from pathlib import Path
from unittest import mock

from test_share_root import NO_SESSIONS, Env, FakeZfs, lib, run


def vanish_msg(dst):
    tmp = Path(dst).parent / f".{Path(dst).name}.AXXRLk"
    return (f'rsync: [receiver] rename "{tmp}" -> "{Path(dst).name}": No such file or directory (2)\n'
            "rsync error: some files/attrs were not transferred (see previous errors) (code 23) at main.c(1338) [sender=3.4.4]")


class Mirror:
    """rsync stand-in: the first `vanish` copies lose their temporary file to «someone else» (code 23, rename ENOENT);
    after that it copies. `other` = an rsync error of another kind instead."""

    def __init__(self, vanish=1, other=None):
        self.vanish, self.other, self.calls = vanish, other, 0

    def __call__(self, cmd, on_progress=None):
        self.calls += 1
        src, dst = cmd[-2], cmd[-1]
        if self.other:
            return 23, self.other
        if self.calls <= self.vanish:
            tmp = Path(dst).parent / f".{Path(dst).name}.AXXRLk"
            shutil.copy2(src, tmp)
            tmp.unlink()                      # the mirror's --delete: not on its source, so it goes
            return 23, vanish_msg(dst)
        shutil.copy2(src, dst)
        return 0, ""


class VanishedFillTests(unittest.TestCase):
    def setUp(self):
        self.env = env = Env("btrfs")
        self.patches = [mock.patch.object(lib, "MOUNTS_FILE", str(env.mounts)),
                        mock.patch.object(lib.subprocess, "run", FakeZfs(env))]
        for p in self.patches:
            p.start()
        self.f = env.array_file("Serien/Show/Season.01/Show.-.S01E04.mkv")

    def tearDown(self):
        for p in self.patches:
            p.stop()

    def fill(self, mirror):
        runner = run.Runner(self.env.cfg, "run")
        with mock.patch.object(run, "run_rsync", side_effect=mirror), self.assertLogs("MediaCache", "INFO") as logs:
            runner.fill(self.env.loc(), [self.f], NO_SESSIONS)
        return runner, "\n".join(logs.output)

    def test_vanished_temp_is_copied_on_the_second_try(self):
        mirror = Mirror(vanish=1)
        runner, out = self.fill(mirror)
        self.assertEqual(mirror.calls, 2)
        self.assertTrue((self.env.cache / self.f.rel).is_file())
        self.assertFalse((self.env.array / self.f.rel).exists())
        self.assertEqual((runner.copied, runner.failed_files, runner.retried), (1, {"fill": 0, "back": 0}, 1))
        self.assertIn("verschwand im Ziel vor dem Umbenennen", out)
        self.assertNotIn("ERROR:MediaCache:", out)

    def test_twice_vanished_stays_one_failed_file(self):
        mirror = Mirror(vanish=2)
        runner, out = self.fill(mirror)
        self.assertEqual(mirror.calls, 2)                      # once more, never a third time
        self.assertTrue((self.env.array / self.f.rel).is_file())
        self.assertFalse((self.env.cache / self.f.rel).exists())
        self.assertEqual((runner.copied, runner.failed_files["fill"]), (0, 1))
        self.assertEqual(runner.failed_list, [{"phase": "fill", "rel": str(self.f.rel)}])
        self.assertEqual(out.count("ERROR:MediaCache:"), 2)    # two log lines — one file

    def test_other_rsync_error_is_not_retried(self):
        mirror = Mirror(other='rsync: [receiver] mkstemp "/x/.a.mkv.AbCdEf" failed: Permission denied (13)')
        runner, _ = self.fill(mirror)
        self.assertEqual((mirror.calls, runner.retried, runner.failed_files["fill"]), (1, 0, 1))

    def test_stop_requested_is_not_retried(self):
        runner = run.Runner(self.env.cfg, "run")
        runner.stopped = True                                  # «stop after this file»: no second copy
        with mock.patch.object(run, "run_rsync", side_effect=Mirror(vanish=1)) as rs:
            dst = self.env.cache / "x.mkv"
            rc, _ = runner.rsync_copy(["rsync", str(self.env.array / self.f.rel), str(dst)], dst, "x.mkv", None)
        self.assertEqual((rc, rs.call_count), (23, 1))


class VanishedBackTests(unittest.TestCase):
    """The give-back by rsync (cleanup_tool=rsync, to /mnt/user0) the same way."""

    def setUp(self):
        self.env = env = Env("btrfs")
        (env.cache / "Filme" / "A").mkdir(parents=True)
        self.src = env.cache / "Filme" / "A" / "a.mkv"
        self.src.write_bytes(b"y" * 500)
        env.share_cfg("Filme", "yes")
        (env.array / "Filme").mkdir()
        env.cfg["cleanup_tool"] = "rsync"
        lib.write_exclude({str(self.src)})
        self.patches = [mock.patch.object(lib, "MOUNTS_FILE", str(env.mounts)),
                        mock.patch.object(lib.subprocess, "run", FakeZfs(env))]
        for p in self.patches:
            p.start()

    def tearDown(self):
        for p in self.patches:
            p.stop()

    def test_back_vanished_once_then_through(self):
        runner = run.Runner(self.env.cfg, "run")
        with mock.patch.object(run, "run_rsync", side_effect=Mirror(vanish=1)) as rs:
            runner.cleanup(self.env.loc(), set(), NO_SESSIONS, set())
        self.assertEqual(rs.call_count, 2)
        self.assertFalse(self.src.exists())
        self.assertTrue((self.env.array / "Filme/A/a.mkv").is_file())
        self.assertEqual((runner.moved_back, runner.failed_files["back"]), (1, 0))

    def test_back_failed_counted(self):
        runner = run.Runner(self.env.cfg, "run")
        with mock.patch.object(run, "run_rsync", side_effect=Mirror(vanish=5)):
            runner.cleanup(self.env.loc(), set(), NO_SESSIONS, set())
        self.assertTrue(self.src.exists())
        self.assertEqual(runner.failed_files["back"], 1)


if __name__ == "__main__":
    unittest.main()
