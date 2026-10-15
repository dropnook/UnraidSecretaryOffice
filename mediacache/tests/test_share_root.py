"""Tests for share-root creation on the cache pool (ZFS dataset vs. folder) and the unchanged cleanup path.

No real server needed: /proc/mounts, the zfs binary, rsync, the move binary and the share .cfg files are faked.
Run:  python3 -m unittest discover -s tests -v     (or: python3 -m pytest tests)
"""
import copy
import os
import shutil
import stat
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

_WORK = tempfile.mkdtemp(prefix="mediacache-test-")
os.environ["MEDIACACHE_DIR"] = _WORK  # logs/, status and exclude list go to a temp dir, never into the repo
os.environ.setdefault("MEDIACACHE_LOG_LEVEL", "WARNING")
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import mediacache_lib as lib  # noqa: E402
import mediacache_run as run  # noqa: E402


class FakeZfs:
    """Stands in for subprocess.run of the zfs binary. `zfs create` creates the mount dir and a mounts entry."""

    def __init__(self, env):
        self.env = env
        self.calls = []
        self.datasets = set()

    def __call__(self, cmd, *args, **kwargs):
        if cmd[0] != lib.ZFS_BIN:
            raise AssertionError(f"unexpected command {cmd}")
        self.calls.append(list(cmd))
        if cmd[1] == "list":
            name = cmd[-1]
            ok = name in self.datasets
            return subprocess.CompletedProcess(cmd, 0 if ok else 1, name + "\n" if ok else "",
                                               "" if ok else f"cannot open '{name}': dataset does not exist")
        if cmd[1] == "create":
            name = cmd[2]
            self.datasets.add(name)
            mp = self.env.cache / name.split("/", 1)[1]
            mp.mkdir()
            self.env.add_mount(name, mp, "zfs")
            return subprocess.CompletedProcess(cmd, 0, "", "")
        raise AssertionError(f"forbidden zfs subcommand {cmd}")

    def creates(self):
        return [c for c in self.calls if c[1] == "create"]


class Env:
    """Temp tree: /mnt/<pool>, /mnt/user0, /mnt/disk1, /boot/config/shares and a fake /proc/mounts."""

    def __init__(self, fstype, pool="cache"):
        self.root = Path(tempfile.mkdtemp(prefix="mc-env-", dir=_WORK))
        self.cache = self.root / "mnt" / pool
        self.array = self.root / "mnt" / "user0"
        self.disk1 = self.root / "mnt" / "disk1"
        self.shares = self.root / "boot" / "config" / "shares"
        for d in (self.cache, self.array, self.disk1, self.shares):
            d.mkdir(parents=True)
        self.mounts = self.root / "mounts"
        self.mounts.write_text("rootfs / rootfs rw 0 0\n")
        self.add_mount(pool if fstype == "zfs" else "/dev/nvme0n1p1", self.cache, fstype)
        self.cfg = copy.deepcopy(lib.DEFAULTS)
        self.cfg.update(cache_path=str(self.cache), array_path=str(self.array), user_path=str(self.root / "mnt" / "user"),
                        array_disks_glob=str(self.root / "mnt" / "disk[0-9]*"), shares_cfg_dir=str(self.shares),
                        min_free_percent=0, copy_progress=False, status_log_interval=0)

    def add_mount(self, source, mountpoint, fstype):
        with self.mounts.open("a") as fh:
            esc = lambda v: str(v).replace(" ", "\\040")  # noqa: E731 – /proc/mounts escaping
            fh.write(f"{esc(source)} {esc(mountpoint)} {fstype} rw 0 0\n")

    def share_cfg(self, share, use_cache="yes", pool="cache"):
        (self.shares / f"{share}.cfg").write_text(f'shareUseCache="{use_cache}"\nshareCachePool="{pool}"\n')

    def array_file(self, rel, data=b"x" * 1000, mode=0o2775):
        share_dir = self.array / Path(rel).parts[0]
        share_dir.mkdir(exist_ok=True)
        os.chmod(share_dir, mode)
        f = self.array / rel
        f.parent.mkdir(parents=True, exist_ok=True)
        f.write_bytes(data)
        return run.OnDeckFile(Path(rel), len(data), False, "test")

    def loc(self):
        return lib.Locations(self.cfg, {})


NO_SESSIONS = (set(), set())


def fake_rsync(cmd, on_progress=None):
    shutil.copy2(cmd[-2], cmd[-1])
    return 0, ""


