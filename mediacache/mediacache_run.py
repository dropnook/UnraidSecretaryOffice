#!/usr/bin/env python3
"""
MediaCache – holt «Weiterschauen»-Inhalte, die nächsten Folgen und Favoriten-Serien der Emby-/Jellyfin-Benutzer
vom Unraid-Array auf den Cache-Pool und räumt nicht mehr benötigte Dateien wieder aufs Array.

Ablauf pro Lauf:
  1. Sessions abfragen – was gerade läuft, wird nie angefasst
  2. On-Deck-Liste berechnen (Weiterschauen, «Als Nächstes» (NextUp), nächste Folgen in Serienreihenfolge, Favoriten)
       Zähl-Modus (Default): number_episodes Folgen pro Serie; pro Benutzer max_resume_movies angefangene Filme
       und max_resume_series angefangene Serien (je eine eigene «Weiterschauen»-Abfrage)
       Budget-Modus (cache_budget gesetzt, z.B. "2.5T"): das Budget wird fair auf die aktiven Benutzer verteilt
       (feste Budgets pro Benutzer möglich, ungenutzter Anteil fliesst an die anderen); pro Benutzer werden
       Filme und Serien nach Bytes im Verhältnis movie_share_percent gefüllt, Serien reihum eine Folge pro
       Runde – kleine Folgen ergeben viele, grosse wenige. Was nicht ins Budget passt, bleibt auf dem Array.
  3. Cleanup: Dateien aus der alten Exclude-Liste, die nicht mehr on deck sind -> Cache -> Array, per cleanup_tool:
       mover (Default): Unraid move-Binary wie das Original – setzt voraus, dass die Mover-Richtung des Shares
                        Cache -> Array ist (shareUseCache=yes); das Script prüft das vorher und meldet es
       rsync:           rsync /mnt/<pool>/<rel> -> /mnt/user0/<rel> (shfs wählt die Disk), Grössenvergleich, dann
                        Quelle löschen – unabhängig von der Mover-Richtung des Shares
  4. Befüllen: On-Deck-Dateien, die noch auf dem Array liegen -> auf den Cache. Werkzeug per fill_tool:
       rsync (Default): rsync -aAX --numeric-ids /mnt/user0/<rel> /mnt/<pool>/<rel> – wie das Original (plus
                        --info=progress2 für den Status, abschaltbar mit copy_progress=false);
                        Quelle erst nach Rückgabecode 0 und Grössenvergleich löschen. array_source = disk liest
                        stattdessen vom echten /mnt/diskN-Pfad (schneller, am FUSE vorbei) – optional
       mover:           Array-Pfade ans Unraid move-Binary pipen (wie der Stock-Mover bei «prefer»-Shares);
                        das Binary wählt den Pool aus der Share-Konfiguration – vorher mit einer Datei testen
     Der Freiplatz-Floor wird in beiden Fällen vorher für alle geplanten Kopien zusammen geprüft.
     rsync-Kopien optional parallel: parallel_per_disk pro Quell-Disk, parallel_total gesamt (Default 1/1 = sequentiell)
  5. Exclude-Liste neu schreiben (alle On-Deck-Dateien, die jetzt auf dem Cache liegen)

Aufruf:
  python3 mediacache_run.py                 Dry-Run: zeigt alle geplanten Aktionen, verändert nichts
  python3 mediacache_run.py --show-on-deck  Report pro Benutzer: Filme, dann Serien mit ihren Folgen, dann «Nicht im Budget»
      --user Benj,Kid   nur diese Benutzer anzeigen (Name oder ID); die Planung läuft immer für alle
      --compact         nur Einträge, keine einzelnen Dateien
  python3 mediacache_run.py --run           Scharf
  python3 mediacache_run.py --release       Alles zurück aufs Array (Unraid Secretary Office: Jack Streamer wird entlassen):
                                           ein Cleanup über die ganze Exclude-Liste auf demselben Weg wie oben (3.),
                                           nichts wird befüllt; was gerade läuft, bleibt auf dem Cache und in der Liste
  python3 mediacache_run.py --status        Live-Status des laufenden bzw. letzten Laufs (--watch [SEK] = laufend neu)

Umgebungsvariablen (überschreiben Config bzw. Defaults; CLI-Flags haben Vorrang vor MEDIACACHE_MODE):
  MEDIACACHE_MODE              dry | report | run | release
  MEDIACACHE_DIR               Arbeitsverzeichnis (Settings, Exclude-Liste, Lock, logs/); Default: Script-Verzeichnis
  MEDIACACHE_CONFIG            Pfad zur Settings-JSON
  MEDIACACHE_LOG_LEVEL         DEBUG | INFO | WARNING (Default INFO)
  MEDIACACHE_MIN_FREE_PERCENT  Mindest-Freiplatz auf dem Cache in Prozent (überschreibt min_free_percent)
  MEDIACACHE_GIVE_BACK_FREE    USO: "1" (das Office) – unter min_free_percent gibt der Lauf zurück, nach Rang, bis
                              es wieder reicht; sonst hört das Befüllen dort nur auf (wie bisher)
  MEDIACACHE_NO_FILL           USO: "1" (das Office, die gemeinsame Nacht mit Frau Moverelli) – der Lauf gibt nur frei
                              (Rückgabe + Cleanup) und holt nichts; was auf dem Array wartet, wird gezählt (waiting)
  MEDIACACHE_WATCHED_ONLY      USO: "1" (das Office, seit 2026-10-14: nur Frau Moverellis Grenze X) – zurück geht nur, was
                              geschaut ist (der Cleanup), nie nach Rang; MEDIACACHE_GIVE_BACK_FREE gilt dann nicht.
                              Was er hält und was wartet, steht trotzdem im Status (pool)
  MEDIACACHE_KEEP_BYTES        USO: der Boden dieser Rückgabe in Bytes (das Office: Jacks Hälfte) – was auf dem Cache
                              liegt, geht nie darunter zurück; leer: kein Boden
  MEDIACACHE_MOVER_DEBUG       0–3, Parameter -d für das move-Binary (überschreibt mover_debug_level)
  MEDIACACHE_RSYNC_ARGS        vollständige rsync-Optionen, Default "-aAX --numeric-ids" (überschreibt rsync_args)
  MEDIACACHE_FILL_TOOL         rsync | mover (überschreibt fill_tool)
  MEDIACACHE_CLEANUP_TOOL      mover | rsync (überschreibt cleanup_tool)
  MEDIACACHE_CACHE_BUDGET      z.B. "2.5T" (überschreibt cache_budget; "" = Zähl-Modus)
  MEDIACACHE_REPORT_USER       wie --user
  MEDIACACHE_RESULT            USO: JSON-Datei für das Ergebnis des Laufs (siehe mediacache_lib.py)
  MEDIACACHE_PROGRESS          JSON-Datei mit dem Fortschritt des scharfen Laufs (--run, --release), an jeder Dateigrenze
                              neu geschrieben: Phase (plan | back | fill | done), «Zurück aufs Array» geplant/erledigt,
                              pro Benutzer geplant/erledigt (Dateien, Bytes) mit Beginn/Ende, die Datei in Arbeit mit
                              Ziel und Grund (USO: active = jede Datei in Arbeit, parallele Kopien), die Warteschlange
                              des Befüllens (queue, pos: wer wann dran ist)
  MEDIACACHE_ZFS, MEDIACACHE_MOUNTS  USO: zfs und /proc/mounts für die Tests (Freiplatz auf ZFS: der ganze Pool)
  MEDIACACHE_STOP              Datei: sobald es sie gibt, hört der Lauf nach der aktuellen Datei auf (nie mitten in einer
                              Kopie; parallel: nach den Dateien in Arbeit, keine neue beginnt); was noch auf dem Cache liegt, bleibt in der Exclude-Liste geschützt; Ergebnis
                              «stopped», Exit-Code 3 (das Office: der Unraid-Mover startete, oder auf Wunsch)
  MEDIACACHE_PARALLEL_PER_DISK gleichzeitige rsync-Kopien pro Quell-Disk (überschreibt parallel_per_disk)
  MEDIACACHE_PARALLEL_TOTAL    gleichzeitige rsync-Kopien insgesamt, 0 = ohne Limit (überschreibt parallel_total)
  MEDIACACHE_STATUS_LOG_INTERVAL  Sekunden zwischen Fortschrittszeilen im Log, 0 = aus

Beispiel User Scripts (Unraid):  cd /mnt/user/system/scripts/mediacache && python3 mediacache_run.py --run
"""
import argparse
import collections
import contextlib
import os
import re
import sys
import threading
import time
import unicodedata
from pathlib import Path

STOP_FILE = os.environ.get("MEDIACACHE_STOP") or ""
NO_FILL = os.environ.get("MEDIACACHE_NO_FILL") == "1"     # USO: nur freigeben, nichts holen (das Office)
WATCHED_ONLY = os.environ.get("MEDIACACHE_WATCHED_ONLY") == "1"   # USO: zurück nur, was geschaut ist (das Office, nur X)


# USO «vanished» (2026-10-22, seen on a real server): another program mirroring into the share with rsync --delete
# (a User Script, through /mnt/user) removed rsync's temporary .<name>.XXXXXX in the target before its rename — code 23,
# «rename "…/.<name>.XXXXXX" -> "<name>": No such file or directory», the file not copied, its source still there
VANISHED_RE = re.compile(r'rename "[^"\n]*" -> "[^"\n]*": No such file or directory')
# its words in the log when it happened again on the second try (one line, never «rsync error» alone)
VANISHED_SAID = ("Ein anderes Programm hat die halb kopierte Datei gelöscht (z. B. ein Sync mit --delete auf diesen Share) "
                 "– die Quelle ist unversehrt, der nächste Lauf versucht es wieder: {rel}")


def vanished(rc, out):
    """USO «vanished»: rsync's temporary file was deleted in the target before its rename"""
    return rc == 23 and bool(VANISHED_RE.search(out))


def stop_requested():
    """MEDIACACHE_STOP: gibt es die Datei, hört der Lauf nach der aktuellen Datei auf."""
    return bool(STOP_FILE) and os.path.exists(STOP_FILE)

from mediacache_lib import (
    ConfigError, MediaServerApi, Locations, Status, acquire_lock, keep_bytes, pool_usage, format_status,
    collect_sessions, detect_mover_bin, ensure_share_root, free_percent_after, human, is_playing, load_config, mkdir_like,
    read_exclude, remove_empty_on_disk, remove_empty_parents, run_mover, run_rsync, setup_logging, share_mode_ok, share_mover_mode,
    summarize_mover_output, unraid_mover_running, write_exclude, write_progress, write_result, EXCLUDE_FILE,
    PROGRESS_FILE, __version__,
)


def group_key(rel):
    """Gruppierung für die Log-Ausgabe: Share/oberster Ordner (Serie bzw. Filmordner), sonst der Share."""
    parts = Path(rel).parts
    return "/".join(parts[:2]) if len(parts) > 2 else parts[0]


def log_grouped(label, rels_with_size):
    """Listet Dateien gruppiert: eine Kopfzeile pro Serie/Filmordner, darunter die Dateien eingerückt."""
    groups = {}
    for rel, size in rels_with_size:
        groups.setdefault(group_key(rel), []).append((rel, size))
    for key, items in groups.items():
        log.info(f"[{label}] {key}: {len(items)} Datei{'en' if len(items) != 1 else ''}, {human(sum(sz for _, sz in items))}")
        for rel, size in items:
            log.info(f"      {human(size):>10}  {rel}")

log = setup_logging("MediaCache", "mediacache.log")


