#!/usr/bin/env python3
"""
USO (2026-11-07, shadow mode; agent/desks/jack-shadow.php, docs/DEVELOPMENT.md «jack»). Not part of helmi1987's MediaCache:
the office's tool to compare its PHP port of the plan (agent/desks/jack-plan.php) with this planner, while this one keeps
moving the files.

One dry run, the same code as `mediacache_run.py` without flags (Runner(cfg, "dry")), that also writes:

  --tape <file>   the one set of answers: every API answer as it came (one JSON per line, 0600, in RAM; the office removes it
                  at once), the clock of this run and the pool's numbers, frozen for the whole run. The PHP planner plans
                  from exactly this, so a difference can only be logic, never a look a minute later. No key on it: a token
                  stands as its hash, plex.tv's tokens in an answer as `tape:<hash>`.
  --plan <file>   the plan in a stable form, the same shape as jackPlanDry()'s (jackPlanReleaseDry()'s for --mode release).
  --mode run|release  which real run this is the dry twin of (the office runs it right BEFORE that real run, with its environment:
                  MEDIACACHE_NO_FILL, _KEEP_BYTES, _WATCHED_ONLY, _GIVE_BACK_FREE, _RELEASE_THEMES …). `release` plans --release's
                  cleanup over the whole list, writing nothing (no list, no themes state).

Nothing in mediacache_lib.py or mediacache_run.py is changed for this: their module-level names are stood in for inside this
process only. `theme.mp3`'s scan is left out (it asks for every item of every library and belongs to no plan), and
Locations.on_disk() (its result only names a disk in the log unless array_source = disk) looks at no disk.

Exit 0 with a plan written, whatever the run's outcome (`outcome`: ok | config | busy | crash). Never moves, copies, writes the
exclude list, the status or the log: logs go to <--logs>.
"""
import argparse
import base64
import hashlib
import html
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
from pathlib import Path

import mediacache_lib as lib

TAPE_V = 1


def token_id(token):
    """The hash a token stands as on the tape (agent/desks/jack-plan.php: jackPlanTokenId())."""
    return hashlib.sha256(token.encode("utf-8", "surrogateescape")).hexdigest()[:16]


def token_of(req):
    """The key a request carries, as a hash."""
    for name in ("X-emby-token", "X-plex-token", "Authorization"):
        v = req.unredirected_hdrs.get(name)
        if v:
            m = re.fullmatch(r'MediaBrowser Token="(.*)"', v, re.S)
            return token_id(m.group(1) if m else v)
    return ""


ACCOUNT_KEEP = ("id", "title", "username")      # all the plan reads of plex.tv's /api/v2/user (when it is a dict)


def untoken(v):
    """An answer that is no dict, as it is (the plan reads its shape), every dict in it without a key holding «token»"""
    if isinstance(v, dict):
        return {k: untoken(x) for k, x in v.items() if "token" not in str(k).lower()}
    if isinstance(v, list):
        return [untoken(x) for x in v]
    return v


def scrub(url, body):
    """plex.tv's answers hold tokens. The account (/api/v2/user): only what the plan reads (an allow-list, never a token). Any other
    answer: every attribute or key with «token» in its name, in either quotes — the users' accessToken become `tape:<hash>` (the plan
    tells them apart by it), every other one goes."""
    if not url.startswith(lib.PlexApi.PLEX_TV):
        return body
    text = body.decode("utf-8", "surrogateescape")
    if urllib.parse.urlsplit(url).path.endswith("/api/v2/user"):
        try:
            acc = json.loads(text)
        except ValueError:
            return b"{}"                    # the plan can't read it either: it sees the same failure, none of the bytes
        return json.dumps({k: acc[k] for k in ACCOUNT_KEEP if k in acc} if isinstance(acc, dict) else untoken(acc)).encode("utf-8")

    def attr(m):
        name, q, val = m.group(1), m.group(2), m.group(3)
        if not val:
            return m.group(0)
        if name == "accessToken":
            return f'{name}={q}tape:{token_id(html.unescape(val))}{q}'
        return f'{name}={q}x{q}'
    text = re.sub(r'([A-Za-z_:][\w:.-]*[Tt][Oo][Kk][Ee][Nn][\w:.-]*)\s*=\s*(["\'])(.*?)\2', attr, text, flags=re.S)
    text = re.sub(r'("[^"]*[Tt][Oo][Kk][Ee][Nn][^"]*"\s*:\s*)"(?:[^"\\]|\\.)*"', r'\1"x"', text)
    return text.encode("utf-8", "surrogateescape")


class Tape:
    def __init__(self, path):
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        self.fh = os.fdopen(fd, "w", encoding="utf-8")
        self.now = time.time()
        self.add({"v": TAPE_V, "now": self.now, "py": list(sys.version_info[:2])})

    def add(self, rec):
        self.fh.write(json.dumps(rec, ensure_ascii=False) + "\n")

    def close(self):
        self.fh.close()