class ShareRootTests(unittest.TestCase):
    def setUp(self):
        self.patches = []

    def tearDown(self):
        for p in self.patches:
            p.stop()

    def start(self, env):
        self.zfs = FakeZfs(env)
        for p in (mock.patch.object(lib, "MOUNTS_FILE", str(env.mounts)),
                  mock.patch.object(lib.subprocess, "run", self.zfs),
                  mock.patch.object(run, "run_rsync", side_effect=fake_rsync)):
            p.start()
            self.patches.append(p)

    def runner(self, env, mode="run"):
        return run.Runner(env.cfg, mode)

    def test_zfs_pool_without_share_root_creates_dataset(self):
        env = Env("zfs")
        self.start(env)
        env.share_cfg("Filme")
        f = env.array_file("Filme/A (2020)/a.mkv")
        with self.assertLogs("MediaCache", "INFO") as logs:
            self.runner(env).fill(env.loc(), [f], NO_SESSIONS)
        self.assertEqual(self.zfs.creates(), [["zfs", "create", "cache/Filme"]])
        root = env.cache / "Filme"
        self.assertEqual(stat.S_IMODE(root.stat().st_mode), 0o2775)
        self.assertEqual((root.stat().st_uid, root.stat().st_gid),
                         ((env.array / "Filme").stat().st_uid, (env.array / "Filme").stat().st_gid))
        self.assertTrue((root / "A (2020)" / "a.mkv").is_file())
        self.assertFalse((env.array / "Filme/A (2020)/a.mkv").exists())
        self.assertTrue(any("Dataset cache/Filme angelegt" in m for m in logs.output))

    def test_zfs_dataset_created_once_per_run(self):
        env = Env("zfs")
        self.start(env)
        env.share_cfg("Serien")
        files = [env.array_file(f"Serien/S/S01/e{i}.mkv") for i in range(3)]
        self.runner(env).fill(env.loc(), files, NO_SESSIONS)
        self.assertEqual(len(self.zfs.creates()), 1)
        self.assertTrue(all((env.cache / f.rel).is_file() for f in files))

    def test_zfs_share_root_as_plain_folder_warns_and_is_not_converted(self):
        env = Env("zfs")
        self.start(env)
        env.share_cfg("Filme")
        (env.cache / "Filme").mkdir()
        files = [env.array_file("Filme/A/a.mkv"), env.array_file("Filme/B/b.mkv")]
        with self.assertLogs("MediaCache", "WARNING") as logs:
            self.runner(env).fill(env.loc(), files, NO_SESSIONS)
        self.assertEqual(self.zfs.creates(), [])
        warnings = [m for m in logs.output if "normaler Ordner" in m]
        self.assertEqual(len(warnings), 1)
        self.assertIn(str(env.cache / "Filme"), warnings[0])
        self.assertTrue((env.cache / "Filme/A/a.mkv").is_file())

    def test_zfs_existing_dataset_no_warning(self):
        env = Env("zfs")
        self.start(env)
        (env.cache / "Filme").mkdir()
        env.add_mount("cache/Filme", env.cache / "Filme", "zfs")
        f = env.array_file("Filme/A/a.mkv")
        with self.assertNoLogs("MediaCache", "WARNING"):
            self.runner(env).fill(env.loc(), [f], NO_SESSIONS)
        # USO: the free-space check reads the pool (zfs list -Hp -o used,avail) – no create, no other call
        self.assertEqual([c for c in self.zfs.calls if c[1:4] != ["list", "-Hp", "-o"]], [])
        self.assertTrue((env.cache / f.rel).is_file())

    def test_zfs_share_not_allowed_on_pool_is_skipped(self):
        for kwargs in ({"use_cache": "no"}, {"use_cache": "yes", "pool": "other"}):
            with self.subTest(**kwargs):
                env = Env("zfs")
                self.start(env)
                env.share_cfg("Filme", **kwargs)
                f = env.array_file("Filme/A/a.mkv")
                with self.assertLogs("MediaCache", "ERROR") as logs:
                    self.runner(env).fill(env.loc(), [f], NO_SESSIONS)
                self.assertEqual(self.zfs.creates(), [])
                self.assertFalse((env.cache / "Filme").exists())
                self.assertTrue((env.array / f.rel).is_file())
                self.assertTrue(any("darf laut Share-Einstellung nicht" in m for m in logs.output))
                self.tearDown()
                self.patches = []

    def test_zfs_share_without_cfg_is_skipped(self):
        env = Env("zfs")
        self.start(env)
        f = env.array_file("Filme/A/a.mkv")
        with self.assertLogs("MediaCache", "ERROR"):
            self.runner(env).fill(env.loc(), [f], NO_SESSIONS)
        self.assertEqual(self.zfs.creates(), [])

    def test_btrfs_and_xfs_use_mkdir_like(self):
        for fstype in ("btrfs", "xfs"):
            with self.subTest(fstype=fstype):
                env = Env(fstype)
                self.start(env)
                f = env.array_file("Filme/A/a.mkv", mode=0o2770)
                self.runner(env).fill(env.loc(), [f], NO_SESSIONS)
                self.assertEqual(self.zfs.calls, [])
                root = env.cache / "Filme"
                self.assertTrue(root.is_dir())
                self.assertEqual(stat.S_IMODE(root.stat().st_mode), 0o2770)
                self.assertTrue((env.cache / f.rel).is_file())
                self.tearDown()
                self.patches = []

    def test_dry_run_creates_nothing_and_names_dataset(self):
        env = Env("zfs")
        self.start(env)
        env.share_cfg("Filme")
        f = env.array_file("Filme/A/a.mkv")
        r = self.runner(env, "dry")
        with self.assertLogs("MediaCache", "INFO") as logs:
            r.fill(env.loc(), [f], NO_SESSIONS)
        self.assertEqual(self.zfs.creates(), [])
        self.assertFalse((env.cache / "Filme").exists())
        self.assertTrue((env.array / f.rel).is_file())
        self.assertTrue(any("würde Dataset cache/Filme anlegen" in m for m in logs.output))
        self.assertEqual(r.fill_planned, 1)

    def test_create_share_root_false_skips(self):
        env = Env("zfs")
        self.start(env)
        env.share_cfg("Filme")
        env.cfg["create_share_root"] = False
        f = env.array_file("Filme/A/a.mkv")
        with self.assertLogs("MediaCache", "ERROR"):
            self.runner(env).fill(env.loc(), [f], NO_SESSIONS)
        self.assertEqual(self.zfs.calls, [])
        self.assertFalse((env.cache / "Filme").exists())

    def test_zfs_dataset_not_mounted_is_skipped(self):
        env = Env("zfs")
        self.start(env)
        env.share_cfg("Filme")
        (env.cache / "Filme").mkdir()  # leftover mountpoint dir inside the pool dataset
        self.zfs.datasets.add("cache/Filme")
        f = env.array_file("Filme/A/a.mkv")
        with self.assertLogs("MediaCache", "ERROR") as logs:
            self.runner(env).fill(env.loc(), [f], NO_SESSIONS)
        self.assertEqual(self.zfs.creates(), [])
        self.assertFalse((env.cache / f.rel).exists())
        self.assertTrue(any("nicht unter" in m and "eingehängt" in m for m in logs.output))

    def test_unknown_pool_fs_is_skipped(self):
        env = Env("zfs")
        self.start(env)
        env.mounts.write_text("rootfs / rootfs rw 0 0\n")  # pool not mounted
        f = env.array_file("Filme/A/a.mkv")
        with self.assertLogs("MediaCache", "ERROR"):
            self.runner(env).fill(env.loc(), [f], NO_SESSIONS)
        self.assertEqual(self.zfs.calls, [])
        self.assertFalse((env.cache / "Filme").exists())

    def test_mounts_escaped_space(self):
        env = Env("zfs")
        self.start(env)
        (env.cache / "Meine Filme").mkdir()
        env.add_mount("cache/Meine Filme", env.cache / "Meine Filme", "zfs")
        m = lib.mount_of(env.cache / "Meine Filme" / "x")
        self.assertEqual(m, ("cache/Meine Filme", str(env.cache / "Meine Filme"), "zfs"))


