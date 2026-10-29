"""USO (2026-10-29, Jack Streamer «theme.mp3 stays on the pool»): the collector moves every film's and every series'
theme.mp3 from the array to the cache in a real run only — never a dry run, a report or --release —, looks next time only
at what is new, keeps them in their own section of the exclude list for good (never given back by the budget, by
--release, by a run without the collector) and only Jack's let-go (MEDIACACHE_RELEASE_THEMES=1) or the switch off brings
them back. A stand-in media server (its library: films, a series, a folder item, a flat film), rsync a copy.

Run:  python3 -m unittest discover -s tests -v
"""
import json
import os
import shutil
import tempfile
import unittest
from pathlib import Path
from unittest import mock

from test_share_root import Env, FakeZfs, fake_rsync, lib, run


class FakeServer:
    """The media server's library (Emby's /Items): [{Id, Type, Path, DateCreated}], or an error"""

    def __init__(self, items=None, fail=False):
        self.items, self.fail, self.asked = list(items or []), fail, 0

    def library_items(self):
        self.asked += 1
        if self.fail:
            raise RuntimeError("HTTP 401 bei /Items")
        return [dict(i) for i in self.items]

    def theme_folder(self, item):
        return item.get("Path") or ""


class Stand:
    """Planner stand-in: nothing on deck (the collector works on its own)"""
    errors, users, files, budget_mode = 0, {}, {}, False

    def __init__(self, cfg=None):
        pass

    def run(self):
        return []

    def not_in_budget(self):
        return None


MOVIE = lambda i, path, dc="2026-10-01T10:00:00.0000000Z": {"Id": i, "Type": "Movie", "Path": "/media/" + path, "DateCreated": dc}  # noqa: E731
SERIES = lambda i, path, dc="2026-10-01T10:00:00.0000000Z": {"Id": i, "Type": "Series", "Path": "/media/" + path, "DateCreated": dc}  # noqa: E731