class Answer:
    """What the stood-in opener hands back: the body, already read."""

    def __init__(self, body):
        self.body = body

    def __enter__(self):
        return self

    def __exit__(self, *a):
        return False

    def read(self):
        return self.body


class TapeOpener:
    def __init__(self, real, tape):
        self.real, self.tape = real, tape

    def open(self, req, timeout=None):
        rec = {"url": req.full_url, "token": token_of(req)}
        try:
            with self.real.open(req, timeout=timeout) as resp:
                body = resp.read()
        except urllib.error.HTTPError as e:
            self.tape.add(dict(rec, http=e.code))
            raise
        except (urllib.error.URLError, TimeoutError, OSError):
            self.tape.add(dict(rec, error="unreachable"))
            raise
        except Exception:
            self.tape.add(dict(rec, crash=True))
            raise
        kept = scrub(req.full_url, body)
        try:
            rec["body"] = kept.decode("utf-8")
        except UnicodeDecodeError:
            rec["body64"] = base64.b64encode(kept).decode("ascii")
        self.tape.add(rec)
        return Answer(body)


class FrozenTime:
    """lib's `time`, with the tape's clock: Plex's days_to_monitor sees one moment."""

    def __init__(self, now):
        self._now = now

    def time(self):
        return self._now

    def __getattr__(self, name):
        return getattr(time, name)


def clean(v):
    """A text as both sides write it: a byte that is no UTF-8 (a file name; surrogateescape here) as \\xNN."""
    if isinstance(v, str):
        if not any(0xD800 <= ord(c) <= 0xDFFF for c in v):
            return v
        return "".join(f"\\x{ord(c) - 0xDC00:02x}" if 0xDC80 <= ord(c) <= 0xDCFF else "�" if 0xD800 <= ord(c) <= 0xDFFF else c for c in v)
    if isinstance(v, dict):
        return {clean(k): clean(x) for k, x in v.items()}
    if isinstance(v, (list, tuple)):
        return [clean(x) for x in v]
    return v


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--tape", required=True)
    ap.add_argument("--plan", required=True)
    ap.add_argument("--logs", required=True)
    ap.add_argument("--mode", choices=("run", "release"), default="run")
    args = ap.parse_args()

    lib.LOG_DIR = Path(args.logs)         # before mediacache_run: its import opens the log
    tape = Tape(args.tape)
    lib.time = FrozenTime(tape.now)
    lib._OPENER = TapeOpener(lib._OPENER, tape)
    if os.environ.get("MEDIACACHE_SHADOW_PLEX_TV"):    # the tests' stand-in for plex.tv
        lib.PlexApi.PLEX_TV = os.environ["MEDIACACHE_SHADOW_PLEX_TV"]

    import mediacache_run as run

    seen, real_usage = {}, lib.pool_usage

    def usage(path):
        key = str(path)
        if key not in seen:
            try:
                seen[key] = tuple(real_usage(path))
                tape.add({"pool": key, "total": seen[key][0], "free": seen[key][1]})
            except OSError as e:
                seen[key] = e
                tape.add({"pool": key, "error": True})
        if isinstance(seen[key], OSError):
            raise seen[key]
        return seen[key]

    lib.pool_usage = run.pool_usage = usage

    grabbed = {"planner": None, "order": [], "cleanup": [], "protected": set(), "dropped": []}

    class Planner(run.Planner):
        def __init__(self, cfg):
            super().__init__(cfg)
            grabbed["planner"] = self

        def run(self):
            grabbed["order"] = super().run()
            return grabbed["order"]

    run.Planner = Planner

    real_grouped = run.log_grouped

    def log_grouped(label, listing):
        if "-> ARRAY" in label:
            grabbed["cleanup"] = [str(run_cache / rel) for rel, _ in listing]
        return real_grouped(label, listing)

    run.log_grouped = log_grouped

    class Dropped:
        """Runner.progress: only what the fill leaves out."""

        def __init__(self, real):
            self.real = real

        def drop(self, f, write=True):
            grabbed["dropped"].append(str(f.rel))

        def __getattr__(self, name):
            return getattr(self.real, name)

    class Runner(run.Runner):
        def __init__(self, cfg):
            super().__init__(cfg, "dry")
            # --release keeps the themes unless MEDIACACHE_RELEASE_THEMES (Runner.__init__ asks mode == "release")
            if args.mode == "release":
                self.keep_themes = self.themes.on and not run.RELEASE_THEMES
            self.themes.on = False        # no scan of every library; keep_themes (the cleanup) stays what the run has
            self.progress = Dropped(self.progress)

        def write_list(self, paths):      # a dry twin writes nothing: --release's own write
            grabbed["list"] = sorted(paths)

        def release(self, sessions, sessions_ok):
            self.themes.off = lambda: None      # … nor the themes state
            return super().release(sessions, sessions_ok)

        def cleanup(self, loc, current, sessions, protected):
            grabbed["protected"] = protected
            return super().cleanup(loc, current, sessions, protected)

    log = run.log
    doc = {"outcome": "crash", "tape": TAPE_V}
    lock = None
    try:
        try:
            cfg = lib.load_config()
            lib.keep_bytes()
        except lib.ConfigError:
            doc["outcome"] = "config"
            return 0
        if cfg["array_source"] == "user0":
            lib.Locations.on_disk = lambda self, rel: []
        run_cache = Path(cfg["cache_path"])
        lock = lib.acquire_lock(log)
        if lock is None:
            doc["outcome"] = "busy"
            return 0
        runner = Runner(cfg)
        sessions = {}
        real_collect = run.collect_sessions

        def collect(c, lg):
            r = real_collect(c, lg)
            sessions["rels"], sessions["names"], sessions["ok"] = sorted(r[0][0]), sorted(r[0][1]), r[1]
            return r

        run.collect_sessions = collect

        def planned_protected(loc):
            """What the incomplete plan's branch keeps protected: the same expression as Runner._execute()."""
            return {p for p in lib.read_exclude() if Path(p).exists()}

        if args.mode == "release":
            s, ok = run.collect_sessions(cfg, log)
            runner.release(s, ok)
            doc = release_doc(runner, grabbed, sessions)
        else:
            runner._execute()
            doc = plan_doc(run, cfg, runner, grabbed, sessions, planned_protected)
    except Exception as e:  # noqa: BLE001 – Python's own end of the run: only that it ended this way counts
        log.exception(f"shadow: {e}")
        doc = {"outcome": "crash", "tape": TAPE_V, "detail": type(e).__name__}
    finally:
        tape.close()
        if lock is not None:
            lock.close()
        write_plan(args.plan, doc)
    return 0