class OnDeckFile:
    __slots__ = ("rel", "size", "on_cache", "reason", "user", "source", "title", "ranks", "item")

    def __init__(self, rel, size, on_cache, reason, user=None, source=None, title=None, ahead=0, item=None):
        self.rel, self.size, self.on_cache, self.reason = rel, size, on_cache, reason
        # für MEDIACACHE_PROGRESS: wessen Datei (UserPlan.key), warum (SOURCE_CODES) und was («Serie – S01E02 Titel»)
        self.user, self.source, self.title = user, source, title
        # USO (Phase 2, Rang): pro Benutzer, wie weit voraus (0 angefangen, 1 als Nächstes, n die n-te Folge voraus);
        # eine Datei, die mehrere vormerken, trägt alle; item: das Emby-Item (alle Dateien eines Items gehen zusammen)
        self.ranks = {user: ahead}
        self.item = item

    def ahead(self):
        """Wie weit voraus für den, der sie am ehesten schaut."""
        return min(self.ranks.values()) if self.ranks else 0


# Die Quellen als Codes für MEDIACACHE_PROGRESS (ein Programm übersetzt sie selbst)
SOURCE_CODES = {"Weiterschauen": "resume", "Als Nächstes": "next_up", "Nächste Folge": "next_episode", "Favorit": "favorite"}


class Progress:
    """MEDIACACHE_PROGRESS: nur im scharfen Lauf und nur, wenn gesetzt; an jeder Dateigrenze neu geschrieben (atomar).
    Für ein Programm, das den Lauf zeigt: die Phase, «Zurück aufs Array» und pro Benutzer geplant/erledigt, die Datei in
    Arbeit mit ihrem Ziel (dort liegt rsyncs temporäre Datei .<name>.XXXXXX – deren Grösse ist der Stand in der Datei).
    Geplant ist, was der Lauf vorhat; was er unterwegs auslässt (läuft gerade, Freiplatz, Fehler), fällt aus «geplant».
    «Zurück aufs Array» und jeder Benutzer: started (Beginn der ersten Datei) und ended (Ende der letzten).
    Das Befüllen kopiert eine Datei nach der anderen, alle Benutzer gemischt, reihum nach Rang (USO, Planner.fill_order): queue = [[Index in users, Bytes],
    …] in genau dieser Reihenfolge (nur, was nicht schon auf dem Cache liegt), pos = wie viele davon erledigt sind
    (kopiert oder ausgelassen) – so weiss ein Programm, wann jeder Benutzer fertig ist. Mehr als QUEUE_MAX: keine queue.
    USO (2026-10-29, parallele Kopien): `active` = jede Datei in Arbeit (dieselben Felder wie `current`, dazu `q`, ihre
    Stelle in der queue), in der Reihenfolge ihres Beginns; `current` bleibt die erste davon (ältere Leser). Eine Datei
    der queue, die erledigt ist, während eine frühere noch kopiert, wird [-1, 0] (niemandes, nichts mehr) – pos zählt nur
    die erledigten von vorn. Thread-sicher: die Kopien laufen parallel.
    USO (2026-10-29, eine Datei mehrerer Benutzer): sie zählt bei jedem, der sie vormerkt (geplant, erledigt; owners()),
    kopiert wird sie einmal – `fill` zählt das Befüllen als Ganzes (jede Datei einmal, wie `back`); die queue nennt sie
    beim Ersten, `shared` = [[Stelle in der queue, [Index der anderen Benutzer, …]], …]; in `active` `users` = alle."""

    QUEUE_MAX = 5000
    RECENT_MAX = 50

    def __init__(self, mode, on):
        self.on = on
        now = int(time.time())
        self.data = {"v": 1, "mode": mode, "phase": "plan", "started": now, "updated": now,
                     "back": None, "fill": None, "users": [], "current": None, "active": [], "queue": None, "shared": [], "pos": 0,
                     "recent": []}
        self.users = {}   # UserPlan.key -> Eintrag in data["users"]
        self.qidx = {}    # id(OnDeckFile) -> ihre Stelle in der queue
        self.passed = set()   # USO: Stellen der queue, die erledigt sind
        self.active = {}  # USO: Schlüssel (id(OnDeckFile), Rückweg: ("back", rel)) -> Eintrag in data["active"]
        self.lock = threading.RLock()
        self.write()

    def write(self):
        if self.on:
            with self.lock:
                self.data["active"] = list(self.active.values())
                self.data["current"] = self.data["active"][0] if self.data["active"] else None
                self.data["updated"] = int(time.time())
                write_progress(self.data)

    def phase(self, phase):
        with self.lock:
            b = self.data["back"]
            if self.data["phase"] == "back" and phase != "back" and b is not None and b.get("started") and not b.get("ended"):
                b["ended"] = int(time.time())
            self.data["phase"] = phase
            self.active.clear()
            self.write()

    def plan_back(self, files, nbytes):
        self.data["back"] = {"files": files, "bytes": nbytes, "done_files": 0, "done_bytes": 0, "started": None, "ended": None}

    def plan_fill(self, planner, files):
        """Pro Benutzer, was noch vom Array auf den Cache kommt – in der Reihenfolge der Planung; dazu die queue in der
        Reihenfolge, in der fill() kopiert."""
        todo = {}
        once = [0, 0]
        for f in files:
            if not f.on_cache:
                once[0] += 1
                once[1] += f.size
                for k in owners(f):
                    t = todo.setdefault(k, [0, 0])
                    t[0] += 1
                    t[1] += f.size
        self.data["fill"] = {"files": once[0], "bytes": once[1], "done_files": 0, "done_bytes": 0, "started": None, "ended": None}
        for u in planner.users.values():
            if u.key in todo:
                entry = {"key": u.key, "name": u.name, "server": u.server, "files": todo[u.key][0], "bytes": todo[u.key][1],
                         "done_files": 0, "done_bytes": 0, "started": None, "ended": None}
                self.users[u.key] = entry
                self.data["users"].append(entry)
        index = {e["key"]: i for i, e in enumerate(self.data["users"])}
        order = [f for f in files if not f.on_cache]      # wie fill(): reihum über die Benutzer (Planner.fill_order)
        if len(order) <= self.QUEUE_MAX:
            self.data["queue"] = [[index.get(f.user, -1), int(f.size)] for f in order]
            self.qidx = {id(f): i for i, f in enumerate(order)}
            self.data["shared"] = [[i, [index[k] for k in owners(f)[1:] if k in index]] for i, f in enumerate(order) if len(owners(f)) > 1]

    def _entries(self, f):
        """USO: die Einträge der Benutzer, die die Datei vormerken (der Erste zuerst), und das Befüllen als Ganzes."""
        return [self.users[k] for k in owners(f) if k in self.users] + ([self.data["fill"]] if self.data["fill"] is not None else [])

    def _passed(self, f, entries):
        """Eine Datei der queue ist erledigt (kopiert oder ausgelassen); ihre Benutzer ohne Rest: ended."""
        i = self.qidx.get(id(f))
        q = self.data["queue"]
        if i is not None and q is not None and i not in self.passed:
            self.passed.add(i)
            q[i] = [-1, 0]
            if self.data["shared"]:
                self.data["shared"] = [x for x in self.data["shared"] if x[0] != i]
            while self.data["pos"] in self.passed:
                self.data["pos"] += 1
        now = int(time.time())
        for entry in entries:
            if entry["done_files"] >= entry["files"] and entry.get("started") and not entry.get("ended"):
                entry["ended"] = now

    def start(self, phase, rel, size, target, f=None, why=None):
        """Eine Datei beginnt (vor dem rsync): wer, was, wohin; why (USO, Rückgabe übers Stück): {"title", "ahead", "who"}."""
        with self.lock:
            now = int(time.time())
            entries = self._entries(f) if f is not None else [self.data["back"]] if phase == "back" and self.data["back"] is not None else []
            for entry in entries:
                if not entry.get("started"):
                    entry["started"] = now
            cur = {"phase": phase, "rel": str(rel), "size": int(size), "target": str(target),
                   "user": f.user if f is not None else None, "source": f.source if f is not None else None,
                   "title": f.title if f is not None else None, "since": now}
            if f is not None:
                cur["users"] = owners(f)
            if f is not None and id(f) in self.qidx:
                cur["q"] = self.qidx[id(f)]
            if why is not None:
                cur["why"] = why
            self.active[self._key(f, rel)] = cur
            self.write()

    @staticmethod
    def _key(f, rel):
        return id(f) if f is not None else ("back", str(rel))

    def _take(self, f, rel):
        """USO: der Eintrag der Datei in Arbeit – ohne rel auf dem Rückweg der erste dort (eine Datei nach der anderen)."""
        if f is not None or rel is not None:
            return self.active.pop(self._key(f, rel), None)
        for k, cur in list(self.active.items()):
            if cur.get("phase") == "back":
                return self.active.pop(k)
        return None

    def done(self, size, f=None, rel=None):
        """Eine Datei ist angekommen (aufs Array zurück, oder f: auf den Cache)."""
        with self.lock:
            entries = self._entries(f) if f is not None else [self.data["back"]] if self.data["back"] is not None else []
            for entry in entries:
                entry["done_files"] += 1
                entry["done_bytes"] += int(size)
            cur = self._take(f, rel)
            if cur is not None:   # USO: die letzten RECENT_MAX erledigten Dateien (das Office: das Bild der Nacht)
                back = cur.get("phase") == "back"
                self.data["recent"] = (self.data["recent"] + [{"name": cur["rel"], "bytes": int(size),
                                       "from": "cache" if back else "array", "to": "array" if back else "cache"}])[-self.RECENT_MAX:]
            if f is not None:
                self._passed(f, entries)
            self.write()

    def drop(self, f, write=True):
        """Eine geplante Datei fällt weg (übersprungen oder fehlgeschlagen): nicht mehr geplant."""
        with self.lock:
            entries = self._entries(f)
            for entry in entries:
                entry["files"] = max(entry["done_files"], entry["files"] - 1)
                entry["bytes"] = max(entry["done_bytes"], entry["bytes"] - f.size)
            self._passed(f, entries)
            self.active.pop(self._key(f, None), None)
            if write:
                self.write()

    def drop_back(self, size, rel=None):
        """Eine Datei des Rückwegs bleibt auf dem Cache (Duplikat, Fehler, Anhalten): nicht mehr geplant."""
        with self.lock:
            b = self.data["back"]
            if b is not None:
                b["files"] = max(b["done_files"], b["files"] - 1)
                b["bytes"] = max(b["done_bytes"], b["bytes"] - int(size))
            self._take(None, rel)


def owners(f):
    """USO (2026-10-29): wer eine Datei vormerkt – der Erste (f.user) zuerst, dann die anderen nach ihrem Rang (eine Datei,
    die mehrere vormerken, zählt bei jedem; Planner.run() gibt ihr alle Ränge)."""
    rest = sorted((k for k in f.ranks if k != f.user), key=lambda k: (f.ranks[k], str(k)))
    return ([f.user] if f.user is not None else []) + rest


# --------------------------------------------------------------------------- Planung
class Group:
    """Atomare Planungseinheit: alle Dateien eines Items (Film + Untertitel + Extras, Folge + srt …)."""
    __slots__ = ("files", "size", "category", "chain", "source", "series", "title", "reason")

    def __init__(self, files, category, chain, source, series, title, reason):
        self.files, self.category, self.chain = files, category, chain
        self.source, self.series, self.title, self.reason = source, series, title, reason
        self.size = sum(f.size for f in files)

    def location(self):
        on = sum(1 for f in self.files if f.on_cache)
        return "CACHE" if on == len(self.files) else "ARRAY" if on == 0 else "TEILS"


SOURCE_ORDER = ("Weiterschauen", "Als Nächstes", "Nächste Folge", "Favorit")


def ep_label(ep):
    """«S02E03 Titel» – oder nur der Titel, wenn der Server keine Nummern liefert."""
    s, e = ep.get("ParentIndexNumber"), ep.get("IndexNumber")
    name = ep.get("Name", "?")
    return f"S{s:02d}E{e:02d} {name}" if s is not None and e is not None else name


def group_key_files(g):
    """USO: ein Eintrag nach seinen Dateien – derselbe Film/dieselbe Folge, von zwei Servern gemeldet, ist einer."""
    return tuple(sorted(str(f.rel) for f in g.files))


