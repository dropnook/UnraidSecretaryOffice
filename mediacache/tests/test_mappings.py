"""USO (2026-11-08): a path mapping with an empty host path means «deliberately not cached», and the longest line wins —
an empty line is a line too, under a broader mapping as well as alone; a real line below an empty one still maps.

Run:  python3 -m unittest discover -s tests -v
"""
import unittest

from test_share_root import lib

CFG = {"cache_path": "/mnt/cache", "array_path": "/mnt/user0", "user_path": "/mnt/user", "array_disks_glob": "/mnt/disk[0-9]*"}


class MappingTests(unittest.TestCase):
    def loc(self, maps):
        return lib.Locations(CFG, {k: v for k, v in maps.items() if v}, sorted(k for k, v in maps.items() if not v))

    def test_empty_below_broader_is_not_cached(self):
        loc = self.loc({"/data/mediathek": "/mnt/user/Mediathek", "/data/mediathek/Diverses": ""})
        self.assertIsNone(loc.rel_from_docker("/data/mediathek/Diverses/x/x.mkv"))
        self.assertIsNone(loc.rel_from_docker("/data/mediathek/Diverses"))
        self.assertTrue(loc.skipped("/data/mediathek/Diverses/x/x.mkv"))
        self.assertEqual(str(loc.rel_from_docker("/data/mediathek/Andere/y.mkv")), "Mediathek/Andere/y.mkv")

    def test_border_only(self):
        loc = self.loc({"/data/mediathek": "/mnt/user/Mediathek", "/data/mediathek/Diverses": ""})
        self.assertEqual(str(loc.rel_from_docker("/data/mediathek/Diverses2/z.mkv")), "Mediathek/Diverses2/z.mkv")

    def test_real_below_empty_maps(self):
        loc = self.loc({"/data": "", "/data/movies": "/mnt/user/Filme"})
        self.assertEqual(str(loc.rel_from_docker("/data/movies/A/a.mkv")), "Filme/A/a.mkv")
        self.assertIsNone(loc.rel_from_docker("/data/other/b.mkv"))
        self.assertFalse(loc.skipped("/data/movies/A/a.mkv", len("/data/movies")))

    def test_empty_alone(self):
        loc = self.loc({"/data/movies": "/mnt/user/Filme", "/data/mediathek/Diverses": ""})
        self.assertIsNone(loc.rel_from_docker("/data/mediathek/Diverses/x.mkv"))
        self.assertEqual(str(loc.rel_from_docker("/data/movies/A/a.mkv")), "Filme/A/a.mkv")


if __name__ == "__main__":
    unittest.main()