def group_doc(g):
    return {"chain": g.chain, "category": g.category, "source": g.source, "series": g.series, "title": g.title, "size": g.size,
            "files": [str(f.rel) for f in g.files]}


def plan_doc(run, cfg, runner, grabbed, sessions, planned_protected):
    p = grabbed["planner"]
    files = grabbed["order"]
    loc = run.Locations(cfg, cfg["instances"][0]["_mappings"])
    incomplete = bool(p.errors or not sessions["ok"])
    # MEDIACACHE_NO_FILL (the joint run's first step): nothing is fetched, what lies on the array only counted as waiting
    planned = [] if run.NO_FILL else [str(f.rel) for f in files if not f.on_cache and str(f.rel) not in set(grabbed["dropped"])]
    kept = [f for f in files if f.on_cache]
    return {
        "outcome": "ok",
        "tape": TAPE_V,
        "mode": "run",
        "incomplete": incomplete,
        "errors": p.errors,
        "sessions": sessions,
        "persons": [{"key": u.key, "name": u.name, "uids": u.uids, "servers": u.servers, "budget": None if u.budget is None else f"{u.budget:.3f}",
                     "bytes": u.bytes, "movie_bytes": u.movie_bytes, "series_bytes": u.series_bytes, "exhausted": u.exhausted,
                     "selected": [group_doc(g) for g in u.selected], "skipped": [group_doc(g) for g in u.skipped]} for u in p.users.values()],
        "on_deck": [{"rel": str(f.rel), "size": f.size, "on_cache": f.on_cache, "user": f.user, "source": f.source, "title": f.title,
                     "ranks": dict(sorted(f.ranks.items())), "item": f.item} for f in files],
        "not_in_budget": p.not_in_budget(),
        "give_back_order": [str(f.rel) for f in run.give_back_order(kept)],
        "give_back": {"paths": sorted(runner.back_rank, key=runner.back_rank.get), "files": runner.given_back, "bytes": runner.given_back_bytes,
                      "held": runner.held, "keep": runner.keep, "items": runner.given_back_list},
        "cleanup": {"paths": grabbed["cleanup"], "bytes": runner.to_array},
        "protected": sorted(planned_protected(loc) if incomplete else grabbed["protected"]),
        "fill": {"rels": planned, "dropped": grabbed["dropped"], "bytes": runner.to_cache, "waiting": runner.waiting, "waiting_bytes": runner.waiting_bytes},
        "sim_delta": runner.sim_delta,
    }


def release_doc(runner, grabbed, sessions):
    """--release's decisions (Runner.release()): the cleanup over the whole list, what stays protected"""
    return {
        "outcome": "ok",
        "tape": TAPE_V,
        "mode": "release",
        "incomplete": not sessions["ok"],
        "sessions": sessions,
        "cleanup": {"paths": grabbed["cleanup"], "bytes": runner.to_array},
        "protected": sorted(grabbed["protected"]),
        "keep_themes": runner.keep_themes,
    }


def write_plan(path, doc):
    tmp = f"{path}.tmp"
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as fh:
        json.dump(clean(doc), fh, ensure_ascii=False, indent=0)
    os.replace(tmp, path)


if __name__ == "__main__":
    sys.exit(main())