def person_key(name):
    """USO: eine Person über mehrere Server – derselbe Name ohne Gross/Klein, Leerraum und Akzente («Anna», «anna»,
    «Ánna »); andere Namen bleiben andere Personen (kein Raten darüber hinaus)."""
    n = unicodedata.normalize("NFKD", str(name or ""))
    return "p:" + "".join(c for c in n if not unicodedata.combining(c) and not c.isspace()).casefold()


class UserPlan:
    """Kandidaten und Auswahl einer Person (USO: über alle Server mit ihrem Namen zusammengefasst)."""

    def __init__(self, key, name, uid="", server=""):
        self.key, self.name, self.uid, self.server = key, name, uid, server
        self.uids = [uid] if uid else []      # USO: ihre Benutzer-IDs auf allen Servern
        self.servers = [server] if server else []
        self.chains = []                      # USO: Serien-Ketten aller Server (reihum neu verteilt)
        self.seen = set()                     # USO: schon vorgemerkte Einträge (Dateien) – ein Eintrag auf zwei Servern zählt einmal
        self.movies = []       # Group, nach Aktualität (Weiterschauen)
        self.series = []       # Group, reihum über alle laufenden Serien und Favoriten
        self.selected = []
        self.skipped = []
        self.bytes = self.movie_bytes = self.series_bytes = 0
        self.budget = None
        self.exhausted = True

    def has_candidates(self):
        return bool(self.movies or self.series)


class Planner:
    def __init__(self, cfg):
        self.cfg = cfg
        self.files = {}      # rel (str) -> OnDeckFile (Ergebnis)
        self.users = {}      # key -> UserPlan
        self.planning = None  # UserPlan.key, dessen Kandidaten gerade gesucht werden (OnDeckFile.user)
        self.errors = 0      # API-Fehler: Planung unvollständig -> kein Cleanup in diesem Lauf
        self.budget_mode = int(cfg["cache_budget_bytes"]) > 0

    # ------------------------------------------------------------------ Dateien eines Items
    def files_for_item(self, loc, item):
        """Ermittelt alle Dateien (relativ zur Share-Ebene), die zu einem Server-Item gehören."""
        raw = item.get("Path") or ""
        if not raw or item.get("LocationType") == "Virtual":
            return []
        rel = loc.rel_from_docker(raw)
        if rel is None:
            if loc.skipped(raw):
                log.debug(f"Ordner absichtlich nicht gecacht: {raw}")
            else:
                log.warning(f"Kein Path-Mapping für {raw} – Item übersprungen (Mapping in der Config prüfen)")
            return []
        exists_dir = loc.on_array(rel).is_dir() or loc.on_cache(rel).is_dir()
        exists_file = loc.on_array(rel).is_file() or loc.on_cache(rel).is_file()
        if not exists_dir and not exists_file:
            log.warning(f"Datei nicht gefunden: {rel} (Mapping oder array_path/cache_path prüfen)")
            return []
        if exists_dir:
            # Ordner-Item (DVD/BluRay-Struktur): den Ordner selbst mitnehmen, nie den Elternordner
            return loc.list_files(rel, recursive=True)
        parent = rel.parent
        if item.get("Type") == "Movie" and self.cfg["movie_mode"] == "folder" and not loc.is_library_root(parent):
            return loc.list_files(parent, recursive=True)
        # Episoden, Filme in flacher Ablage, movie_mode=file: nur Dateien mit gleichem Basisnamen (mkv, srt, nfo …)
        stem = rel.stem
        files = [f for f in loc.list_files(parent, recursive=False) if f.name.startswith(stem)]
        # Jellyfin legt Trickplay-Vorschaubilder (bei «neben den Medien speichern») in <Basisname>.trickplay/ ab
        return files + loc.list_files(parent / f"{stem}.trickplay", recursive=True)

    def make_group(self, loc, item, category, chain, source, title, uname, series=None, ahead=0):
        """ahead (USO, Phase 2): wie weit voraus – 0 angefangen (Weiterschauen), 1 als Nächstes, n die n-te Folge voraus."""
        reason = f"{uname}: {source} «{series + ' – ' if series else ''}{title}»"
        what = f"{series} – {title}" if series else title
        files = []
        for rel in self.files_for_item(loc, item):
            cache_p = loc.on_cache(rel)
            on_cache = cache_p.exists()
            src = cache_p if on_cache else loc.on_array(rel)
            try:
                size = src.stat().st_size
            except OSError as e:
                log.warning(f"Datei nicht lesbar, übersprungen: {src} ({e})")
                continue
            files.append(OnDeckFile(rel, size, on_cache, reason, self.planning, SOURCE_CODES.get(source, "other"), what,
                                    ahead, f"{item.get('Id')}|{item.get('Path')}"))
        return Group(files, category, chain, source, series, title, reason) if files else None

    # ------------------------------------------------------------------ Server-Abfragen
    @staticmethod
    def ep_key(ep):
        return (ep.get("ParentIndexNumber") if ep.get("ParentIndexNumber") is not None else -1,
                ep.get("IndexNumber") if ep.get("IndexNumber") is not None else -1)

    def series_episodes(self, api, uid, series_id):
        eps = api.user_items(uid, ParentId=series_id, Recursive="true", IncludeItemTypes="Episode",
                        SortBy="ParentIndexNumber,IndexNumber", SortOrder="Ascending",
                        Fields="Path,ParentIndexNumber,IndexNumber,LocationType")
        eps = [e for e in eps if e.get("Path") and e.get("LocationType") != "Virtual"]
        return sorted(eps, key=self.ep_key)

    def episode_cap(self):
        """Wie viele Folgen pro Serie höchstens: Zähl-Modus number_episodes (0 = keine),
        Budget-Modus max_episodes_per_series (0 = unbegrenzt, das Budget entscheidet)."""
        if self.budget_mode:
            n = int(self.cfg["max_episodes_per_series"])
            return n if n > 0 else None
        n = int(self.cfg["number_episodes"])
        return n if n > 0 else 0

    def next_episodes_after(self, api, uid, current):
        """Die Folgen nach der aktuellen – in Staffel-/Folgenreihenfolge, unabhängig vom Gesehen-Status."""
        cap = self.episode_cap()
        if cap == 0 or not current.get("SeriesId"):
            return []
        cur = self.ep_key(current)
        eps = self.series_episodes(api, uid, current["SeriesId"])
        after = [e for e in eps if self.ep_key(e) > cur and e.get("Id") != current.get("Id")]
        return after[:cap] if cap else after

    def next_unplayed(self, api, uid, series_id):
        """Für Favoriten ohne Resume-Eintrag: ab der ersten ungesehenen Folge."""
        cap = self.episode_cap()
        if cap == 0:
            return []
        eps = self.series_episodes(api, uid, series_id)
        unplayed = [e for e in eps if not (e.get("UserData") or {}).get("Played")]
        return unplayed[:cap] if cap else unplayed

    # ------------------------------------------------------------------ Kandidaten pro Benutzer
    def plan_user(self, api, loc, name, uid, uname):
        # USO: dieselbe Person auf mehreren Servern (gleicher Name) ist eine – ein Anteil am Budget, ihre Einträge zusammen
        key = person_key(uname)
        up = self.users.get(key)
        if up is None:
            up = UserPlan(key, uname, uid, name)
        else:
            up.uids.append(uid)
            if name not in up.servers:
                up.servers.append(name)
            up.server = ", ".join(up.servers)
        self.planning = up.key
        # Filme und Serien je mit eigener Grenze: zwei «Weiterschauen»-Abfragen
        resume = []
        try:
            # USO: getrennte Grenzen für angefangene Filme und Serien (max_resume_movies / max_resume_series)
            for kind, limit in (("Movie", int(self.cfg["max_resume_movies"])), ("Episode", int(self.cfg["max_resume_series"]))):
                if limit > 0:
                    resume += api.resume_items(uid, MediaTypes="Video", IncludeItemTypes=kind, Limit=limit,
                                               Fields="Path,SeriesId,ParentIndexNumber,IndexNumber,LocationType")
        except RuntimeError as e:
            self.errors += 1; log.error(f"[{name}] Resume-Liste von {uname} nicht abrufbar: {e}")
            return
        chains = []  # Listen von Groups je Serie, in Aktualitätsreihenfolge
        seen_series = set()
        for item in resume:
            if item.get("Type") == "Movie":
                g = self.make_group(loc, item, "movie", f"movie:{item.get('Id')}", "Weiterschauen", item.get("Name", "?"), uname)
                if g and group_key_files(g) not in up.seen:   # USO: derselbe Film auf zwei Servern einmal
                    up.seen.add(group_key_files(g))
                    up.movies.append(g)
                continue
            if item.get("Type") != "Episode":
                continue
            sname = item.get("SeriesName", "?")
            sid = item.get("SeriesId") or f"ep:{item.get('Id')}"
            if sid in seen_series:
                continue
            seen_series.add(sid)
            chain = []
            g = self.make_group(loc, item, "series", f"series:{sid}", "Weiterschauen", ep_label(item), uname, sname)
            if g:
                chain.append(g)
            try:
                for i, ep in enumerate(self.next_episodes_after(api, uid, item), 1):
                    g = self.make_group(loc, ep, "series", f"series:{sid}", "Nächste Folge", ep_label(ep), uname, sname, i)
                    if g:
                        chain.append(g)
            except RuntimeError as e:
                self.errors += 1; log.error(f"[{name}] Folgen von «{sname}» nicht abrufbar: {e}")
            if chain:
                chains.append(chain)

        # «Als Nächstes» (NextUp): Serien, bei denen die letzte Folge fertig geschaut ist und die nächste
        # noch nicht läuft – ohne diese Quelle fiele die Serie zwischen zwei Folgen aus dem Cache
        if self.cfg["use_next_up"]:
            try:
                nextup = api.next_up(uid, Limit=int(self.cfg["max_resume_series"]),
                                   Fields="Path,SeriesId,ParentIndexNumber,IndexNumber,LocationType")
            except RuntimeError as e:
                self.errors += 1; log.error(f"[{name}] NextUp-Liste von {uname} nicht abrufbar: {e}")
                nextup = []
            for item in nextup:
                if item.get("Type") != "Episode":
                    continue
                sname = item.get("SeriesName", "?")
                sid = item.get("SeriesId") or f"ep:{item.get('Id')}"
                if sid in seen_series:
                    continue
                seen_series.add(sid)
                chain = []
                g = self.make_group(loc, item, "series", f"series:{sid}", "Als Nächstes", ep_label(item), uname, sname, 1)
                if g:
                    chain.append(g)
                try:
                    for i, ep in enumerate(self.next_episodes_after(api, uid, item), 2):
                        g = self.make_group(loc, ep, "series", f"series:{sid}", "Nächste Folge", ep_label(ep), uname, sname, i)
                        if g:
                            chain.append(g)
                except RuntimeError as e:
                    self.errors += 1; log.error(f"[{name}] Folgen von «{sname}» nicht abrufbar: {e}")
                if chain:
                    chains.append(chain)

        max_fav = int(self.cfg["max_favorite_series"])
        if max_fav > 0:
            try:
                favs = api.user_items(uid, Recursive="true", IncludeItemTypes="Series",
                                 Filters="IsFavorite", Limit=max_fav, Fields="Path")
            except RuntimeError as e:
                self.errors += 1; log.error(f"[{name}] Favoriten von {uname} nicht abrufbar: {e}")
                favs = []
            for series in favs:
                sid = series.get("Id")
                if sid in seen_series:
                    continue
                seen_series.add(sid)
                chain = []
                try:
                    for i, ep in enumerate(self.next_unplayed(api, uid, sid), 1):
                        g = self.make_group(loc, ep, "series", f"series:{sid}", "Favorit", ep_label(ep), uname, series.get("Name", "?"), i)
                        if g:
                            chain.append(g)
                except RuntimeError as e:
                    self.errors += 1; log.error(f"[{name}] Folgen von Favorit «{series.get('Name', '?')}» nicht abrufbar: {e}")
                if chain:
                    chains.append(chain)

        # Reihum über alle Serien: jede Serie eine Folge pro Runde – kleine Folgen ergeben so
        # automatisch mehr Stück im Budget als grosse, keine Serie kann alles belegen
        # USO: über die Serien aller Server der Person; eine Folge, die zwei Server vormerken, einmal
        up.chains += chains
        up.series, seen = [], {group_key_files(g) for g in up.movies}
        pos = 0
        while any(pos < len(c) for c in up.chains):
            for c in up.chains:
                if pos < len(c) and group_key_files(c[pos]) not in seen:
                    seen.add(group_key_files(c[pos]))
                    up.series.append(c[pos])
            pos += 1
        self.users[up.key] = up

    def plan_instance(self, inst, uids):
        api = MediaServerApi.for_instance(inst, self.cfg["api_timeout"])
        loc = Locations(self.cfg, inst["_mappings"], inst["_skip"])
        name = inst["servername"]
        if not uids:
            try:
                users = api.users()
            except RuntimeError as e:
                self.errors += 1; log.error(f"[{name}] Benutzerliste nicht abrufbar: {e}")
                return
            if getattr(api, "users_incomplete", False) or not users:
                # USO: no users (or Plex's shared ones missing) is never «nobody watches anything» – no cleanup this run
                self.errors += 1; log.error(f"[{name}] Benutzerliste unvollständig – Cleanup wird übersprungen")
            uids = [(u["Id"], u.get("Name", u["Id"])) for u in users]
        for uid, uname in uids:
            self.plan_user(api, loc, name, uid, uname)

    # ------------------------------------------------------------------ Auswahl
    @staticmethod
    def select(up, budget, movie_share):
        """Füllt das Budget eines Benutzers: Filme und Serien im Verhältnis movie_share nach Bytes (weich –
        ist eine Kategorie leer, bekommt die andere den Rest). Passt ein Eintrag nicht mehr, wird seine
        Kette (die Serie) gestoppt, damit nie Folge 5 ohne Folge 4 gecacht wird."""
        queues = {"movie": up.movies, "series": up.series}
        idx = {"movie": 0, "series": 0}
        used = {"movie": 0, "series": 0}
        target = {"movie": budget * movie_share, "series": budget * (1.0 - movie_share)}
        stopped = set()
        up.selected, up.skipped, up.bytes = [], [], 0
        # USO (2026-10-16): the share holds while the other kind still has something — a big film that would overrun the
        # films' part waits (its kind is «full» for now) instead of taking the whole budget because it came first; once the
        # other kind is done or full too, what is left of the budget is filled as before (soft: an empty kind gives its room).
        full, relaxed = set(), False

        def fill_fraction(cat):
            return (used[cat] / target[cat]) if target[cat] > 0 else float("inf")

        while True:
            avail = [c for c in ("movie", "series") if idx[c] < len(queues[c]) and c not in full]
            if not avail:
                if not full:
                    break
                full.clear()
                relaxed = True
                continue
            cat = min(avail, key=fill_fraction)
            g = queues[cat][idx[cat]]
            other = "series" if cat == "movie" else "movie"
            if (not relaxed and g.chain not in stopped and used[cat] + g.size > target[cat]
                    and idx[other] < len(queues[other])):
                full.add(cat)
                continue
            idx[cat] += 1
            if g.chain in stopped:
                up.skipped.append(g)
                continue
            if up.bytes + g.size > budget:
                stopped.add(g.chain)
                up.skipped.append(g)
                continue
            up.selected.append(g)
            up.bytes += g.size
            used[cat] += g.size
        up.movie_bytes, up.series_bytes = used["movie"], used["series"]
        up.exhausted = not up.skipped
        up.budget = budget

    def apply_budget(self):
        """Verteilt cache_budget fair: feste Benutzer-Budgets zuerst, der Rest gleichmässig auf alle
        aktiven Benutzer; was ein Benutzer nicht braucht, fliesst an die anderen (Water-Filling)."""
        total = int(self.cfg["cache_budget_bytes"])
        share = float(self.cfg["movie_share_percent"]) / 100.0
        user_budgets = self.cfg["user_budgets"]
        active = [u for u in self.users.values() if u.has_candidates()]
        # USO: das Budget einer Person – unter einer ihrer IDs (auf irgendeinem Server) gespeichert
        explicit = {u.key: next(user_budgets[i] for i in u.uids if i in user_budgets) for u in active if any(i in user_budgets for i in u.uids)}
        pool = total - sum(explicit.values())
        if pool < 0:
            log.warning(f"Feste Benutzer-Budgets ({human(sum(explicit.values()))}) übersteigen cache_budget ({human(total)})")
            pool = 0
        flexible = [u for u in active if u.key not in explicit]
        fixed = {}
        cap = 0.0
        for _ in range(len(flexible) + 1):
            open_users = [u for u in flexible if u.key not in fixed]
            if not open_users:
                break
            cap = (pool - sum(fixed.values())) / len(open_users)
            changed = False
            for u in open_users:
                self.select(u, cap, share)
                if u.exhausted and u.bytes < cap:
                    fixed[u.key] = u.bytes
                    changed = True
            if not changed:
                break
        for u in flexible:
            if u.key not in fixed:
                self.select(u, cap, share)
        for u in active:
            if u.key in explicit:
                self.select(u, float(explicit[u.key]), share)
        for u in active:
            skipped = sum(g.size for g in u.skipped)
            log.info(f"Budget {u.name}: {human(u.bytes)} von {human(u.budget)} "
                     f"(Filme {human(u.movie_bytes)}, Serien {human(u.series_bytes)})"
                     + (f" – nicht im Budget: {len(u.skipped)} Einträge, {human(skipped)}" if u.skipped else ""))

    def run(self):
        wanted = self.cfg["valid_users"]
        for inst in self.cfg["instances"]:
            uids = []
            if wanted:
                # Namen für die Log-Ausgabe auflösen; unbekannte IDs (z.B. andere Instanz) still überspringen
                try:
                    api = MediaServerApi.for_instance(inst, self.cfg["api_timeout"])
                    known = {u["Id"]: u.get("Name", u["Id"]) for u in api.users()}
                except RuntimeError as e:
                    self.errors += 1; log.error(f"[{inst['servername']}] nicht erreichbar: {e}")
                    continue
                if getattr(api, "users_incomplete", False):
                    # USO: a chosen user may be one plex.tv didn't give – never «nobody of them is here» (the cleanup
                    # would give back what they hold)
                    self.errors += 1; log.error(f"[{inst['servername']}] Benutzerliste unvollständig – Cleanup wird übersprungen")
                uids = [(u, known[u]) for u in wanted if u in known]
                if not uids:
                    log.warning(f"[{inst['servername']}] keiner der konfigurierten Benutzer existiert auf dieser Instanz")
                    continue
            self.plan_instance(inst, uids)

        if self.budget_mode:
            self.apply_budget()
        else:
            for u in self.users.values():
                u.selected = u.movies + u.series
        for u in self.users.values():
            for g in u.selected:
                for f in g.files:
                    first = self.files.setdefault(str(f.rel), f)
                    if first is not f:      # USO: eine Datei, die mehrere vormerken – jeder mit seinem Rang
                        for k, a in f.ranks.items():
                            first.ranks[k] = min(a, first.ranks.get(k, a))
        if self.budget_mode:
            total = sum(f.size for f in self.files.values())
            log.info(f"Budget gesamt: {human(total)} von {human(int(self.cfg['cache_budget_bytes']))} "
                     f"für {sum(1 for u in self.users.values() if u.has_candidates())} aktive Benutzer")
        return self.fill_order()

    def not_in_budget(self):
        """USO (2026-10-16): what waits beyond the budget — each entry once (several persons may want it), none another
        person's budget takes along; films and episodes apart. None outside the budget mode. USO (2026-10-29): `persons`
        the same per person — an entry two persons want counts at each of them."""
        if not self.budget_mode:
            return None
        seen, out, persons = set(), {"items": 0, "films": 0, "episodes": 0, "bytes": 0}, {}
        for u in self.users.values():
            mine = set()
            for g in u.skipped:
                key = tuple(sorted(str(f.rel) for f in g.files))
                if not key or key in mine or any(k in self.files for k in key):
                    continue
                mine.add(key)
                kind = "films" if g.category == "movie" else "episodes"
                pe = persons.setdefault(u.key, {"name": u.name, "items": 0, "films": 0, "episodes": 0, "bytes": 0})
                pe["items"] += 1
                pe[kind] += 1
                pe["bytes"] += g.size
                if key in seen:
                    continue
                seen.add(key)
                out["items"] += 1
                out[kind] += 1
                out["bytes"] += g.size
        out["persons"] = list(persons.values())
        return out

    def fill_order(self):
        """USO (Phase 2): die Reihenfolge des Befüllens – reihum über die Benutzer nach ihrem nächsten Eintrag
        (des ersten Nächstes, des zweiten Nächstes, …, dann jedermanns Zweites), damit bei knappem Platz jeder etwas
        bekommt statt die ersten in der Pfadliste alles. Pro Benutzer nach Rang (angefangen, als Nächstes, …), bei
        gleichem Rang in der Reihenfolge seiner Planung. Ein Eintrag, den mehrere vormerken, zählt einmal – an der
        frühesten Stelle. Die Dateien eines Eintrags bleiben zusammen (nach Pfad)."""
        lists = []
        for u in self.users.values():
            groups = [(min(f.ranks.get(u.key, 0) for f in g.files), i, g) for i, g in enumerate(u.selected) if g.files]
            lists.append([g for _, _, g in sorted(groups, key=lambda t: (t[0], t[1]))])
        order, placed = [], set()
        for r in range(max((len(l) for l in lists), default=0)):
            for l in lists:
                if r < len(l):
                    for f in sorted(l[r].files, key=lambda x: str(x.rel)):
                        canon = self.files.get(str(f.rel))
                        if canon is not None and id(canon) not in placed:
                            placed.add(id(canon))
                            order.append(canon)
        order += [f for f in self.files.values() if id(f) not in placed]     # nichts geht verloren
        return order

    def names(self, f):
        """Wer die Datei vormerkt, der am ehesten Schauende zuerst: «Mutter, Vater»."""
        keys = sorted(f.ranks, key=lambda k: (f.ranks[k], self.users[k].name.lower() if k in self.users else str(k)))
        return ", ".join(self.users[k].name if k in self.users else str(k) for k in keys)