class CleanupRegressionTests(unittest.TestCase):
    """Cache -> Array stays on Unraid's move binary / rsync to /mnt/user0; no zfs call, no share root logic."""

    def setUp(self):
        self.env = env = Env("zfs")
        (env.cache / "Filme" / "A").mkdir(parents=True)
        self.src = env.cache / "Filme" / "A" / "a.mkv"
        self.src.write_bytes(b"y" * 500)
        env.share_cfg("Filme", "yes")
        (env.array / "Filme").mkdir()
        lib.write_exclude({str(self.src)})
        self.zfs = FakeZfs(env)
        self.patches = [mock.patch.object(lib, "MOUNTS_FILE", str(env.mounts)),
                        mock.patch.object(lib.subprocess, "run", self.zfs)]
        for p in self.patches:
            p.start()

    def tearDown(self):
        for p in self.patches:
            p.stop()

    def test_cleanup_rsync_to_user0(self):
        self.env.cfg["cleanup_tool"] = "rsync"
        with mock.patch.object(run, "run_rsync", side_effect=fake_rsync) as rs:
            run.Runner(self.env.cfg, "run").cleanup(self.env.loc(), set(), NO_SESSIONS, set())
        cmd = rs.call_args[0][0]
        self.assertEqual(cmd[:3], ["rsync", "-aAX", "--numeric-ids"])
        self.assertEqual(cmd[-2:], [str(self.src), str(self.env.array / "Filme/A/a.mkv")])
        self.assertFalse(self.src.exists())
        self.assertEqual(self.zfs.calls, [])

    def test_cleanup_move_binary(self):
        self.env.cfg["cleanup_tool"] = "mover"

        def fake_mover(mover_bin, paths, debug_level, log):
            for p in paths:
                Path(p).unlink()
            return 0, "", ""

        with mock.patch.object(run, "detect_mover_bin", return_value="/usr/libexec/unraid/move"), \
                mock.patch.object(run, "run_mover", side_effect=fake_mover) as mv:
            r = run.Runner(self.env.cfg, "run")
            r.cleanup(self.env.loc(), set(), NO_SESSIONS, set())
        self.assertEqual(mv.call_args[0][:2], ("/usr/libexec/unraid/move", [str(self.src)]))
        self.assertEqual(r.moved_back, 1)
        self.assertEqual(self.zfs.calls, [])


if __name__ == "__main__":
    unittest.main()