class ThemesTests(unittest.TestCase):
    def setUp(self):
        self.env = env = Env("btrfs")
        env.share_cfg("Filme")
        env.share_cfg("Serien")
        env.cfg.update(themes=True, cleanup_tool="rsync", api_timeout=1)
        env.cfg["instances"] = [{"servername": "Emby", "url": "http://x", "api_key": "k",
                                 "_mappings": {"/media": str(env.root / "mnt" / "user")}, "_skip": []}]
        self.data = Path(tempfile.mkdtemp(dir=env.root))
        self.list = self.data / "mediacache_exclude.txt"
        self.state_file = self.data / "mediacache_themes.json"
        self.progress = self.data / "progress.json"
        self.stop = self.data / "stop"
        self.seen = []           # every progress file written
        self.server = FakeServer()
        self.servers = {"Emby": self.server}
        self.patches = [mock.patch.object(lib, "MOUNTS_FILE", str(env.mounts)),
                        mock.patch.object(lib.subprocess, "run", FakeZfs(env)),
                        mock.patch.object(lib, "EXCLUDE_FILE", self.list),
                        mock.patch.object(run, "EXCLUDE_FILE", self.list),
                        mock.patch.object(lib, "THEMES_FILE", self.state_file),
                        mock.patch.object(run, "PROGRESS_FILE", str(self.progress)),
                        mock.patch.object(run, "write_progress", lambda d: self.seen.append(json.loads(json.dumps(d)))),
                        mock.patch.object(run, "STOP_FILE", str(self.stop)),
                        mock.patch.object(run, "run_rsync", side_effect=fake_rsync),
                        mock.patch.object(run, "unraid_mover_running", return_value=False),
                        mock.patch.object(run, "theme_disk", lambda loc, rel: {"Filme": "disk1", "Serien": "disk2"}[Path(rel).parts[0]]),
                        mock.patch.object(run.MediaServerApi, "for_instance",
                                          classmethod(lambda cls, inst, t: self.servers[inst["servername"]])),
                        mock.patch.object(run.Runner, "report", lambda *a, **k: None)]
        for p in self.patches:
            p.start()

    def tearDown(self):
        for p in self.patches:
            p.stop()

    # ------------------------------------------------------------------ helpers
    def on_array(self, rel, data=b"t" * 300):
        f = self.env.array / rel
        f.parent.mkdir(parents=True, exist_ok=True)
        f.write_bytes(data)
        return f

    def on_cache(self, rel, data=b"t" * 300):
        f = self.env.cache / rel
        f.parent.mkdir(parents=True, exist_ok=True)
        f.write_bytes(data)
        return f

    def library(self):
        """Three films (folder, folder, a DVD folder item), a flat film, one series — with their theme.mp3 on the array"""
        for rel in ("Filme/Alpha (2020)/Alpha.mkv", "Filme/Beta (2021)/Beta.mkv", "Filme/Delta (2019)/VIDEO_TS/VTS_01_1.VOB",
                    "Filme/Flach.mkv", "Serien/Gamma/Season 01/Gamma S01E01.mkv"):
            self.on_array(rel, b"v" * 2000)
        for rel in ("Filme/Alpha (2020)/theme.mp3", "Filme/Beta (2021)/theme.mp3", "Filme/Delta (2019)/theme.mp3",
                    "Filme/theme.mp3", "Serien/Gamma/theme.mp3"):
            self.on_array(rel)
        self.server.items = [MOVIE("a", "Filme/Alpha (2020)/Alpha.mkv"), MOVIE("b", "Filme/Beta (2021)/Beta.mkv"),
                             MOVIE("d", "Filme/Delta (2019)"), MOVIE("f", "Filme/Flach.mkv"), SERIES("g", "Serien/Gamma")]

    def execute(self, mode="run", planner=Stand, env=None):
        with mock.patch.object(run, "Planner", planner), \
             mock.patch.object(run, "collect_sessions", return_value=((set(), set()), True)), \
             mock.patch.dict(os.environ, env or {}), \
             mock.patch.object(run, "RELEASE_THEMES", (env or {}).get("MEDIACACHE_RELEASE_THEMES") == "1"), \
             self.assertLogs("MediaCache", "INFO") as logs:
            runner = run.Runner(self.env.cfg, mode)
            rc = runner.execute()
        return rc, runner.result, "\n".join(logs.output)

    def sections(self):
        text = self.list.read_text() if self.list.exists() else ""
        lines = text.splitlines()
        if lib.THEMES_MARK not in lines:
            return [x for x in lines if x], []
        i = lines.index(lib.THEMES_MARK)
        return [x for x in lines[:i] if x], [x for x in lines[i + 1:] if x]

    def state(self):
        return json.loads(self.state_file.read_text())

    def cache(self, rel):
        return str(self.env.cache / rel)

    # ------------------------------------------------------------------ the collector
    def test_films_series_folder_item_moved_flat_film_left(self):
        self.library()
        rc, res, out = self.execute()
        self.assertEqual(rc, 0)
        moved = ["Filme/Alpha (2020)/theme.mp3", "Filme/Beta (2021)/theme.mp3", "Filme/Delta (2019)/theme.mp3", "Serien/Gamma/theme.mp3"]
        for rel in moved:
            with self.subTest(rel=rel):
                self.assertTrue((self.env.cache / rel).is_file(), "on the cache")
                self.assertFalse((self.env.array / rel).exists(), "the source gone after the verified copy")
        self.assertTrue((self.env.array / "Filme/theme.mp3").is_file(), "a flat film has no theme of its own: the root's stays")
        self.assertTrue((self.env.array / "Filme/Alpha (2020)/Alpha.mkv").is_file(), "only theme.mp3 — the film stays on the array")
        files, themes = self.sections()
        self.assertEqual(files, [], "nothing on deck")
        self.assertEqual(themes, sorted(self.cache(r) for r in moved), "every theme in its own section")
        t = res["themes"]
        self.assertEqual((t["first"], t["to_look"], t["looked"], t["fetched"], t["fetched_bytes"], t["on_pool"]),
                         (True, 4, 4, 4, 1200, {"files": 4, "bytes": 1200}), "the flat film never looked at")
        self.assertEqual(t["disks"], ["disk1", "disk2"], "the disks the themes came from")
        st = self.state()
        self.assertEqual(st["v"], 1)
        self.assertTrue(st["done"], "the first collection is done")
        self.assertEqual({k: v[2] for k, v in st["items"].items()},
                         {"Emby|a": "pool", "Emby|b": "pool", "Emby|d": "pool", "Emby|f": "flat", "Emby|g": "pool"})
        self.assertEqual(st["items"]["Emby|d"][1], "Filme/Delta (2019)", "a folder item: its theme lies inside it")
        self.assertEqual(st["on_pool"], {"files": 4, "bytes": 1200})

    def test_progress_has_its_own_phase(self):
        self.library()
        self.execute()
        phases = [p["phase"] for p in self.seen]
        self.assertIn("themes", phases)
        at = [p for p in self.seen if p["phase"] == "themes" and p["active"]]
        self.assertTrue(at and all(a["phase"] == "themes" for p in at for a in p["active"]), "the theme in work, phase themes")
        self.assertEqual(at[0]["active"][0]["target"], self.cache(at[0]["active"][0]["rel"]))
        last = self.seen[-1]
        self.assertEqual(last["phase"], "done")
        self.assertEqual({k: last["themes"][k] for k in ("titles", "looked", "files", "bytes", "done_files", "done_bytes")},
                         {"titles": 4, "looked": 4, "files": 4, "bytes": 1200, "done_files": 4, "done_bytes": 1200})
        self.assertTrue(last["themes"]["started"] and last["themes"]["ended"])
        self.assertEqual(sum(1 for r in last["recent"] if r["name"].endswith("theme.mp3") and r["to"] == "cache"), 4)

    def test_already_on_the_pool_the_array_not_looked_at(self):
        self.server.items = [MOVIE("a", "Filme/Alpha (2020)/Alpha.mkv")]
        self.on_cache("Filme/Alpha (2020)/theme.mp3")
        with mock.patch.object(run.Locations, "on_array", side_effect=AssertionError("the array asked")):
            rc, res, _ = self.execute()
        self.assertEqual((rc, res["themes"]["fetched"], res["themes"]["on_pool"]["files"]), (0, 0, 1))
        self.assertEqual(self.sections()[1], [self.cache("Filme/Alpha (2020)/theme.mp3")], "listed: a new film's theme stays on the pool")

    def test_on_both_the_pool_copy_counts_nothing_deleted(self):
        self.server.items = [MOVIE("a", "Filme/Alpha (2020)/Alpha.mkv")]
        self.on_cache("Filme/Alpha (2020)/theme.mp3", b"c" * 100)
        arr = self.on_array("Filme/Alpha (2020)/theme.mp3", b"a" * 200)
        _, res, _ = self.execute()
        self.assertTrue(arr.is_file(), "the array's copy untouched")
        self.assertEqual((self.env.cache / "Filme/Alpha (2020)/theme.mp3").read_bytes(), b"c" * 100, "the pool's untouched")
        self.assertEqual(res["themes"]["on_pool"], {"files": 1, "bytes": 100})

    def test_next_run_looks_only_at_what_is_new(self):
        self.library()
        self.execute()
        self.on_array("Filme/Epsilon (2026)/theme.mp3")
        self.server.items.append(MOVIE("e", "Filme/Epsilon (2026)/Epsilon.mkv"))
        before = self.state()["items"]
        _, res, _ = self.execute()
        t = res["themes"]
        self.assertEqual((t["first"], t["to_look"], t["looked"], t["fetched"]), (False, 1, 1, 1), "only the new title")
        after = self.state()["items"]
        self.assertEqual({k: v for k, v in after.items() if k != "Emby|e"}, before, "the others untouched")
        self.assertEqual(after["Emby|e"][2], "pool")
        self.assertEqual(len(self.sections()[1]), 5)

    def test_looked_again_newer_date_other_path_gone_from_the_pool(self):
        self.library()
        self.execute()
        items = {i["Id"]: i for i in self.server.items}
        items["a"]["DateCreated"] = "2099-01-01T00:00:00.0000000Z"            # replaced: newer than its look (its theme there)
        items["f"]["DateCreated"] = "2099-01-01T00:00:00.0000000Z"            # a flat film: never looked at, whatever its date
        items["b"]["Path"] = "/media/Filme/Beta neu (2021)/Beta.mkv"         # moved by the server
        self.on_array("Filme/Beta neu (2021)/theme.mp3")
        (self.env.cache / "Serien/Gamma/theme.mp3").unlink()                 # gone from the pool (someone moved it)
        self.on_array("Serien/Gamma/theme.mp3")
        self.server.items = list(items.values())
        _, res, _ = self.execute()
        self.assertEqual((res["themes"]["to_look"], res["themes"]["fetched"]), (3, 2))
        self.assertTrue((self.env.cache / "Serien/Gamma/theme.mp3").is_file())
        self.assertTrue((self.env.cache / "Filme/Beta neu (2021)/theme.mp3").is_file())
        self.assertIn(self.cache("Filme/Beta (2021)/theme.mp3"), self.sections()[1],
                      "the old folder's theme, still on the pool, stays listed (never given back, never deleted)")
        self.assertTrue((self.env.cache / "Filme/Beta (2021)/theme.mp3").is_file())

    def test_a_title_gone(self):
        self.library()
        self.execute()
        shutil.rmtree(self.env.cache / "Filme/Beta (2021)")                   # deleted through the user share
        shutil.rmtree(self.env.array / "Filme/Beta (2021)")
        self.server.items = [i for i in self.server.items if i["Id"] != "b"]
        rc, res, _ = self.execute()
        self.assertEqual((rc, res["themes"]["to_look"], res["themes"]["on_pool"]["files"]), (0, 0, 3))
        self.assertNotIn("Emby|b", self.state()["items"])
        self.assertNotIn(self.cache("Filme/Beta (2021)/theme.mp3"), self.sections()[1])

    def test_a_server_that_does_not_answer_keeps_its_state(self):
        self.library()
        self.execute()
        before = self.state()["items"]
        self.server.fail = True
        rc, res, out = self.execute()
        self.assertEqual((rc, res["themes"]["servers_failed"], res["themes"]["to_look"]), (0, ["Emby"], 0))
        self.assertEqual(self.state()["items"], before)
        self.assertEqual(len(self.sections()[1]), 4, "its themes stay listed")
        self.assertIn("nicht abrufbar (theme.mp3)", out)

    def test_stop_mid_way_the_rest_waits(self):
        self.library()
        calls = []

        def rsync_then_stop(cmd, on_progress=None):
            calls.append(cmd[-1])
            self.stop.write_text("{}")          # asked to stop while the first theme copies
            return fake_rsync(cmd)
        with mock.patch.object(run, "run_rsync", side_effect=rsync_then_stop):
            rc, res, _ = self.execute()
        self.assertEqual((rc, res["result"] if "result" in res else None), (3, None))
        self.assertEqual((len(calls), res["themes"]["fetched"], res["themes"]["waiting"]), (1, 1, 3))
        st = self.state()
        self.assertFalse(st["done"], "the first collection isn't done")
        self.assertEqual(sorted(v[2] for v in st["items"].values()), ["flat", "pool", "wait", "wait", "wait"])
        self.assertEqual(len(self.sections()[1]), 1, "what came is listed")
        self.stop.unlink()
        rc, res, _ = self.execute()
        self.assertEqual((rc, res["themes"]["first"], res["themes"]["to_look"], res["themes"]["fetched"]), (0, True, 3, 3))
        self.assertTrue(self.state()["done"])

    def test_stop_before_it_starts_nothing_looked_at(self):
        self.library()
        self.stop.write_text("{}")
        rc, res, _ = self.execute()
        self.assertEqual((rc, res["themes"]), (3, {"on": True}))
        self.assertFalse(self.state_file.exists())

    def test_no_room_waits(self):
        self.library()
        with mock.patch.object(run, "free_percent_after", return_value=5.0):
            self.env.cfg["min_free_percent"] = 10
            _, res, out = self.execute()
        self.assertEqual((res["themes"]["fetched"], res["themes"]["waiting"]), (0, 4))
        self.assertIn("WARTET: Freiplatz", out)
        self.assertTrue((self.env.array / "Serien/Gamma/theme.mp3").is_file())

    def test_a_failed_copy_keeps_the_source(self):
        self.library()
        with mock.patch.object(run, "run_rsync", return_value=(11, "rsync: write failed")):
            _, res, _ = self.execute()
        self.assertEqual((res["themes"]["fetched"], res["themes"]["failed"]), (0, 4))
        self.assertTrue((self.env.array / "Filme/Alpha (2020)/theme.mp3").is_file())
        self.assertEqual(self.sections()[1], [])

    def test_trials_move_nothing_write_no_state(self):
        self.library()
        for mode in ("dry", "report"):
            with self.subTest(mode=mode):
                _, res, out = self.execute(mode)
                t = res["themes"]
                self.assertEqual((t["first"], t["to_look"], t["shares"]), (True, 4, ["Filme", "Serien"]),
                                 "said beforehand: how many folders (the flat film never)")
                self.assertIn("zum ersten Mal", out)
                self.assertFalse(self.state_file.exists())
                self.assertFalse((self.env.cache / "Filme").exists())

    def test_no_fill_run_collects_nothing_and_keeps_the_section(self):
        self.library()
        self.execute()
        self.on_array("Filme/Epsilon (2026)/theme.mp3")
        self.server.items.append(MOVIE("e", "Filme/Epsilon (2026)/Epsilon.mkv"))
        with mock.patch.object(run, "NO_FILL", True):
            _, res, _ = self.execute()
        self.assertEqual(res["themes"], {"on": True})
        self.assertEqual(len(self.sections()[1]), 4, "the section survives a run without the collector")

    # ------------------------------------------------------------------ never back
    def test_the_section_is_never_given_back_by_a_cleanup(self):
        self.library()
        self.execute()
        old = self.on_cache("Filme/Old (2001)/Old.mkv", b"o" * 500)
        self.list.write_text(self.list.read_text().replace(lib.THEMES_MARK, f"{old}\n{lib.THEMES_MARK}"))
        _, res, _ = self.execute()
        self.assertEqual(res["cleanup"]["done"], 1, "the old film goes back")
        self.assertFalse(old.exists())
        self.assertEqual(len(self.sections()[1]), 4, "the themes stay")
        for rel in ("Filme/Alpha (2020)/theme.mp3", "Serien/Gamma/theme.mp3"):
            self.assertTrue((self.env.cache / rel).is_file())

    def test_release_leaves_them_the_letgo_takes_them(self):
        self.library()
        self.execute()
        film = self.on_cache("Filme/Old (2001)/Old.mkv", b"o" * 500)
        self.list.write_text(f"{film}\n" + self.list.read_text())
        _, res, _ = self.execute("release")
        self.assertFalse(film.exists(), "the film back on the array")
        self.assertEqual((res["cleanup"]["done"], res["themes"]["kept"]), (1, True))
        self.assertEqual(self.sections(), ([], sorted(self.sections()[1])))
        self.assertEqual(len(self.sections()[1]), 4, "--release leaves the themes")
        self.assertTrue(self.state()["done"], "and their state")
        _, res, _ = self.execute("release", env={"MEDIACACHE_RELEASE_THEMES": "1"})
        self.assertEqual((res["cleanup"]["done"], res["themes"]["kept"]), (4, False))
        for rel in ("Filme/Alpha (2020)/theme.mp3", "Filme/Delta (2019)/theme.mp3", "Serien/Gamma/theme.mp3"):
            self.assertTrue((self.env.array / rel).is_file(), "back on the array")
            self.assertFalse((self.env.cache / rel).exists())
        self.assertEqual(self.list.read_text(), "")
        self.assertEqual(self.state()["items"], {}, "the state reset: hired again, the first collection again")

    def test_switched_off_the_next_run_gives_them_back(self):
        self.library()
        self.execute()
        self.env.cfg["themes"] = False
        _, res, _ = self.execute("dry")
        self.assertEqual((res["cleanup"]["planned"], res["themes"]), (4, {"on": False}), "a dry run says they go back")
        self.assertTrue((self.env.cache / "Serien/Gamma/theme.mp3").is_file())
        _, res, _ = self.execute()
        self.assertEqual(res["cleanup"]["done"], 4)
        self.assertTrue((self.env.array / "Serien/Gamma/theme.mp3").is_file())
        self.assertEqual(self.sections(), ([], []))
        self.assertEqual(self.state()["items"], {})
        self.env.cfg["themes"] = True
        _, res, _ = self.execute("dry")
        self.assertTrue(res["themes"]["first"], "on again: the first collection again, said beforehand")

    def test_a_film_theme_from_before_moves_into_the_section(self):
        """A folder film brought its theme.mp3 along before the switch (in the films' section): with the switch on it is
        never given back with its film — the collector claims it."""
        film = self.on_cache("Filme/Alpha (2020)/Alpha.mkv", b"v" * 2000)
        theme = self.on_cache("Filme/Alpha (2020)/theme.mp3")
        self.list.write_text(f"{film}\n{theme}\n")
        self.server.items = [MOVIE("a", "Filme/Alpha (2020)/Alpha.mkv")]
        _, res, _ = self.execute()
        self.assertEqual(res["cleanup"]["done"], 1, "the film (no longer on deck) back")
        self.assertTrue(theme.is_file(), "its theme stays")
        self.assertEqual(self.sections(), ([], [str(theme)]))

    def test_budget_and_on_deck_leave_the_theme_to_the_collector(self):
        self.env.cfg["cache_budget_bytes"] = 0
        loc = lib.Locations(self.env.cfg, self.env.cfg["instances"][0]["_mappings"])
        self.on_array("Filme/Alpha (2020)/Alpha.mkv", b"v" * 2000)
        self.on_array("Filme/Alpha (2020)/theme.mp3")
        self.on_array("Filme/Delta (2019)/VIDEO_TS/VTS_01_1.VOB", b"v" * 2000)
        self.on_array("Filme/Delta (2019)/theme.mp3")
        p = run.Planner(self.env.cfg)
        for item, n in ((MOVIE("a", "Filme/Alpha (2020)/Alpha.mkv"), 2000), (MOVIE("d", "Filme/Delta (2019)"), 2000)):
            with self.subTest(item=item["Id"]):
                g = p.make_group(loc, item, "movie", "c", "Weiterschauen", "t", "Benj")
                self.assertEqual((g.size, [f.rel.name for f in g.files if f.rel.name == "theme.mp3"]), (n, []),
                                 "not in the film's files, not in anyone's budget")
        self.env.cfg["themes"] = False
        g = run.Planner(self.env.cfg).make_group(loc, MOVIE("a", "Filme/Alpha (2020)/Alpha.mkv"), "movie", "c", "Weiterschauen", "t", "Benj")
        self.assertEqual(g.size, 2300, "off: the theme travels with its film, as before")

    def test_the_list_reads_its_sections(self):
        self.list.write_text(f"/a\n# a comment\n\n{lib.THEMES_MARK}\n/b/theme.mp3\n")
        self.assertEqual((lib.read_exclude(), lib.read_exclude_themes()), ({"/a", "/b/theme.mp3"}, {"/b/theme.mp3"}))
        lib.write_exclude({"/a", "/b/theme.mp3"}, {"/b/theme.mp3"})
        self.assertEqual(self.list.read_text(), f"/a\n{lib.THEMES_MARK}\n/b/theme.mp3\n")
        lib.write_exclude({"/a"})
        self.assertEqual(self.list.read_text(), "/a\n", "no themes: no section, the list as before")

    def test_the_setting_only_a_real_true(self):
        cfg_file = self.data / "settings.json"
        for v, on in ((True, True), ("true", False), (1, False), (None, False)):
            with self.subTest(v=v):
                base = {"cache_path": str(self.env.cache), "array_path": str(self.env.array),
                        "instances": [{"url": "http://x", "api_key": "k", "path_mappings": {"/media": "/mnt/user"}}]}
                cfg_file.write_text(json.dumps(base | ({} if v is None else {"themes": v})))
                with mock.patch.object(lib, "CONFIG_FILE", cfg_file):
                    self.assertIs(lib.load_config()["themes"], on)

    def test_the_server_time(self):
        self.assertEqual([run.theme_time(v) for v in ("2026-10-01T10:00:00.0000000Z", 1790000000, "junk", None, True, -5)],
                         [1790848800, 1790000000, None, None, None, None])