def ahead_words(ahead, who):
    """USO: der Rang in Worten fürs Log – «angefangen, Vater», «als Nächstes, Vater», «Folge 10 voraus, Mutter»."""
    what = "angefangen" if ahead <= 0 else "als Nächstes" if ahead == 1 else f"Folge {ahead} voraus"
    return f"{what}, {who}" if who else what


def give_back_order(kept, prepared_of=None):
    """USO (Phase 2): in welcher Reihenfolge Dateien zurück aufs Array gehen, wenn Jack über seiner Grenze liegt –
    egal welche (ein Stück in Bytes, ein Freiplatz in Prozent): das am weitesten voraus Liegende zuerst (die 10. nächste
    Folge vor der nächsten; was jemand angefangen hat, ganz zuletzt); bei gleichem Rang zuerst vom Benutzer, der gerade
    am meisten auf dem Cache hat (nach jeder Rückgabe neu gezählt); ein Eintrag, den mehrere vormerken, gilt mit dem
    nächsten Rang und zählt für jeden. Die Dateien eines Emby-Items gehen zusammen. Ergibt die Dateien in Reihenfolge."""
    items = {}
    for f in kept:
        items.setdefault(f.item if f.item is not None else str(f.rel), []).append(f)
    prepared = {}
    for f in kept:
        for k in f.ranks:
            prepared[k] = prepared.get(k, 0) + f.size
    by_ahead = {}
    for key, fs in items.items():
        ranks = {}
        for f in fs:
            for k, a in f.ranks.items():
                ranks[k] = min(a, ranks.get(k, a))
        by_ahead.setdefault(min(ranks.values()) if ranks else 0, []).append((key, fs, ranks))
    out = []
    for ahead in sorted(by_ahead, reverse=True):
        pending = sorted(by_ahead[ahead], key=lambda t: max(str(f.rel) for f in t[1]), reverse=True)
        while pending:
            # wer hat am meisten? von dessen Einträgen (auf diesem Rang) der nach Namen letzte zuerst
            best = max(range(len(pending)), key=lambda i: (max((prepared.get(k, 0) for k, a in pending[i][2].items() if a == ahead), default=0), -i))
            _, fs, _ = pending.pop(best)
            for f in sorted(fs, key=lambda x: str(x.rel), reverse=True):
                out.append(f)
                for k in f.ranks:
                    prepared[k] = prepared.get(k, 0) - f.size
    return out