class PlexThemesTests(unittest.TestCase):
    def test_plex_library_items_and_a_series_folder_when_needed(self):
        api = lib.PlexApi("http://plex", "tok")
        answers = {"/library/sections": {"Directory": [{"key": "1", "type": "movie"}, {"key": "2", "type": "show"}, {"key": "3", "type": "artist"}]},
                   "/library/sections/1/all": {"Metadata": [{"ratingKey": 10, "type": "movie", "addedAt": 1790000000,
                                                             "Media": [{"Part": [{"file": "/data/Filme/A/A.mkv"}]}]}]},
                   "/library/sections/2/all": {"Metadata": [{"ratingKey": 20, "type": "show", "addedAt": 1790000001,
                                                             "Location": [{"path": "/data/Serien/B"}]},
                                                            {"ratingKey": 21, "type": "show", "addedAt": 1790000002}]},
                   "/library/metadata/21": {"Metadata": [{"Location": [{"path": "/data/Serien/C"}]}]}}
        asked = []
        with mock.patch.object(lib.PlexApi, "get", lambda self, path, token=None, **p: asked.append(path) or answers[path]):
            items = api.library_items()
            self.assertEqual(items, [{"Id": "10", "Type": "Movie", "Path": "/data/Filme/A/A.mkv", "DateCreated": 1790000000},
                                     {"Id": "20", "Type": "Series", "Path": "/data/Serien/B", "DateCreated": 1790000001},
                                     {"Id": "21", "Type": "Series", "Path": "", "DateCreated": 1790000002}])
            self.assertNotIn("/library/metadata/21", asked, "no question per series up front")
            self.assertEqual(api.theme_folder(items[2]), "/data/Serien/C")

    def test_emby_pages_through_the_library(self):
        api = lib.MediaServerApi("http://emby", "k")
        pages = []

        def get(path, **p):
            pages.append((path, p["StartIndex"], p["IncludeItemTypes"]))
            n = 3 if p["StartIndex"] == 0 else 1
            return {"Items": [{"Id": f"{p['StartIndex']}-{i}", "Type": "Movie", "Path": "/m"} for i in range(n)] + [{"Type": "Folder"}]}
        with mock.patch.object(lib.MediaServerApi, "THEME_PAGE", 4), mock.patch.object(api, "get", get):
            items = api.library_items()
        self.assertEqual(len(items), 4, "two pages, a Folder left out")
        self.assertEqual(pages, [("/Items", 0, "Movie,Series"), ("/Items", 4, "Movie,Series")])
        with mock.patch.object(api, "get", return_value={"Error": 1}), self.assertRaises(RuntimeError):
            api.library_items()


if __name__ == "__main__":
    unittest.main()