# --------------------------------------------------------------------------- Ausführung
class Runner:
    def __init__(self, cfg, mode, user_filter=None, show_files=True):
        self.cfg = cfg
        self.mode = mode  # dry | report | run | release
        self.user_filter = user_filter
        self.show_files = show_files
        self.run_mode = mode in ("run", "release")
        self.to_cache = self.to_array = 0
        self.copied_bytes = self.moved_back_bytes = 0
        self.fill_planned = self.cleanup_planned = 0
        self.sim_delta = 0  # Dry-Run: Bytes, die der Cleanup freigäbe, minus Bytes geplanter Kopien
        self.copied = self.moved_back = 0
        self.result = {"mode": mode}      # für $MEDIACACHE_RESULT (USO)
        self.stopped = False              # MEDIACACHE_STOP: nach der aktuellen Datei aufgehört
        self.progress = Progress(mode, self.run_mode and bool(PROGRESS_FILE))   # MEDIACACHE_PROGRESS
        # USO: Jacks Boden (MEDIACACHE_KEEP_BYTES, None: keiner) – was auf dem Cache liegt, was zurückgeht, was wartet
        self.keep = keep_bytes()
        self.held = 0
        self.given_back = self.given_back_bytes = 0
        self.waiting = self.waiting_bytes = 0
        # USO (Phase 2): was über die Grenze zurückgeht – Cache-Pfad -> {title, ahead, who} (Fortschritt) und seine Stelle
        self.back_why, self.back_rank, self.given_back_list = {}, {}, []
        # USO: im Office (MEDIACACHE_GIVE_BACK_FREE=1) ist auch der Freiplatz eine Grenze – unter min_free_percent gibt der
        # Lauf zurück (0: keine Grenze); eigenständig hört das Befüllen dort nur auf, wie bisher
        # USO (2026-10-14): mit MEDIACACHE_WATCHED_ONLY nie nach Rang – nur der Cleanup bringt Geschautes zurück
        self.give_back_free = (os.environ.get("MEDIACACHE_GIVE_BACK_FREE") == "1" and not WATCHED_ONLY
                               and float(cfg["min_free_percent"]) > 0)
        self.office_pool = self.give_back_free or WATCHED_ONLY   # USO: was er hält und was wartet, in den Status

        self.fill_failures = 0
        # USO: files not copied / not brought back, per file (the office counts these, never log lines); «vanished» retries
        self.failed_files = {"fill": 0, "back": 0, "vanished": 0}
        self.failed_list = []
        self.retried = 0
        self._lock = threading.Lock()  # Zähler und Löschen/Ordner-Aufräumen bei parallelen Kopien
        self._halt_lock = threading.Lock()   # USO: halt() aus mehreren Kopien zugleich
        self.status = Status(mode, log, enabled=self.run_mode, log_interval=cfg["status_log_interval"])

    def halt(self):
        """True, sobald ein Anhalten verlangt wurde (nur im scharfen Lauf; einmal ins Log). USO: thread-sicher – parallele
        Kopien fragen, bevor sie eine neue beginnen; die laufenden werden fertig."""
        with self._halt_lock:
            return self._halt()

    def _halt(self):
        if not self.stopped and self.run_mode and stop_requested():
            self.stopped = True
            log.warning("Anhalten verlangt (MEDIACACHE_STOP – das Office: der Unraid-Mover startete, oder auf Wunsch): "
                        "nach der aktuellen Datei ist Schluss, was noch auf dem Cache liegt, bleibt geschützt")
        return self.stopped

    def cleanup_with_rsync(self, loc, candidates, listing, protected):
        """Cache -> Array per rsync nach /mnt/user0 (shfs wählt die Disk), Quelle erst nach Prüfung löschen."""
        sizes = {str(loc.cache / r): sz for r, sz in listing}
        failures = 0
        self.status.phase("Cleanup (Cache -> Array, rsync)", len(candidates), sum(sizes.values()))
        for i, p in enumerate(candidates, 1):
            if self.halt():
                protected.add(p)  # bleibt auf dem Cache und in der Liste
                self.progress.drop_back(sizes.get(p, 0))
                continue
            src = Path(p)
            rel = src.relative_to(loc.cache)
            dst = loc.on_array(rel)
            if failures >= 3:
                log.error("Drei rsync-Fehler in Folge – Cleanup abgebrochen, Ursache im Log prüfen")
                protected.add(p)
                self.progress.drop_back(sizes.get(p, 0))
                continue
            if dst.exists():
                log.warning(f"Ziel existiert schon auf dem Array (Duplikat?), Datei bleibt auf dem Cache: {rel}")
                protected.add(p)
                self.progress.drop_back(sizes.get(p, 0))
                continue
            mkdir_like(dst.parent, src.parent)
            key = f"{i}:{rel}"
            log.info(f"[{i}/{len(candidates)}] -> ARRAY {human(sizes.get(p, 0)):>10}  {rel}")
            self.status.job_start(key, rel, "Array", sizes.get(p, 0), i)
            cmd = self.rsync_cmd(src, dst)
            log.debug("rsync-Aufruf (Cleanup): " + " ".join(cmd))
            self.progress.start("back", rel, sizes.get(p, 0), dst, why=self.back_why.get(p))
            rc, out = self.rsync_copy(cmd, dst, rel, lambda done, speed, eta: self.status.job_progress(key, done, speed, eta))
            if rc != 0:
                failures += 1
                if vanished(rc, out):
                    log.error(VANISHED_SAID.format(rel=rel))
                    self.failed("back", rel, "vanished")
                else:
                    log.error(f"rsync-Fehler (Code {rc}) bei {rel}: {out.strip()}")
                    self.failed("back", rel)
                protected.add(p)
                self.status.job_end(key, False)
                self.progress.drop_back(sizes.get(p, 0))
                continue
            failures = 0
            try:
                if dst.stat().st_size != src.stat().st_size:
                    log.error(f"Grösse stimmt nicht überein nach rsync, Datei bleibt auf dem Cache: {rel}")
                    self.failed("back", rel)
                    protected.add(p)
                    self.status.job_end(key, False)
                    self.progress.drop_back(sizes.get(p, 0))
                    continue
                src.unlink()
            except OSError as e:
                log.error(f"Quelle konnte nicht gelöscht werden (Datei liegt jetzt doppelt!): {src} ({e})")
                self.failed("back", rel)
                protected.add(p)
                self.status.job_end(key, False)
                self.progress.drop_back(sizes.get(p, 0))
                continue
            self.status.job_end(key, True)
            self.moved_back += 1
            self.moved_back_bytes += sizes.get(p, 0)
            self.progress.done(sizes.get(p, 0))
            remove_empty_parents(src.parent, loc.cache, log)

    def cleanup(self, loc, current, sessions, protected):
        """Alte Exclude-Einträge, die nicht mehr on deck sind -> move-Binary (Cache -> Array)."""
        previous = read_exclude()
        candidates, listing, checked = [], [], {}
        # zuerst, was niemand mehr vormerkt (nach Pfad), dann, was übers Stück zurückgeht – in seiner Reihenfolge
        for p in sorted(previous, key=lambda q: (q in self.back_rank, self.back_rank.get(q, 0), q)):
            if p in current:
                continue
            path = Path(p)
            if not path.exists():
                continue
            try:
                rel = path.relative_to(loc.cache)
            except ValueError:
                log.warning(f"Exclude-Eintrag liegt nicht unter {loc.cache}, ignoriert: {p}")
                continue
            if is_playing(rel, sessions):
                log.info(f"[BLEIBT: läuft gerade] {rel}")
                protected.add(p)
                continue
            if not share_mode_ok(self.cfg, rel.parts[0], checked, log):
                protected.add(p)  # bleibt geschützt, bis die Share-Einstellung stimmt
                continue
            try:
                size = path.stat().st_size
            except OSError:
                continue
            self.to_array += size
            candidates.append(p)
            listing.append((rel, size))
        self.cleanup_planned = len(candidates)
        if not candidates:
            return
        log_grouped("MOVE -> ARRAY" if self.run_mode else "PLAN: -> ARRAY", listing)
        log.info(f"Cleanup: {len(candidates)} Dateien ({human(self.to_array)}) vom Cache aufs Array")
        if not self.run_mode:
            self.sim_delta += self.to_array  # Dry-Run: diesen Platz gäbe der Cleanup frei
            return
        self.progress.plan_back(len(candidates), self.to_array)
        self.progress.phase("back")
        sizes = {str(loc.cache / r): sz for r, sz in listing}
        if self.halt():
            protected.update(candidates)
            for p in candidates:
                self.progress.drop_back(sizes.get(p, 0))
            self.progress.write()
            return
        if self.cfg["cleanup_tool"] == "rsync":
            log.info("Cleanup-Werkzeug: rsync nach " + str(loc.array))
            self.cleanup_with_rsync(loc, candidates, listing, protected)
            return
        mover = detect_mover_bin(self.cfg)
        if not mover:
            log.error("Kein Unraid move-Binary gefunden (mover_bin in der Config setzen) – Cleanup übersprungen")
            protected.update(candidates)
            for p in candidates:
                self.progress.drop_back(sizes.get(p, 0))
            self.progress.write()
            return
        self.status.phase("Cleanup (Cache -> Array, move-Binary)", len(candidates), self.to_array)
        sizes = {str(loc.cache / r): sz for r, sz in listing}
        tracked = [(p, Path(p).relative_to(loc.cache), sizes.get(p, 0), lambda rel: loc.on_disk(rel)) for p in candidates]
        # USO: with a progress file or a stop file (the office) the binary gets one file per call — the progress names the
        # file in work and «stop after this file» holds exactly; standalone one call for all, as before
        per_file = bool(PROGRESS_FILE or STOP_FILE)
        batches = [[t] for t in tracked] if per_file else [tracked]
        stdout = stderr = ""
        asked = []
        for batch in batches:
            if per_file and self.halt():
                break
            paths = [t[0] for t in batch]
            if per_file:
                self.progress.start("back", batch[0][1], batch[0][2], "", why=self.back_why.get(paths[0]))
            with self.track_mover(batch, "-> ARRAY"):
                rc, out, err = run_mover(mover, paths, self.cfg["mover_debug_level"], log)
            stdout, stderr = stdout + out + "\n", stderr + err + "\n"
            asked += paths
            if per_file:
                p = paths[0]
                if Path(p).exists():
                    self.progress.drop_back(sizes.get(p, 0))
                else:
                    self.progress.done(sizes.get(p, 0))
        messages = summarize_mover_output(stdout + "\n" + stderr, candidates)
        left_by_reason = {}
        for p in candidates:
            if p not in asked:                   # USO: stopped before its turn – stays on the cache and in the list
                protected.add(p)
                self.progress.drop_back(sizes.get(p, 0))
                continue
            if Path(p).exists():
                protected.add(p)
                if not per_file:
                    self.progress.drop_back(sizes.get(p, 0))
                reason = messages.get(p, "keine Meldung vom Mover (in Benutzung oder Ziel existiert bereits)")
                left_by_reason.setdefault(reason, []).append(p)
            else:
                self.moved_back += 1
                self.moved_back_bytes += sizes.get(p, 0)
                if not per_file:
                    self.progress.done(sizes.get(p, 0))
                remove_empty_parents(Path(p).parent, loc.cache, log)
        for reason, paths in left_by_reason.items():
            log.warning(f"{len(paths)} Dateien nicht verschoben – Mover: {reason}")
            for p in paths[:3]:
                log.warning(f"      {p}")
            if len(paths) > 3:
                log.warning(f"      … und {len(paths) - 3} weitere (alle im DEBUG-Log)")
            for p in paths[3:]:
                log.debug(f"      {p}")
            if "No space left" in reason:
                cache_hits = [l for l in stdout.splitlines() if "create_parent" in l and str(loc.cache) in l]
                if cache_hits:
                    log.error("Der Mover wollte das Ziel AUF DEM CACHE anlegen – für diesen Share ist der Cache sein Ziel "
                              "(Share-Einstellung/Standardwerte, siehe Zeile «Share «…»: shareUseCache=…» oben). "
                              "Entweder Share auf Cache: Yes (Mover Cache → Array) stellen oder cleanup_tool=rsync verwenden.")
                else:
                    log.error("Ziel voll: der Mover findet auf dem Array keinen Platz (Share-Einstellung «Minimum free space», "
                              "Allocation/Split-Level oder eingeschlossene Disks prüfen).")

    def cleanup_frees(self, loc, current, sessions):
        """USO: die Bytes, die der Cleanup gleich aufs Array bringt – Exclude-Einträge, die nicht mehr on deck sind, noch
        auf dem Cache liegen und nicht laufen; nur, wo der Weg sicher ist (rsync oder eine bekannte Herkunft)."""
        n = 0
        for p in read_exclude():
            if p in current:
                continue
            path = Path(p)
            try:
                rel = path.relative_to(loc.cache)
                if is_playing(rel, sessions):
                    continue
                if self.cfg["cleanup_tool"] != "rsync" and share_mover_mode(self.cfg, rel.parts[0])[0] != "yes":
                    continue                     # the move binary goes Cache -> Array only for shareUseCache=yes
                n += path.stat().st_size
            except (ValueError, OSError):
                continue
        return n

    def give_back(self, loc, files, current, sessions, planner, cleanup_runs=True):
        """USO: Jacks Grenze ist der Freiplatz des Pools (nur mit MEDIACACHE_GIVE_BACK_FREE=1, das Office): liegt weniger frei
        als min_free_percent (gemessen wie beim Befüllen, auf ZFS der ganze Pool), nimmt der Lauf Dateien aus `current`
        (der Cleanup bringt sie zurück aufs Array), bis es wieder reicht (der Pool auf Frau Moverellis
        Ziel Y = 100 − min_free_percent) – aber nie unter
        MEDIACACHE_KEEP_BYTES (Jacks Hälfte: was On-Deck auf dem Cache liegt, bleibt mindestens so viel). Was schon
        geschaut ist, geht immer zuerst: der Freiplatz zählt, was der Cleanup gleich zurückbringt (nicht mehr on
        deck, läuft nicht), schon als frei – zurückgegeben wird nur, was dann noch fehlt (cleanup_runs=False: der Cleanup
        fällt aus, nichts davon zählt). Die Reihenfolge nach Rang (give_back_order): das am weitesten voraus Liegende
        zuerst, was jemand angefangen hat zuletzt; was gerade läuft, bleibt."""
        kept = [f for f in files if f.on_cache and loc.on_cache(f.rel).exists()]
        self.held = sum(f.size for f in kept)
        if not self.give_back_free:
            return
        total, free = pool_usage(loc.cache)
        free += self.cleanup_frees(loc, current, sessions) if cleanup_runs else 0
        min_free = float(self.cfg["min_free_percent"])
        need = int(-(-total * min_free // 100)) - free if total > 0 and free * 100 < total * min_free else 0
        if need <= 0:
            return
        why = (f"Zu wenig frei auf dem Pool: {free / total * 100:.1f} % ({human(free)} von {human(total)}), Minimum {min_free:g} % "
               f"– {human(need)} gehen zurück aufs Array (bis {min_free:g} % frei), das am weitesten voraus Liegende zuerst")
        if self.keep is not None:
            room = self.held - self.keep
            if room <= 0:
                log.info(f"Zu wenig frei auf dem Pool ({free / total * 100:.1f} %, Minimum {min_free:g} %), aber ich halte nur "
                         f"{human(self.held)} – meine Hälfte ist {human(self.keep)}: nichts geht zurück")
                return
            if room < need:
                need = room
                why += f" – höchstens {human(room)}: meine Hälfte ({human(self.keep)}) bleibt"
        if need <= 0:
            return
        log.warning(why)
        freed = 0
        for f in give_back_order(kept):
            if freed >= need:
                break
            if is_playing(f.rel, sessions):
                log.info(f"[BLEIBT: läuft gerade] {f.rel}")
                continue
            p = str(loc.on_cache(f.rel))
            current.discard(p)
            freed += f.size
            self.held -= f.size
            self.given_back += 1
            self.given_back_bytes += f.size
            who = planner.names(f)
            entry = {"title": f.title or str(f.rel), "ahead": f.ahead(), "who": who}
            self.back_why[p] = entry
            self.back_rank[p] = len(self.back_rank)
            if not self.given_back_list or self.given_back_list[-1]["title"] != entry["title"]:
                if len(self.given_back_list) < 20:
                    self.given_back_list.append(dict(entry))
            log.info(f"[ZURÜCK: Rang] {f.rel} ({human(f.size)}) – Gebe zurück: {f.title or f.rel} ({ahead_words(f.ahead(), who)})")

    def fill(self, loc, files, sessions):
        """On-Deck-Dateien vom Array auf den Cache – per rsync (Quelle erst nach Prüfung löschen) oder per move-Binary.
        Erst wird geplant (Freiplatz inkl. aller geplanten Kopien), dann kopiert – sequentiell oder parallel pro Disk."""
        from_disk = self.cfg["array_source"] == "disk"
        min_free = float(self.cfg["min_free_percent"])
        use_mover = self.cfg["fill_tool"] == "mover"
        label = "MOVE" if use_mover else "COPY"
        jobs = []  # (OnDeckFile, src, disk)
        pending = 0  # scharfer Lauf: Bytes, die die geplanten Kopien noch belegen werden
        last_group = None
        share_roots = {}  # {share: bool} – Share-Wurzel einmal pro Lauf prüfen/anlegen
        for f in files:                   # USO: reihum über die Benutzer nach Rang (Planner.fill_order)
            if f.on_cache:
                continue
            if is_playing(f.rel, sessions):
                log.info(f"[SKIP: läuft gerade] {f.rel}")
                self.progress.drop(f)
                continue
            share_root = loc.cache / f.rel.parts[0]
            # ZFS: Dataset <pool>/<share> wie Unraid; btrfs/XFS: Ordner. Dry-Run meldet nur.
            if not ensure_share_root(self.cfg, loc.cache, loc.array, f.rel.parts[0], self.run_mode, log, share_roots):
                log.warning(f"[SKIP: Share-Wurzel] {f.rel}")
                self.progress.drop(f)
                continue
            # Dataset (Quota) und Pool-Wurzel prüfen – appdata liegt oft auf demselben Pool
            delta = self.sim_delta if not self.run_mode else -pending
            free_after = free_percent_after(loc.cache, f.size, delta)
            if share_root.is_dir():
                free_after = min(free_after, free_percent_after(share_root, f.size, delta))
            if free_after < min_free:
                log.warning(f"[SKIP: Freiplatz] {f.rel} ({human(f.size)}) – danach nur {free_after:.1f} % frei, Minimum {min_free:g} %"
                            + (" (Dry-Run: Cleanup und geplante Kopien eingerechnet)" if not self.run_mode
                               else " (geplante Kopien eingerechnet)"))
                self.waiting += 1             # USO: wartet auf dem Array, bis Platz frei wird
                self.waiting_bytes += f.size
                self.progress.drop(f)
                continue
            disks = loc.on_disk(f.rel)
            sources = disks if from_disk else []
            if len(disks) > 1:
                log.warning(f"Datei liegt auf mehreren Disks (Duplikat im Array!): {', '.join(map(str, disks))} – nehme die erste")
            src = sources[0] if sources else loc.on_array(f.rel)
            if not src.is_file():
                log.warning(f"Quelle nicht gefunden: {src}")
                self.progress.drop(f)
                continue
            disk = self._disk_name(disks[0], loc) if disks else "?"
            self.to_cache += f.size
            self.fill_planned += 1
            self.held += f.size           # USO: zählt die geplante Kopie (auch im Dry-Run)
            key = group_key(f.rel)
            if key != last_group:
                log.info(f"[{label if self.run_mode else 'PLAN:'} -> CACHE] {key}   ({f.reason})")
                last_group = key
            log.info(f"      {human(f.size):>10}  {disk:<7} {f.rel}")
            if not self.run_mode:
                self.sim_delta -= f.size  # Dry-Run: diese Kopie würde Platz belegen
                continue
            pending += f.size
            jobs.append((f, src, disk))

        per_disk, total = self.cfg["parallel_per_disk"], self.cfg["parallel_total"]
        if not use_mover and (per_disk > 1 or total != 1):
            n_disks = len({d for _, _, d in jobs}) or 1
            limit = per_disk * n_disks if total == 0 else min(total, per_disk * n_disks)
            log.info(f"Parallel: {per_disk} pro Disk, {'ohne Gesamtlimit' if total == 0 else f'höchstens {total} gesamt'}"
                     f" – {n_disks} Quell-Disk(s), also bis {limit} Kopien gleichzeitig")
        if not jobs:
            return
        if use_mover:
            if not self.halt():
                self.fill_with_mover(loc, [(f, src) for f, src, _ in jobs])
            return
        self.status.phase("Befüllen (Array -> Cache)", len(jobs), sum(f.size for f, _, _ in jobs))
        if per_disk == 1 and total == 1:
            for i, job in enumerate(jobs, 1):  # wie das Original: eine Datei nach der anderen
                if self.halt():               # USO: MEDIACACHE_STOP – nach der aktuellen Datei
                    break
                if self.fill_failures >= 3:
                    log.error("Drei rsync-Fehler in Folge – Befüllen abgebrochen, Ursache im Log prüfen")
                    break
                self.copy_one(loc, *job, i, len(jobs))
        else:
            self.copy_parallel(loc, jobs, per_disk, total)
        log.info(self.status.summary())

    def copy_parallel(self, loc, jobs, per_disk, total):
        """Pro Quell-Disk eine Warteschlange mit per_disk Workern; total begrenzt alle Disks zusammen (0 = ohne Limit)."""
        queues = {}
        for i, (f, src, disk) in enumerate(jobs, 1):
            queues.setdefault(disk, collections.deque()).append((f, src, disk, i))
        gate = threading.Semaphore(total) if total > 0 else None
        stop = threading.Event()

        def worker(disk):
            q = queues[disk]
            while not stop.is_set():
                with self._lock:
                    if not q:
                        return
                    f, src, d, i = q.popleft()
                if gate:
                    gate.acquire()
                try:
                    if stop.is_set():
                        return
                    if self.halt():           # USO: MEDIACACHE_STOP – keine neue Kopie mehr
                        stop.set()
                        return
                    self.copy_one(loc, f, src, d, i, len(jobs))
                    if self.fill_failures >= 3 and not stop.is_set():
                        stop.set()
                        log.error("Drei rsync-Fehler in Folge – Befüllen abgebrochen (laufende Kopien werden fertig), "
                                  "Ursache im Log prüfen")
                finally:
                    if gate:
                        gate.release()

        threads = [threading.Thread(target=worker, args=(disk,), name=f"copy-{disk}-{n}", daemon=True)
                   for disk, q in queues.items() for n in range(min(per_disk, len(q)))]
        for t in threads:
            t.start()
        for t in threads:
            t.join()

    def rsync_cmd(self, src, dst):
        extra = ["--info=progress2"] if self.cfg["copy_progress"] else []
        return ["rsync", *self.cfg["rsync_args"], *extra, str(src), str(dst)]

    def rsync_copy(self, cmd, dst, rel, on_progress):
        """USO «vanished»: rsync once; when its temporary file vanished before the rename (someone else deleted it in the
        target, nothing there yet), once more — the next time it is usually through. Any other error stays an error."""
        rc, out = run_rsync(cmd, on_progress)
        if vanished(rc, out) and not dst.exists() and not self.halt():
            log.warning(f"Die Kopie von {rel} verschwand im Ziel vor dem Umbenennen (ein anderes Programm hat sie gelöscht, "
                        "z. B. ein Abgleich mit --delete) – noch ein Versuch")
            with self._lock:
                self.retried += 1
            rc, out = run_rsync(cmd, on_progress)
        return rc, out

    def failed(self, phase, rel, why=None):
        """USO: a file this run could not copy or bring back — counted per file (not per log line) for the office;
        why «vanished» (someone else deleted its half copy) counted apart too. A parallel fill calls it holding self._lock."""
        self.failed_files[phase] += 1
        if why == "vanished":
            self.failed_files["vanished"] += 1
        if len(self.failed_list) < 20:
            self.failed_list.append({"phase": phase, "rel": str(rel)} | ({"why": why} if why else {}))

    def copy_one(self, loc, f, src, disk, index, count):
        """Eine Datei Array -> Cache per rsync, Grössenvergleich, dann Quelle löschen. Thread-sicher."""
        dst = loc.on_cache(f.rel)
        key = f"{index}:{f.rel}"
        log.info(f"[{index}/{count}] Start  {disk:<7} {human(f.size):>10}  {f.rel}")
        self.status.job_start(key, f.rel, disk, f.size, index)
        t0 = time.time()
        ok = False
        try:
            mkdir_like(dst.parent, src.parent)
            cmd = self.rsync_cmd(src, dst)
            log.debug("rsync-Aufruf: " + " ".join(cmd))
            self.progress.start("fill", f.rel, f.size, dst, f)
            rc, out = self.rsync_copy(cmd, dst, f.rel, lambda done, speed, eta: self.status.job_progress(key, done, speed, eta))
            if rc != 0:
                gone = vanished(rc, out)
                with self._lock:
                    self.fill_failures += 1
                    self.failed("fill", f.rel, "vanished" if gone else None)
                if gone:
                    log.error(VANISHED_SAID.format(rel=f.rel))
                else:
                    log.error(f"rsync-Fehler (Code {rc}) bei {f.rel}: {out.strip()}")
                    log.error("Quelle bleibt auf dem Array; Ziel prüfen, sonst liegt die Datei doppelt")
                return
            with self._lock:
                self.fill_failures = 0
                try:
                    if dst.stat().st_size != src.stat().st_size:
                        log.error(f"Grösse stimmt nicht überein nach rsync, Quelle bleibt: {f.rel}")
                        self.failed("fill", f.rel)
                        return
                    src.unlink()
                except OSError as e:
                    log.error(f"Quelle konnte nicht gelöscht werden (Datei liegt jetzt doppelt!): {src} ({e})")
                    self.failed("fill", f.rel)
                    return
                self.copied += 1
                self.copied_bytes += f.size
                f.on_cache = True
                ok = True
                if src == loc.on_array(f.rel):      # USO: über /mnt/user0 – auch auf der Disk, auf der sie lag
                    remove_empty_on_disk(loc, f.rel, disk, log)
                remove_empty_parents(src.parent, self._disk_root(src, loc, src != loc.on_array(f.rel)), log)
        finally:
            self.status.job_end(key, ok)
            if ok:
                self.progress.done(f.size, f)
            else:
                self.progress.drop(f)
            secs = time.time() - t0
            if ok:
                log.info(f"[{index}/{count}] Fertig {disk:<7} {human(f.size):>10} in {secs:.0f} s "
                         f"({human(f.size / secs if secs > 0 else 0)}/s)  {f.rel}")

    @staticmethod
    def _disk_name(path, loc):
        """/mnt/disk3/Filme/x.mkv -> disk3"""
        depth = len(Path(loc.disks_glob).parts)
        parts = Path(path).parts
        return parts[depth - 1] if len(parts) >= depth else "?"

    def fill_with_mover(self, loc, batch):
        """Array -> Cache über das Unraid move-Binary (ein Aufruf für alle Dateien), danach Ergebnis prüfen."""
        mover = detect_mover_bin(self.cfg)
        if not mover:
            log.error("Kein Unraid move-Binary gefunden (mover_bin in der Config setzen) – nichts kopiert")
            return
        log.info(f"Befüllen: {len(batch)} Dateien ({human(sum(f.size for f, _ in batch))}) per move-Binary Array -> Cache")
        self.status.phase("Befüllen (Array -> Cache, move-Binary)", len(batch), sum(f.size for f, _ in batch))
        tracked = [(str(src), f.rel, f.size, lambda rel: [loc.on_cache(rel)]) for f, src in batch]
        on_disk = {f.rel: (self._disk_name(d[0], loc) if (d := loc.on_disk(f.rel)) else "?")      # USO: vor dem Verschieben
                   for f, src in batch if src == loc.on_array(f.rel)}
        with self.track_mover(tracked, "-> CACHE"):
            run_mover(mover, [str(src) for _, src in batch], self.cfg["mover_debug_level"], log)
        for f, src in batch:
            dst = loc.on_cache(f.rel)
            if dst.exists() and not src.exists():
                self.copied += 1
                self.copied_bytes += f.size
                self.progress.done(f.size, f)
                f.on_cache = True
                if f.rel in on_disk:                # USO: über /mnt/user0 – auch auf der Disk, auf der sie lag
                    remove_empty_on_disk(loc, f.rel, on_disk[f.rel], log)
                remove_empty_parents(src.parent, self._disk_root(src, loc, src != loc.on_array(f.rel)), log)
            elif dst.exists() and src.exists():
                log.error(f"Datei liegt jetzt doppelt (Quelle nicht entfernt): {f.rel}")
                self.progress.drop(f)
            else:
                log.warning(f"Nicht auf den Cache verschoben (in Benutzung, oder das Binary kennt keinen Pool für diesen Share?): {f.rel}")
                self.progress.drop(f)
        if self.copied == 0:
            log.warning("Das move-Binary hat nichts auf den Cache verschoben – fill_tool=rsync verwenden oder mover_debug_level=1 setzen und Log prüfen")

    @contextlib.contextmanager
    def track_mover(self, items, label):
        """Überwacht einen laufenden move-Binary-Aufruf: alle 2 s wird geprüft, welche Quellen schon weg sind.
        Das Binary arbeitet die Pfade der Reihe nach ab – die erste noch vorhandene Quelle gilt als aktuelle Datei;
        ihr Fortschritt ist die Grösse am Ziel, soweit dort schon sichtbar. items: (quelle, rel, size, targets(rel))."""
        stop = threading.Event()
        count = len(items)

        def monitor():
            pending = list(enumerate(items, 1))
            current = None
            skipped = set()  # schon als Fehler gezählt
            last_done = 0
            t_current = time.time()
            while True:
                finished = stop.wait(2)
                still = []
                for i, (src, rel, size, targets) in pending:
                    if os.path.exists(src):
                        still.append((i, (src, rel, size, targets)))
                        continue
                    key = f"m{i}"
                    if current != key:
                        self.status.job_start(key, rel, "move", size, i)
                    self.status.job_end(key, True)
                    secs = time.time() - t_current if current == key else 0
                    log.info(f"[{i}/{count}] {label} fertig {human(size):>10}"
                             + (f" in {secs:.0f} s" if secs else "") + f"  {rel}")
                    if current == key:
                        current = None
                    last_done = max(last_done, i)
                pending = still
                if finished:
                    # vom Binary liegengelassen (in Benutzung, Ziel voll …) – im Status als Fehler zählen
                    for i, (src, rel, size, targets) in pending:
                        key = f"m{i}"
                        if key in skipped:
                            continue
                        if current != key:
                            self.status.job_start(key, rel, "move", size, i)
                        self.status.job_end(key, False)
                    break
                if not pending:
                    break
                # aktuelle Datei = erste noch vorhandene nach der zuletzt fertigen (übersprungene bleiben zurück)
                i, (src, rel, size, targets) = next((x for x in pending if x[0] > last_done), pending[0])
                key = f"m{i}"
                if current != key:
                    if current:
                        skipped.add(current)
                        self.status.job_end(current, False)  # übersprungen (in Benutzung o.ä.) – Endergebnis prüft unten
                    current, t_current = key, time.time()
                    self.status.job_start(key, rel, "move", size, i)
                    log.info(f"[{i}/{count}] {label} läuft {human(size):>10}  {rel}")
                try:
                    done = max((t.stat().st_size for t in targets(rel) if t.is_file()), default=0)
                except OSError:
                    done = 0
                self.status.job_progress(key, done)

        t = threading.Thread(target=monitor, name="mover-monitor", daemon=True)
        t.start()
        try:
            yield
        finally:
            stop.set()
            t.join(timeout=30)

    @staticmethod
    def _disk_root(src, loc, from_disk):
        """/mnt/disk3/Filme/X/x.mkv -> /mnt/disk3; bei FUSE-Fallback array_path."""
        if not from_disk:
            return loc.array
        depth = len(Path(loc.disks_glob).parts)
        return Path(*src.parts[:depth]) if len(src.parts) > depth else loc.array

    @staticmethod
    def render_groups(groups, mark, show_files, indent="    "):
        """Filme einzeln, Serien als Block mit ihren Folgen in Reihenfolge."""
        chains = {}
        for g in groups:
            chains.setdefault(g.chain, []).append(g)
        n_files = "{n} Datei{pl}"
        for chain_groups in chains.values():
            first = chain_groups[0]
            if first.series is None:
                for g in chain_groups:
                    print(f"{indent}{mark} {g.title}   [{g.location()}] {human(g.size)}, "
                          f"{n_files.format(n=len(g.files), pl='en' if len(g.files) != 1 else '')}   ({g.source})")
                    if show_files:
                        for f in g.files:
                            print(f"{indent}  {mark} [{'CACHE' if f.on_cache else 'ARRAY'}] {human(f.size):>10}  {f.rel}")
                continue
            total = sum(g.size for g in chain_groups)
            locs = {g.location() for g in chain_groups}
            loc = locs.pop() if len(locs) == 1 else "TEILS"
            print(f"{indent}{mark} {first.series}   ({first.source})  {len(chain_groups)} Folge{'n' if len(chain_groups) != 1 else ''}, "
                  f"{human(total)}  [{loc}]")
            for g in chain_groups:
                print(f"{indent}  {mark} {g.title}   [{g.location()}] {human(g.size)}, "
                      f"{n_files.format(n=len(g.files), pl='en' if len(g.files) != 1 else '')}   ({g.source})")
                if show_files:
                    for f in g.files:
                        print(f"{indent}    {mark} [{'CACHE' if f.on_cache else 'ARRAY'}] {human(f.size):>10}  {f.rel}")

    def report(self, planner, user_filter=None, show_files=True):
        """On-Deck-Liste pro Benutzer: Filme, dann Serien mit ihren Folgen, dann was nicht ins Budget passt."""
        wanted = {u.strip().lower() for u in (user_filter or []) if u.strip()}
        users = sorted(planner.users.values(), key=lambda u: (u.server, u.name.lower()))
        if wanted:
            users = [u for u in users if u.name.lower() in wanted or any(i.lower() in wanted for i in u.uids)]
            if not users:
                print(f"\nKein Benutzer passt auf: {', '.join(sorted(wanted))}")
                print("Bekannt: " + ", ".join(f"{u.name} ({u.uid})" for u in planner.users.values()))
                return
        shown = set()
        for u in users:
            head = f"=== {u.name} @ {u.server} ==="
            if planner.budget_mode and u.has_candidates():
                head += (f"  Budget: {human(u.bytes)} von {human(u.budget)}"
                         f" (Filme {human(u.movie_bytes)}, Serien {human(u.series_bytes)})")
            print(f"\n{head}")
            if not u.has_candidates():
                print("  (nichts on deck)")
                continue
            movies = [g for g in u.selected if g.series is None]
            series = [g for g in u.selected if g.series is not None]
            if movies:
                print(f"  Filme: {len(movies)} Einträge, {human(sum(g.size for g in movies))}")
                self.render_groups(movies, "•", show_files)
            if series:
                n_series = len({g.chain for g in series})
                print(f"  Serien: {n_series} Serie{'n' if n_series != 1 else ''}, {len(series)} Folgen, {human(sum(g.size for g in series))}")
                self.render_groups(series, "•", show_files)
            if u.skipped:
                print(f"  Nicht im Budget: {len(u.skipped)} Einträge, {human(sum(g.size for g in u.skipped))}"
                      "  (bleiben auf dem Array bzw. werden zurückgeräumt)")
                self.render_groups(u.skipped, "✗", show_files)
            for g in u.selected:
                for f in g.files:
                    shown.add(str(f.rel))
        files = [f for f in planner.files.values() if str(f.rel) in shown] if wanted else list(planner.files.values())
        on_c = sum(x.size for x in files if x.on_cache)
        on_a = sum(x.size for x in files if not x.on_cache)
        print(f"\n{'Auswahl' if wanted else 'Gesamt'}: {len(files)} Dateien – auf dem Cache {human(on_c)}, noch auf dem Array {human(on_a)}, zusammen {human(on_c + on_a)}")
        if not wanted and planner.budget_mode:
            print(f"Budget: {human(int(self.cfg['cache_budget_bytes']))} für {sum(1 for u in planner.users.values() if u.has_candidates())} aktive Benutzer")
        print(f"Exclude-Liste: {EXCLUDE_FILE} ({len(read_exclude())} Einträge)\n")

    def execute(self):
        state = "abgebrochen (Fehler)"
        try:
            rc = self._execute()
            state = "fertig"
            return rc
        finally:
            self.status.finish(state)

    def _execute(self):
        cfg = self.cfg
        log.info(f"=== MediaCache {__version__} – Modus: {self.mode.upper()} ===")
        if unraid_mover_running():
            log.warning("Der reguläre Unraid-Mover läuft gerade – Cleanup könnte mit ihm kollidieren (nur Log-Meldungen, kein Datenrisiko)")

        sessions, sessions_ok = collect_sessions(cfg, log)
        if self.mode == "release":
            return self.release(sessions, sessions_ok)
        planner = Planner(cfg)
        files = planner.run()
        self.result["on_deck"] = {"files": len(files), "bytes": sum(f.size for f in files),
                                  "on_cache": sum(1 for f in files if f.on_cache)}
        nib = planner.not_in_budget()           # USO: what waits beyond the budget (Jack's «budget full»)
        if nib is not None:
            self.result["not_in_budget"] = nib
        log.info(f"On deck: {len(files)} Dateien, {human(sum(f.size for f in files))} "
                 f"(davon {sum(1 for f in files if f.on_cache)} bereits auf dem Cache)")
        if self.mode == "report":
            self.report(planner, self.user_filter, self.show_files)
            return 0
        if not NO_FILL:
            # USO: ein Lauf, der nur freigibt (MEDIACACHE_NO_FILL), plant nichts zum Holen ein – sonst zählte sein Fortschritt
            # die Dateien mit, die erst der nächste Schritt holt («2 von 16 Dateien» beim Zurückgeben)
            self.progress.plan_fill(planner, files)

        # Referenz-Locations (Cache/Array sind global, Mappings spielen hier keine Rolle)
        loc = Locations(cfg, cfg["instances"][0]["_mappings"])
        current = {str(loc.on_cache(f.rel)) for f in files}
        protected = set()
        if self.office_pool:          # USO: ohne Rückgabe nach Rang zählt give_back() nur, was er hält
            self.give_back(loc, files, current, sessions, planner, not (planner.errors or not sessions_ok))
        try:
            if planner.errors or not sessions_ok:
                log.warning("Planung unvollständig (Medienserver nicht vollständig erreichbar) – Cleanup wird übersprungen, "
                            "bisherige Exclude-Liste bleibt geschützt")
                protected.update(p for p in read_exclude() if Path(p).exists())
            else:
                self.cleanup(loc, current, sessions, protected)
            if NO_FILL:
                # USO: nur freigeben (das Office holt später, in einem zweiten Lauf) – was wartet, nur gezählt
                for f in files:
                    if not loc.on_cache(f.rel).exists():
                        self.waiting += 1
                        self.waiting_bytes += f.size
                log.info(f"Nur freigeben (MEDIACACHE_NO_FILL): nichts geholt – {self.waiting} Dateien ({human(self.waiting_bytes)}) warten auf dem Array")
            else:
                self.progress.phase("fill")
                self.fill(loc, files, sessions)
        finally:
            self.progress.phase("done")
            if self.run_mode:
                on_cache = {str(loc.on_cache(f.rel)) for f in files if loc.on_cache(f.rel).exists()}
                write_exclude(on_cache | protected)
        if self.run_mode:
            excl = read_exclude()
            log.info(f"Ergebnis Cleanup:  {self.moved_back} von {self.cleanup_planned} Dateien aufs Array verschoben "
                     f"({human(self.moved_back_bytes)} von {human(self.to_array)})")
            log.info(f"Ergebnis Befüllen: {self.copied} von {self.fill_planned} Dateien auf den Cache kopiert "
                     f"({human(self.copied_bytes)} von {human(self.to_cache)})")
            log.info(f"Exclude-Liste: {len(excl)} Einträge"
                     + (f", davon {len(protected)} geschützt (laufen gerade oder konnten nicht verschoben werden)" if protected else ""))
        else:
            log.info(f"Dry-Run: {self.cleanup_planned} Dateien ({human(self.to_array)}) würden aufs Array, "
                     f"{self.fill_planned} Dateien ({human(self.to_cache)}) auf den Cache – nichts verändert")
        self.result["cleanup"] = {"planned": self.cleanup_planned, "done": self.moved_back,
                                  "bytes_planned": self.to_array, "bytes_done": self.moved_back_bytes}
        self.result["fill"] = {"planned": self.fill_planned, "done": self.copied,
                               "bytes_planned": self.to_cache, "bytes_done": self.copied_bytes}
        self.result["protected"] = len(protected)
        self.result["incomplete"] = bool(planner.errors or not sessions_ok)
        self.result["failed"] = dict(self.failed_files, files=self.failed_list, retried=self.retried)   # USO
        if self.office_pool:              # USO: was er hält, sein Boden, was zurückging (in Worten fürs Office), was wartet
            self.result["pool"] = {"keep": self.keep, "held": self.held, "waiting": self.waiting, "waiting_bytes": self.waiting_bytes}
            self.result["give_back"] = {"files": self.given_back, "bytes": self.given_back_bytes, "items": self.given_back_list}
        if self.stopped:
            log.info("Angehalten nach der aktuellen Datei (MEDIACACHE_STOP) – der nächste Lauf macht weiter")
        return 3 if self.stopped else 0


    def release(self, sessions, sessions_ok):
        """Alles zurück aufs Array: der Cleanup über die ganze Exclude-Liste (nichts ist mehr «on deck») – derselbe Weg
        wie der normale Cleanup (cleanup_tool), ohne Befüllen. Was gerade läuft, bleibt.
        Danach steht in der Exclude-Liste nur noch, was weiter auf dem Cache liegt."""
        cfg = self.cfg
        if not sessions_ok:
            log.warning("Nicht alle Emby-Instanzen haben geantwortet – was dort gerade läuft, kann nicht geschützt werden "
                        "(das Office fragt vorher, ob jemand schaut)")
        loc = Locations(cfg, cfg["instances"][0]["_mappings"])
        previous = read_exclude()
        protected = set()
        try:
            self.cleanup(loc, set(), sessions, protected)
        finally:
            self.progress.phase("done")
            left = {p for p in previous if Path(p).exists()}
            write_exclude(left)
        log.info(f"Ergebnis Zurückbringen: {self.moved_back} von {self.cleanup_planned} Dateien aufs Array verschoben "
                 f"({human(self.moved_back_bytes)} von {human(self.to_array)})")
        log.info(f"Exclude-Liste: {len(left)} Einträge" + (" (liegen weiter auf dem Cache)" if left else ""))
        self.result["cleanup"] = {"planned": self.cleanup_planned, "done": self.moved_back,
                                  "bytes_planned": self.to_array, "bytes_done": self.moved_back_bytes}
        self.result["fill"] = {"planned": 0, "done": 0, "bytes_planned": 0, "bytes_done": 0}
        self.result["protected"] = len(left)
        self.result["incomplete"] = not sessions_ok
        self.result["failed"] = dict(self.failed_files, files=self.failed_list, retried=self.retried)   # USO
        return 3 if self.stopped else 0


def main():
    parser = argparse.ArgumentParser(description="MediaCache für Unraid", epilog="Details: siehe Kopf des Scripts")
    parser.add_argument("--run", action="store_true", help="Aktionen wirklich ausführen (Default: Dry-Run)")
    parser.add_argument("--show-on-deck", action="store_true", help="Nur die On-Deck-Liste anzeigen (pro Benutzer, nach Quelle)")
    parser.add_argument("--release", action="store_true", help="Alles aus der Exclude-Liste zurück aufs Array (nichts befüllen)")
    parser.add_argument("--user", help="Report nur für diese Benutzer (Name oder ID, Komma-getrennt); nur mit --show-on-deck")
    parser.add_argument("--compact", action="store_true", help="Report ohne einzelne Dateien, nur Einträge")
    parser.add_argument("--status", action="store_true", help="Status des laufenden bzw. letzten Laufs anzeigen")
    parser.add_argument("--watch", type=float, nargs="?", const=2, metavar="SEK",
                        help="mit --status: alle SEK Sekunden neu anzeigen (Default 2), Ende mit Ctrl+C")
    args = parser.parse_args()
    if args.watch is not None and args.watch <= 0:
        parser.error("--watch braucht ein Intervall grösser als 0 Sekunden")
    if args.watch and not args.status:
        parser.error("--watch nur zusammen mit --status (in einer zweiten Shell, während --run läuft)")
    if args.status:
        if not args.watch:
            print(format_status())
            return 0
        try:
            while True:
                print("\033[2J\033[H" + format_status(), flush=True)
                time.sleep(args.watch)
        except KeyboardInterrupt:
            return 0
    mode = os.environ.get("MEDIACACHE_MODE", "dry").lower()
    if args.release:
        mode = "release"
    elif args.run:
        mode = "run"
    elif args.show_on_deck:
        mode = "report"
    if mode not in ("dry", "report", "run", "release"):
        log.error(f"Ungültiger Modus: {mode}")
        return 2
    user_filter = args.user if args.user is not None else os.environ.get("MEDIACACHE_REPORT_USER")
    if user_filter and mode != "report":
        log.error("--user filtert nur die Anzeige und ist deshalb nur mit --show-on-deck erlaubt – "
                  "ein Lauf für einzelne Benutzer würde die Dateien der anderen zurückräumen")
        return 2

    started = int(time.time())
    try:
        cfg = load_config()
        keep_bytes()          # USO: a floor that is no number stops here, like a bad setting
    except ConfigError as e:
        log.error(str(e))
        write_result({"mode": mode, "result": "config", "message": str(e), "started": started, "finished": int(time.time())})
        return 1

    lock = acquire_lock(log)
    if lock is None:
        write_result({"mode": mode, "result": "busy", "started": started, "finished": int(time.time())})
        return 1
    runner = Runner(cfg, mode, user_filter.split(",") if user_filter else None, not args.compact)
    result, code = "failed", 1
    try:
        code = runner.execute()
        result = "stopped" if runner.stopped else "ok"
        return code
    except Exception:
        log.exception("Unerwarteter Fehler")
        return 1
    finally:
        lock.close()
        write_result(dict(runner.result, result=result, started=started, finished=int(time.time())))


if __name__ == "__main__":
    sys.exit(main())
