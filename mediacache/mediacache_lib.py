#!/usr/bin/env python3
"""
MediaCache – gemeinsame Funktionen für mediacache_run.py, mediacache_cleaner.py und mediacache_setup.py.

Enthält: Config mit Defaults und Validierung, Pfad-Übersetzung (Docker → Host → relativ zum Share),
Emby-/Jellyfin-API-Client (nur Standardbibliothek, kein `requests`), Aufruf des Unraid move-Binaries,
Lockfile, Exclude-Liste und Logging.

Umgebungsvariablen (gelten für alle Scripte):
  MEDIACACHE_DIR        Arbeitsverzeichnis für Settings, Exclude-Liste, Lock und logs/
                       Default: das Verzeichnis, in dem die Scripte liegen
  MEDIACACHE_CONFIG     Pfad zur Settings-JSON; Default: $MEDIACACHE_DIR/mediacache_settings.json
  MEDIACACHE_LOG_LEVEL  DEBUG | INFO | WARNING; Default INFO
  MEDIACACHE_RESULT     USO, optional: JSON-Datei, in die mediacache_run.py am Ende das Ergebnis schreibt
                       (Modus, Zähler, Fehler) – für Programme, die MediaCache starten (Unraid Secretary Office)
  MEDIACACHE_PROGRESS   optional: JSON-Datei, die mediacache_run.py im scharfen Lauf an jeder Dateigrenze neu schreibt
                       (Phase, pro Benutzer geplant/erledigt, die Datei in Arbeit) – für eine Fortschrittsanzeige
"""
import copy
import datetime
import fcntl
import glob
import json
import logging
import os
import re
import shutil
import stat
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from logging.handlers import RotatingFileHandler
from pathlib import Path

__version__ = "7.4.0 (2026-10-15)"

BASE_DIR = Path(os.environ.get("MEDIACACHE_DIR") or Path(__file__).resolve().parent)
CONFIG_FILE = Path(os.environ.get("MEDIACACHE_CONFIG") or (BASE_DIR / "mediacache_settings.json"))
EXCLUDE_FILE = BASE_DIR / "mediacache_exclude.txt"
LOCK_FILE = BASE_DIR / "mediacache.lock"
# USO: MEDIACACHE_LIVE_STATUS puts the live status elsewhere (the office: RAM, not the pool every 2 s)
STATUS_FILE = Path(os.environ.get("MEDIACACHE_LIVE_STATUS") or (BASE_DIR / "mediacache_status.json"))
LOG_DIR = BASE_DIR / "logs"
ORIGIN_FILE = BASE_DIR / "mediacache_origin.json"   # Cache-Pfad -> Array-Disk, von der die Datei kam
RESULT_FILE = os.environ.get("MEDIACACHE_RESULT") or ""   # USO
PROGRESS_FILE = os.environ.get("MEDIACACHE_PROGRESS") or ""

MOVER_CANDIDATES = ["/usr/libexec/unraid/move", "/usr/local/sbin/move", "/usr/local/bin/move"]
MOVER_PIDFILE = "/var/run/mover.pid"

# Alle Config-Schlüssel mit Default. Unbekannte Schlüssel werden ignoriert, fehlende ergänzt.
DEFAULTS = {
    "cache_path": "/mnt/cache",          # Pool, auf den gecacht wird (z.B. /mnt/cache oder /mnt/maple)
    "array_path": "/mnt/user0",          # Array-Sicht ohne Pools (FUSE)
    "user_path": "/mnt/user",            # Zusammengeführte Sicht (der Medienserver sieht diese Pfade)
    "array_disks_glob": "/mnt/disk[0-9]*",  # Echte Array-Disks (Quelle bei array_source = disk, Disk-Zuordnung für Parallelität/Status)
    "array_source": "user0",             # rsync-Quelle: user0 = /mnt/user0/... wie das Original, disk = echte /mnt/diskN-Pfade
    "instances": [],                     # [{"servername": "...", "type": "emby|jellyfin|plex", "url": "...", "api_key": "...", "path_mappings": {...}}]
    "path_mappings": {},                 # Globales Mapping Docker-Pfad -> Host-Pfad (/mnt/user/...);
                                         #   USO: ein leerer Host-Pfad heisst: diesen Ordner absichtlich nicht cachen
    "libraries": [],                     # Nur informativ (Wizard); Filter passiert über path_mappings
    "valid_users": [],                   # Liste von User-IDs oder Dict {id: {"budget": "300G"}}; leer = alle Benutzer
    "number_episodes": 3,                # Zähl-Modus: wie viele Folgen nach der aktuellen vorgeladen werden
    "cache_budget": "",                  # Budget-Modus: z.B. "2.5T" oder "800G" – leer = Zähl-Modus
    "movie_share_percent": 50,           # Budget-Modus: Anteil Filme am Benutzer-Budget (weich, nach Bytes)
    "max_episodes_per_series": 0,        # Budget-Modus: Folgen pro Serie höchstens (0 = das Budget entscheidet)
    "max_resume_items": 10,              # Wie viele "Weiterschauen"-Einträge pro Benutzer berücksichtigt werden
    "max_resume_movies": None,           # davon getrennt: angefangene Filme pro Benutzer (None = max_resume_items)
    "max_resume_series": None,           # davon getrennt: angefangene Serien pro Benutzer, auch für «Als Nächstes» (None = max_resume_items)
    "max_favorite_series": 10,           # Wie viele Favoriten-Serien pro Benutzer (0 = keine Favoriten)
    "use_next_up": True,                 # Auch «Als Nächstes» des Servers (/Shows/NextUp) als Quelle – hält Serien zwischen zwei Folgen im Cache
    "min_free_percent": 20,              # Unter diesem Freiplatz (Prozent) wird nichts mehr auf den Cache kopiert
    "movie_mode": "folder",              # folder = ganzer Filmordner mitnehmen, file = nur Dateien mit gleichem Namen
    "create_share_root": True,           # Fehlende Share-Wurzel auf dem Pool anlegen: ZFS = Dataset <pool>/<share> (wie Unraid),
                                         #   btrfs/XFS = Ordner; false = Dateien solcher Shares überspringen
    "mover_bin": "",                     # Leer = automatisch erkennen
    "mover_debug_level": 0,              # Parameter -d für das move-Binary (0 = still, 1..3 = ausführlicher)
    "rsync_args": ["-aAX", "--numeric-ids"],  # Vollständige rsync-Optionen (Default = wie das Original)
    "fill_tool": "rsync",                # Array -> Cache: rsync (kopieren + Quelle löschen) oder mover (Unraid move-Binary)
    "cleanup_tool": "mover",             # Cache -> Array: mover (Unraid move-Binary, wie das Original) oder rsync
                                         #   (rsync /mnt/<pool>/<rel> -> /mnt/user0/<rel>, unabhängig von der Mover-Richtung des Shares)
    "return_to_origin": True,            # Cache -> Array: zuerst zurück auf die Disk, von der die Datei kam (mediacache_origin.json),
                                         #   per rsync nach /mnt/diskN/<rel>; nur wenn das nicht geht, cleanup_tool.
                                         #   Beim Befüllen bleibt der (leere) Ordner auf der Disk dann stehen – als Wegweiser
    "parallel_per_disk": 1,              # Befüllen per rsync: gleichzeitige Kopien pro Quell-Disk (1 = wie das Original)
    "parallel_total": 1,                 # Befüllen per rsync: gleichzeitige Kopien insgesamt (0 = ohne Limit, nur parallel_per_disk)
    "copy_progress": True,               # rsync zusätzlich mit --info=progress2 (nur Ausgabe) – Fortschritt pro Datei im Status
    "status_log_interval": 60,           # Sekunden zwischen Fortschrittszeilen im Log während des Kopierens (0 = aus)
    "api_timeout": 10,                   # Sekunden pro API-Aufruf
    "shares_cfg_dir": "/boot/config/shares",  # Unraid Share-Konfigurationen (für die Mover-Richtungs-Prüfung)
}


# --------------------------------------------------------------------------- Logging
class _LevelCounter(logging.Handler):
    """Zählt Warnungen und Fehler eines Laufs (für die Status-Datei)."""

    def __init__(self):
        super().__init__(logging.WARNING)
        self.counts = {"WARNING": 0, "ERROR": 0}

    def emit(self, record):
        key = "ERROR" if record.levelno >= logging.ERROR else "WARNING"
        self.counts[key] += 1


LEVEL_COUNTER = _LevelCounter()


def setup_logging(name, filename):
    """Rotierendes Logfile (10 MB x 20) plus Konsole. Level über MEDIACACHE_LOG_LEVEL."""
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    level = getattr(logging, os.environ.get("MEDIACACHE_LOG_LEVEL", "INFO").upper(), logging.INFO)
    log = logging.getLogger(name)
    log.setLevel(level)
    log.handlers.clear()
    fmt = logging.Formatter("%(asctime)s | %(levelname)s | %(message)s")
    fh = RotatingFileHandler(LOG_DIR / filename, maxBytes=10 * 1024 * 1024, backupCount=20, encoding="utf-8")
    fh.setFormatter(fmt)
    sh = logging.StreamHandler(sys.stdout)
    sh.setFormatter(fmt)
    log.addHandler(fh)
    log.addHandler(sh)
    log.addHandler(LEVEL_COUNTER)
    return log


def human(size):
    size = float(size)
    for unit in ("B", "KB", "MB", "GB", "TB", "PB"):
        if size < 1024:
            return f"{size:.2f} {unit}"
        size /= 1024
    return f"{size:.2f} EB"


# --------------------------------------------------------------------------- Config
class ConfigError(Exception):
    pass


_UNITS = {"": 1, "B": 1, "K": 1024, "KB": 1024, "M": 1024 ** 2, "MB": 1024 ** 2, "G": 1024 ** 3, "GB": 1024 ** 3,
          "T": 1024 ** 4, "TB": 1024 ** 4, "P": 1024 ** 5, "PB": 1024 ** 5}


def parse_size(value):
    """'2.5T', '800G', '100GB', '1536M' oder eine Zahl (Bytes) -> Bytes; leer/0 -> 0."""
    if value is None:
        return 0
    if isinstance(value, (int, float)):
        return int(value)
    text = str(value).strip().upper().replace(" ", "")
    if not text:
        return 0
    num = text.rstrip("KMGTPB")
    unit = text[len(num):]
    try:
        return int(float(num) * _UNITS[unit])
    except (ValueError, KeyError):
        raise ConfigError(f"Ungültige Grössenangabe: '{value}' (erlaubt z.B. 2.5T, 800G, 100GB)")


def load_config(require_paths=True):
    """Liest die Settings-JSON, ergänzt Defaults und prüft das Nötigste."""
    if not CONFIG_FILE.exists():
        raise ConfigError(f"Konfiguration nicht gefunden: {CONFIG_FILE} – zuerst mediacache_setup.py ausführen")
    try:
        cfg = json.loads(CONFIG_FILE.read_text(encoding="utf-8"))
    except json.JSONDecodeError as e:
        raise ConfigError(f"Konfiguration ist kein gültiges JSON ({CONFIG_FILE}): {e}")
    for key, val in DEFAULTS.items():
        cfg.setdefault(key, copy.deepcopy(val))

    # Umgebungsvariablen überschreiben einzelne Werte
    env_min = os.environ.get("MEDIACACHE_MIN_FREE_PERCENT")
    if env_min:
        cfg["min_free_percent"] = env_min
    env_dbg = os.environ.get("MEDIACACHE_MOVER_DEBUG")
    if env_dbg:
        cfg["mover_debug_level"] = env_dbg
    try:
        cfg["min_free_percent"] = float(cfg["min_free_percent"])
        cfg["mover_debug_level"] = int(cfg["mover_debug_level"])
    except (TypeError, ValueError):
        raise ConfigError("min_free_percent muss eine Zahl, mover_debug_level eine ganze Zahl sein "
                          "(auch MEDIACACHE_MIN_FREE_PERCENT / MEDIACACHE_MOVER_DEBUG prüfen)")
    env_rsync = os.environ.get("MEDIACACHE_RSYNC_ARGS")
    if env_rsync is not None:
        cfg["rsync_args"] = env_rsync.split()
    env_fill = os.environ.get("MEDIACACHE_FILL_TOOL")
    if env_fill:
        cfg["fill_tool"] = env_fill.lower()

    for key in ("cache_path", "array_path", "user_path"):
        cfg[key] = str(cfg[key]).rstrip("/") or "/"

    env_budget = os.environ.get("MEDIACACHE_CACHE_BUDGET")
    if env_budget is not None:
        cfg["cache_budget"] = env_budget

    # valid_users: Liste von IDs oder Dict {id: {"budget": "300G", ...}} -> Liste + feste Budgets
    vu = cfg["valid_users"]
    cfg["user_budgets"] = {}
    if isinstance(vu, dict):
        for uid, opts in vu.items():
            b = parse_size((opts or {}).get("budget")) if isinstance(opts, dict) else 0
            if b > 0:
                cfg["user_budgets"][str(uid)] = b
    cfg["valid_users"] = [str(k) for k in (vu.keys() if isinstance(vu, dict) else vu) if str(k).strip()]
    cfg["cache_budget_bytes"] = parse_size(cfg["cache_budget"])
    for key in ("max_resume_movies", "max_resume_series"):
        if cfg[key] is None:
            cfg[key] = cfg["max_resume_items"]
    try:
        cfg["max_resume_movies"] = int(cfg["max_resume_movies"])
        cfg["max_resume_series"] = int(cfg["max_resume_series"])
    except (TypeError, ValueError):
        raise ConfigError("max_resume_movies und max_resume_series müssen ganze Zahlen sein")
    try:
        cfg["movie_share_percent"] = float(cfg["movie_share_percent"])
        cfg["max_episodes_per_series"] = int(cfg["max_episodes_per_series"])
    except (TypeError, ValueError):
        raise ConfigError("movie_share_percent muss eine Zahl 0–100, max_episodes_per_series eine ganze Zahl sein")
    if not 0 <= cfg["movie_share_percent"] <= 100:
        raise ConfigError("movie_share_percent muss zwischen 0 und 100 liegen")

    if not cfg["instances"]:
        raise ConfigError("Keine Medienserver-Instanz konfiguriert (instances)")
    for i, inst in enumerate(cfg["instances"], 1):
        inst["url"] = str(inst.get("url", "")).rstrip("/")
        inst.setdefault("servername", f"Server{i}")
        inst["type"] = str(inst.get("type") or "emby").lower()
        if inst["type"] not in SERVER_TYPES:
            raise ConfigError(f"Instanz {i} ({inst['servername']}): type muss {' oder '.join(SERVER_TYPES)} sein")
        if inst["type"] == "plex":
            try:
                inst["days_to_monitor"] = int(inst.get("days_to_monitor", 99))
            except (TypeError, ValueError):
                raise ConfigError(f"Instanz {i} ({inst['servername']}): days_to_monitor muss eine ganze Zahl sein")
        if not inst["url"] or not inst.get("api_key"):
            raise ConfigError(f"Instanz {i} ({inst.get('servername')}): url oder api_key fehlt")
        # Pro-Instanz-Mappings (README v6) und globale Mappings zusammenführen
        merged = dict(cfg["path_mappings"])
        merged.update(inst.get("path_mappings") or {})
        inst["_mappings"] = {k.rstrip("/"): v.rstrip("/") for k, v in merged.items() if k and v}
        inst["_skip"] = sorted(k.rstrip("/") for k, v in merged.items() if k and not v)
        if not inst["_mappings"]:
            raise ConfigError(f"Instanz {i} ({inst['servername']}): keine path_mappings – ohne Mapping passiert nichts")

    if require_paths:
        for key in ("cache_path", "array_path"):
            if not Path(cfg[key]).is_dir():
                raise ConfigError(f"{key} existiert nicht: {cfg[key]}")
    if cfg["movie_mode"] not in ("folder", "file"):
        raise ConfigError("movie_mode muss 'folder' oder 'file' sein")
    if cfg["fill_tool"] not in ("rsync", "mover"):
        raise ConfigError("fill_tool muss 'rsync' oder 'mover' sein")
    env_cleanup = os.environ.get("MEDIACACHE_CLEANUP_TOOL")
    if env_cleanup:
        cfg["cleanup_tool"] = env_cleanup.lower()
    if cfg["cleanup_tool"] not in ("rsync", "mover"):
        raise ConfigError("cleanup_tool muss 'rsync' oder 'mover' sein")
    if cfg["array_source"] not in ("user0", "disk"):
        raise ConfigError("array_source muss 'user0' oder 'disk' sein")
    for key, env, minimum in (("parallel_per_disk", "MEDIACACHE_PARALLEL_PER_DISK", 1),
                              ("parallel_total", "MEDIACACHE_PARALLEL_TOTAL", 0),
                              ("status_log_interval", "MEDIACACHE_STATUS_LOG_INTERVAL", 0)):
        if os.environ.get(env):
            cfg[key] = os.environ[env]
        try:
            cfg[key] = int(cfg[key])
        except (TypeError, ValueError):
            raise ConfigError(f"{key} muss eine ganze Zahl sein")
        if cfg[key] < minimum:
            raise ConfigError(f"{key} muss mindestens {minimum} sein")
    cfg["copy_progress"] = bool(cfg["copy_progress"])
    cfg["create_share_root"] = bool(cfg["create_share_root"])
    if not cfg["rsync_args"]:
        raise ConfigError("rsync_args darf nicht leer sein (Original: [\"-aAX\", \"--numeric-ids\"])")
    if not isinstance(cfg["return_to_origin"], bool):
        raise ConfigError("return_to_origin muss true oder false sein")
    return cfg


def save_config(cfg):
    clean = {k: v for k, v in cfg.items() if not k.startswith("_")}
    for inst in clean.get("instances", []):
        inst.pop("_mappings", None)
        inst.pop("_skip", None)
    CONFIG_FILE.parent.mkdir(parents=True, exist_ok=True)
    CONFIG_FILE.write_text(json.dumps(clean, indent=4, ensure_ascii=False) + "\n", encoding="utf-8")


# --------------------------------------------------------------------------- Medienserver-API
SERVER_TYPES = ("emby", "jellyfin", "plex")

# USO: the key goes only to the server's own host. It is an unredirected header (urllib hands only redirected headers on);
# _SameHostRedirect carries it on a redirect to that same host only (the server's own http -> https), never elsewhere.
_KEY_HEADERS = ("X-Emby-Token", "Authorization", "X-Plex-Token")


class _SameHostRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        new = super().redirect_request(req, fp, code, msg, headers, newurl)
        if new is not None and urllib.parse.urlsplit(newurl).hostname == urllib.parse.urlsplit(req.full_url).hostname:
            for name in _KEY_HEADERS:
                key = req.unredirected_hdrs.get(name.capitalize())
                if key:
                    new.add_unredirected_header(name, key)
        return new


_OPENER = urllib.request.build_opener(_SameHostRedirect)


def _key_request(full, headers):
    """USO: a request whose key headers are unredirected (see _SameHostRedirect)."""
    req = urllib.request.Request(full, headers={k: v for k, v in headers.items() if k not in _KEY_HEADERS})
    for k, v in headers.items():
        if k in _KEY_HEADERS:
            req.add_unredirected_header(k, v)
    return req


class MediaServerApi:
    """Minimaler Emby-/Jellyfin-Client auf urllib-Basis. API-Key geht als Header, nicht in die URL.
    Emby: X-Emby-Token. Jellyfin: Authorization: MediaBrowser Token="..." (X-Emby-Token ist dort Legacy).
    Unterschiedliche Endpunkte kapseln user_items() und resume_items()."""

    def __init__(self, url, api_key, timeout=10, server_type="emby"):
        self.url = url.rstrip("/")
        self.api_key = api_key
        self.timeout = timeout
        self.type = (server_type or "emby").lower()
        if self.type == "jellyfin":
            self.headers = {"Authorization": f'MediaBrowser Token="{api_key}"', "Accept": "application/json"}
        else:
            self.headers = {"X-Emby-Token": api_key, "Accept": "application/json"}

    @classmethod
    def for_instance(cls, inst, timeout):
        """Passenden Client für eine Instanz: PlexApi für type=plex, sonst Emby/Jellyfin."""
        if inst.get("type") == "plex":
            return PlexApi(inst["url"], inst["api_key"], timeout, int(inst.get("days_to_monitor", 99)))
        return cls(inst["url"], inst["api_key"], timeout, inst.get("type", "emby"))

    def get(self, path, **params):
        query = urllib.parse.urlencode({k: v for k, v in params.items() if v is not None})
        full = f"{self.url}{path}" + (f"?{query}" if query else "")
        req = _key_request(full, self.headers)   # USO: the key only to the server's own host
        try:
            with _OPENER.open(req, timeout=self.timeout) as resp:
                return json.loads(resp.read().decode("utf-8") or "null")
        except urllib.error.HTTPError as e:
            raise RuntimeError(f"HTTP {e.code} bei {path}") from e
        except (urllib.error.URLError, TimeoutError, OSError) as e:
            raise RuntimeError(f"{path} nicht erreichbar: {e}") from e

    def items(self, path, **params):
        data = self.get(path, **params)
        # USO: an answer without a list is an error, never «nothing on deck» (the cleanup would give everything back)
        if isinstance(data, dict) and isinstance(data.get("Items"), list):
            return data["Items"]
        if isinstance(data, list):
            return data
        raise RuntimeError(f"Ungültige Antwort von {path}")

    def user_items(self, uid, **params):
        """Items aus Sicht eines Benutzers. Jellyfin (ab 10.9): /Items?userId=..., Emby: /Users/{uid}/Items."""
        if self.type == "jellyfin":
            return self.items("/Items", userId=uid, **params)
        return self.items(f"/Users/{uid}/Items", **params)

    def resume_items(self, uid, **params):
        """«Weiterschauen». Jellyfin (ab 10.9): /UserItems/Resume?userId=..., Emby: /Users/{uid}/Items/Resume."""
        if self.type == "jellyfin":
            return self.items("/UserItems/Resume", userId=uid, **params)
        return self.items(f"/Users/{uid}/Items/Resume", **params)

    def next_up(self, uid, **params):
        return self.items("/Shows/NextUp", UserId=uid, **params)

    def users(self):
        """[{"Id", "Name"}]"""
        return self.get("/Users") or []

    def sessions(self):
        """[{"NowPlayingItem": {"Path"}, "UserName"}]"""
        return self.get("/Sessions") or []

    def libraries(self):
        """[{"Name", "Locations": [Docker-Pfade]}]"""
        return self.get("/Library/VirtualFolders") or []


class PlexApi:
    """Plex-Client mit derselben Schnittstelle wie MediaServerApi. Liefert Items im Emby-Format
    (Id, Type, Name, Path, SeriesId, SeriesName, ParentIndexNumber, IndexNumber, UserData.Played),
    damit die Planung unverändert bleibt.

    Logik wie PlexCache (github.com/bexem/PlexCache): Quelle ist On Deck (/library/onDeck) pro Benutzer,
    gefiltert auf lastViewedAt <= days_to_monitor Tage; danach die nächsten Folgen in Serienreihenfolge.
    Benutzer: Admin (Token der Instanz) plus alle Benutzer, mit denen der Server geteilt ist
    (Token via plex.tv shared_servers, wie plexapi MyPlexUser.get_token). Plex hat keine Favoriten
    und kein separates NextUp (steckt im On Deck) – beide liefern leere Listen."""

    PLEX_TV = "https://plex.tv"

    def __init__(self, url, api_key, timeout=10, days_to_monitor=99):
        self.url = url.rstrip("/")
        self.api_key = api_key
        self.timeout = timeout
        self.days_to_monitor = days_to_monitor
        self._tokens = None   # uid -> Server-Token des Benutzers
        self.users_incomplete = False   # USO: plex.tv gab die geteilten Benutzer nicht (siehe _load_users)
        self._names = {}

    def _request(self, full, token, accept_json=True):
        headers = {"X-Plex-Token": token, "X-Plex-Client-Identifier": "mediacache", "X-Plex-Product": "MediaCache"}
        if accept_json:
            headers["Accept"] = "application/json"
        req = _key_request(full, headers)   # USO: the token only to the host it is meant for
        path = urllib.parse.urlsplit(full).path
        try:
            with _OPENER.open(req, timeout=self.timeout) as resp:
                body = resp.read().decode("utf-8")
        except urllib.error.HTTPError as e:
            raise RuntimeError(f"HTTP {e.code} bei {path}") from e
        except (urllib.error.URLError, TimeoutError, OSError) as e:
            raise RuntimeError(f"{path} nicht erreichbar: {e}") from e
        if not accept_json:
            return body
        try:
            return json.loads(body or "null")
        except json.JSONDecodeError as e:
            raise RuntimeError(f"Ungültige Antwort von {path}: {e}") from e

    def get(self, path, token=None, **params):
        query = urllib.parse.urlencode({k: v for k, v in params.items() if v is not None})
        data = self._request(f"{self.url}{path}" + (f"?{query}" if query else ""), token or self.api_key)
        return (data or {}).get("MediaContainer") or {}

    # ------------------------------------------------------------------ Benutzer
    def _load_users(self):
        if self._tokens is not None:
            return
        # Ohne plex.tv (offline) bleibt nur der Admin mit dem Instanz-Token; Ergebnis wird so oder so gecacht
        tokens, names = {"admin": self.api_key}, {"admin": "Admin"}
        try:
            acc = self._request(f"{self.PLEX_TV}/api/v2/user", self.api_key) or {}
            admin_id = str(acc.get("id") or "admin")
            tokens = {admin_id: self.api_key}
            names = {admin_id: acc.get("title") or acc.get("username") or "Admin"}
            mid = self.get("/").get("machineIdentifier")
            if mid:
                xml = self._request(f"{self.PLEX_TV}/api/servers/{mid}/shared_servers", self.api_key, accept_json=False)
                for s in ET.fromstring(xml).iter("SharedServer"):
                    uid, tok = str(s.get("userID") or ""), s.get("accessToken")
                    if uid and tok and uid not in tokens:   # Admin behält sein eigenes Token
                        tokens[uid] = tok
                        names[uid] = s.get("username") or s.get("email") or uid
        except (RuntimeError, ET.ParseError) as e:
            logging.getLogger("MediaCache").warning(f"Plex-Benutzer über plex.tv nicht abrufbar ({e}) – nur Admin")
            # USO: a claimed server whose users plex.tv didn't give is an incomplete plan (the cleanup is skipped),
            # never «the others watch nothing»; an unclaimed (local-only) server has only its admin anyway
            try:
                root = self.get("/")   # signed in to plex.tv (claimed): myPlexSigninState ok, myPlexUsername set; unclaimed: none
                self.users_incomplete = (str(root.get("myPlexSigninState") or "none") != "none") or bool(root.get("myPlexUsername"))
            except RuntimeError:
                self.users_incomplete = True
        self._tokens, self._names = tokens, names

    def users(self):
        self._load_users()
        return [{"Id": uid, "Name": self._names.get(uid, uid)} for uid in self._tokens]

    def _token(self, uid):
        self._load_users()
        tok = self._tokens.get(str(uid))
        if not tok:
            raise RuntimeError(f"Kein Plex-Token für Benutzer {uid} (Server nicht mit ihm geteilt?)")
        return tok

    # ------------------------------------------------------------------ Items
    @staticmethod
    def _path(meta):
        for media in meta.get("Media") or []:
            for part in media.get("Part") or []:
                if part.get("file"):
                    return part["file"]
        return ""

    def _convert(self, meta):
        typ = meta.get("type")
        item = {"Id": str(meta.get("ratingKey", "")), "Name": meta.get("title", "?"), "Path": self._path(meta),
                "UserData": {"Played": bool(meta.get("viewCount"))}}
        if typ == "movie":
            item["Type"] = "Movie"
        elif typ == "episode":
            item.update({"Type": "Episode", "SeriesId": str(meta.get("grandparentRatingKey") or ""),
                         "SeriesName": meta.get("grandparentTitle", "?"),
                         "ParentIndexNumber": meta.get("parentIndex"), "IndexNumber": meta.get("index")})
        elif typ == "show":
            item["Type"] = "Series"
        else:
            item["Type"] = str(typ or "")
        return item

    def resume_items(self, uid, Limit=None, IncludeItemTypes=None, **_):
        """On Deck des Benutzers, nur Einträge der letzten days_to_monitor Tage (wie PlexCache).
        Einträge ohne lastViewedAt (nächste, noch ungesehene Folge) werden nicht gefiltert.
        USO: IncludeItemTypes (Movie | Episode) filtert wie bei Emby – vor dem Limit (getrennte Grenzen)."""
        meta = self.get("/library/onDeck", token=self._token(uid)).get("Metadata") or []
        cutoff = time.time() - self.days_to_monitor * 86400
        want = {"Movie": ("movie",), "Episode": ("episode",)}.get(IncludeItemTypes, ("movie", "episode"))
        out = [self._convert(m) for m in meta
               if m.get("type") in want and (m.get("lastViewedAt") is None or m["lastViewedAt"] >= cutoff)]
        return out[:int(Limit)] if Limit else out

    def user_items(self, uid, ParentId=None, IncludeItemTypes=None, **_):
        """Nur Episoden einer Serie (/library/metadata/{id}/allLeaves). Favoriten-Serien gibt es in Plex nicht."""
        if IncludeItemTypes != "Episode" or not ParentId:
            return []
        meta = self.get(f"/library/metadata/{ParentId}/allLeaves", token=self._token(uid)).get("Metadata") or []
        return [self._convert(m) for m in meta]

    def next_up(self, uid, **_):
        return []

    def sessions(self):
        out = []
        for m in self.get("/status/sessions").get("Metadata") or []:
            out.append({"NowPlayingItem": {"Path": self._path(m)}, "UserName": (m.get("User") or {}).get("title", "?")})
        return out

    def libraries(self):
        return [{"Name": d.get("title", "?"), "Locations": [l.get("path") for l in d.get("Location") or [] if l.get("path")]}
                for d in self.get("/library/sections").get("Directory") or [] if d.get("type") in ("movie", "show")]


# --------------------------------------------------------------------------- Pfade
class Locations:
    """Übersetzt Docker-Pfade in Host-Pfade und findet die Datei auf Cache, Array-Sicht und echter Disk."""

    def __init__(self, cfg, mappings, skip=()):
        self.skip = list(skip)            # Docker-Pfade, die absichtlich nicht gecacht werden
        self.cache = Path(cfg["cache_path"])
        self.array = Path(cfg["array_path"])
        self.user = Path(cfg["user_path"])
        self.disks_glob = cfg["array_disks_glob"]
        self.mappings = mappings
        # Bibliotheks-Wurzeln relativ zum Share (z.B. "Filme") – werden nie als Filmordner behandelt
        self.library_roots = set()
        for host in mappings.values():
            rel = self.to_rel(host)
            if rel is not None:
                self.library_roots.add(rel)

    def to_host(self, docker_path):
        """Längstes passendes Mapping, Prefix nur an Pfadgrenzen."""
        best, repl = "", ""
        for d, h in self.mappings.items():
            if (docker_path == d or docker_path.startswith(d + "/")) and len(d) > len(best):
                best, repl = d, h
        if not best:
            return None
        return repl + docker_path[len(best):]

    def to_rel(self, host_path):
        """Host-Pfad -> Pfad relativ zur Share-Ebene (z.B. /mnt/user/Filme/X/x.mkv -> Filme/X/x.mkv)."""
        p = Path(host_path)
        for root in (self.user, self.array, self.cache):
            try:
                rel = p.relative_to(root)
            except ValueError:
                continue
            if ".." in rel.parts:
                return None   # nie aus der Share hinaus: ein Pfad mit ".." (von Emby oder im Mapping) zählt nicht
            return rel if rel.parts else None
        return None

    def skipped(self, docker_path):
        return any(docker_path == d or docker_path.startswith(d + "/") for d in self.skip)

    def rel_from_docker(self, docker_path):
        host = self.to_host(docker_path)
        return None if host is None else self.to_rel(host)

    def on_cache(self, rel):
        return self.cache / rel

    def on_array(self, rel):
        return self.array / rel

    def on_disk(self, rel):
        """Echte Disk-Pfade (z.B. /mnt/disk3/Filme/...), auf denen die Datei liegt."""
        return [Path(p) for p in sorted(glob.glob(str(Path(self.disks_glob) / rel))) if Path(p).exists()]

    def is_library_root(self, rel):
        return len(rel.parts) <= 1 or rel in self.library_roots

    def list_files(self, rel_dir, recursive):
        """Dateien unter rel_dir – Union aus Array-Sicht und Cache (ersetzt den FUSE-Blick über /mnt/user)."""
        found = set()
        for root in (self.array, self.cache):
            base = root / rel_dir
            if not base.is_dir():
                continue
            it = base.rglob("*") if recursive else base.iterdir()
            for f in it:
                if f.is_file():
                    found.add(f.relative_to(root))
        return sorted(found)


def mkdir_like(dst_dir, src_dir):
    """Legt dst_dir samt fehlender Elternordner an und übernimmt Owner, Gruppe und Rechte vom jeweils
    entsprechenden Quellordner (gleiche relative Struktur). mkdir(parents=True) allein würde root:root
    mit umask-Rechten erzeugen. Bestehende Ordner bleiben unverändert."""
    missing = []
    d, s = Path(dst_dir), Path(src_dir)
    while not d.exists() and d != d.parent:
        missing.append((d, s))
        d, s = d.parent, s.parent
    for d, s in reversed(missing):
        d.mkdir(exist_ok=True)
        try:
            st = s.stat()
            os.chown(d, st.st_uid, st.st_gid)
            os.chmod(d, stat.S_IMODE(st.st_mode))
        except OSError as e:
            logging.getLogger("MediaCache").warning(f"Owner/Rechte für {d} nicht übernommen ({e})")


def remove_empty_parents(path, stop_root, log, min_depth=2):
    """Löscht leere Elternordner von path bis (exklusiv) stop_root; Share-Wurzel (Tiefe 1) bleibt immer stehen."""
    p = Path(path)
    while True:
        try:
            rel = p.relative_to(stop_root)
        except ValueError:
            return
        if len(rel.parts) < min_depth or not p.is_dir():
            return
        try:
            next(p.iterdir())
            return  # nicht leer
        except StopIteration:
            pass
        try:
            p.rmdir()
            log.info(f"Leeren Ordner entfernt: {p}")
        except OSError as e:
            log.warning(f"Leerer Ordner konnte nicht entfernt werden: {p} ({e})")
            return
        p = p.parent


# USO (das Office, 2026-10-12): auf ZFS zählt der ganze Pool. shutil.disk_usage() auf /mnt/<pool> sieht nur das
# Root-Dataset (fast leer), auf einem Share-Dataset nur refer+avail – MediaCache sah ~94 % frei statt 57 %. Darum:
# Grösse = used+avail des Pools (wie das Office und der Smart Mover), frei = avail des Datasets (eine Quota zählt).
ZFS_BIN = os.environ.get("MEDIACACHE_ZFS") or "zfs"            # die Tests: ein Stand-in
MOUNTS_FILE = os.environ.get("MEDIACACHE_MOUNTS") or "/proc/mounts"
_ZFS_DATASET = {}


def _zfs_dataset(path):
    """Das ZFS-Dataset, auf dem `path` liegt (der längste Mountpunkt darüber in /proc/mounts), sonst None."""
    p = os.path.normpath(str(path))
    if p in _ZFS_DATASET:
        return _ZFS_DATASET[p]
    best = None
    try:
        lines = Path(MOUNTS_FILE).read_text(encoding="utf-8", errors="replace").splitlines()
    except OSError:
        lines = []
    for line in lines:
        f = line.split()
        if len(f) < 3:
            continue
        mnt = f[1].replace("\\040", " ")
        if (p == mnt or p.startswith(mnt.rstrip("/") + "/")) and (best is None or len(mnt) > len(best[0])):
            best = (mnt, f[0], f[2])
    ds = best[1] if best and best[2] == "zfs" else None
    _ZFS_DATASET[p] = ds
    return ds


def _zfs_used_avail(name):
    try:
        res = subprocess.run([ZFS_BIN, "list", "-Hp", "-o", "used,avail", name], capture_output=True, text=True, timeout=30)
        used, avail = res.stdout.split()
        return (int(used), int(avail)) if res.returncode == 0 else None
    except (OSError, ValueError, subprocess.SubprocessError):
        return None


def pool_usage(path):
    """(total, free) für `path`: auf ZFS der ganze Pool (frei = avail des Datasets), sonst statvfs (USO)."""
    ds = _zfs_dataset(path)
    if ds:
        pool = _zfs_used_avail(ds.split("/")[0])
        own = _zfs_used_avail(ds) if "/" in ds else pool
        if pool and own and pool[0] + pool[1] > 0:
            return pool[0] + pool[1], min(own[1], pool[1])
    u = shutil.disk_usage(path)
    return u.total, u.free


def free_percent_after(path, size, simulated_delta=0):
    """Freiplatz in Prozent, nachdem `size` Bytes geschrieben wären (bei ZFS-Quota zählt die Quota; USO: auf ZFS
    der ganze Pool, pool_usage()). simulated_delta: im Dry-Run der noch nicht ausgeführte Platzgewinn (Cleanup) minus
    geplante Kopien, damit der Dry-Run denselben Füllstand sieht wie der scharfe Lauf an dieser Stelle."""
    total, free = pool_usage(path)
    if total == 0:
        return 0.0
    return max(0.0, (free + simulated_delta - size) / total * 100.0)


def keep_bytes():
    """USO: MEDIACACHE_KEEP_BYTES – was Jack in jedem Fall behalten darf (seine Hälfte des Platzes unter Frau Moverellis
    Ziel, das Office rechnet sie aus); die Rückgabe nach Freiplatz geht nie darunter. None: kein Boden."""
    v = (os.environ.get("MEDIACACHE_KEEP_BYTES") or "").strip()
    if not v:
        return None
    if not v.isdigit():
        raise ConfigError(f"MEDIACACHE_KEEP_BYTES ist keine ganze Zahl: {v!r}")
    return int(v)


# --------------------------------------------------------------------------- Mover
def _read_share_cfg(path):
    """Gibt {key: value} für shareUseCache/shareCachePool/shareCachePool2 zurück oder None, wenn nicht lesbar."""
    try:
        text = Path(path).read_text(encoding="utf-8", errors="replace")
    except OSError:
        return None
    vals = {}
    for line in text.splitlines():
        line = line.strip()
        for key in ("shareUseCache", "shareCachePool", "shareCachePool2", "shareFloor", "shareInclude", "shareExclude"):
            if line.startswith(key + "="):
                vals[key] = line.split("=", 1)[1].strip().strip('"')
    return vals


def share_mover_mode(cfg, share):
    """Mover-Einstellung eines Shares: (mode, primary, secondary, quelle).
    mode = yes | prefer | only | no | None (nichts gefunden). Quelle = Share-Cfg, Standardwerte oder ''."""
    cfg_dir = Path(cfg["shares_cfg_dir"])
    share_cfg = cfg_dir / f"{share}.cfg"
    vals = _read_share_cfg(share_cfg)
    source = str(share_cfg)
    if not vals or "shareUseCache" not in vals:
        defaults = _read_share_cfg(cfg_dir.parent / "share.cfg")  # /boot/config/share.cfg = Standardwerte neuer Shares
        if defaults and "shareUseCache" in defaults:
            vals = defaults
            source = f"Standardwerte {cfg_dir.parent / 'share.cfg'} (keine {share}.cfg vorhanden)"
        else:
            return None, None, None, ""
    mode = (vals.get("shareUseCache") or "").lower() or None
    primary = vals.get("shareCachePool") or "cache"
    secondary = vals.get("shareCachePool2") or ("array" if mode in ("yes", "prefer") else "keines")
    return mode, primary, secondary, source


MOVER_MODE_HINT = {
    "yes": "Mover Cache → Array – Cleanup über das move-Binary funktioniert",
    "prefer": "Mover Array → Cache: das move-Binary schiebt Dateien dieses Shares immer Richtung Cache – "
              "Cleanup Cache → Array ist damit unmöglich (Share auf «Cache: Yes» stellen)",
    "only": "Cache only: das move-Binary kennt kein Array-Ziel für diesen Share – Cleanup unmöglich",
    "no": "Array only: der reguläre Mover fasst den Share nicht an; ob das move-Binary Pool-Pfade trotzdem "
          "aufs Array schiebt, ist ungetestet – ersten Lauf mit mover_debug_level 1 prüfen",
    None: "keine Share-Konfiguration gefunden – Mover-Richtung unbekannt, Cleanup wird versucht",
}


def share_mode_ok(cfg, share, checked, log):
    """Prüft einmal pro Share, ob das move-Binary Cache → Array kann, und schreibt das Ergebnis ins Log.
    Bei cleanup_tool=rsync immer True (rsync nach /mnt/user0 ist von der Mover-Richtung unabhängig)."""
    if share in checked:
        return checked[share]
    if cfg["cleanup_tool"] == "rsync":
        checked[share] = True
        return True
    mode, primary, secondary, source = share_mover_mode(cfg, share)
    where = f"Primary {primary}, Secondary {secondary}; aus {source}" if mode else "keine Konfiguration gefunden"
    hint = MOVER_MODE_HINT.get(mode, f"unbekannter Wert «{mode}»")
    if mode == "yes":
        log.info(f"Share «{share}»: shareUseCache=yes ({where}) – {hint}")
        checked[share] = True
    elif mode is None or mode == "no":
        log.warning(f"Share «{share}»: shareUseCache={mode or '?'} ({where}) – {hint}")
        checked[share] = True
    else:
        log.error(f"Share «{share}»: shareUseCache={mode} ({where}) – {hint}. "
                  f"Cleanup für diesen Share übersprungen (Alternative: cleanup_tool=rsync).")
        checked[share] = False
    return checked[share]


# --------------------------------------------------------------------------- Share-Wurzel / ZFS
def _unescape_mount(field):
    """/proc/mounts kodiert Leerzeichen, Tab, Newline und Backslash oktal (\\040 usw.)."""
    return re.sub(r"\\([0-7]{3})", lambda m: chr(int(m.group(1), 8)), field)


def read_mounts():
    """[(source, mountpoint, fstype)] aus /proc/mounts; leer, wenn nicht lesbar."""
    try:
        text = Path(MOUNTS_FILE).read_text(encoding="utf-8", errors="replace")
    except OSError:
        return []
    out = []
    for line in text.splitlines():
        parts = line.split()
        if len(parts) >= 3:
            out.append((_unescape_mount(parts[0]), _unescape_mount(parts[1]), parts[2]))
    return out


def mount_of(path, mounts=None):
    """Mount-Eintrag (source, mountpoint, fstype), unter dem path liegt (längster Mountpoint-Prefix), oder None.
    Bei mehrfach gemounteten Pfaden gilt der letzte Eintrag (wie im Kernel)."""
    p = str(path).rstrip("/") or "/"
    best = None
    for src, mp, fs in (read_mounts() if mounts is None else mounts):
        mpn = mp.rstrip("/") or "/"
        if p == mpn or p.startswith(mpn.rstrip("/") + "/"):
            if best is None or len(mpn) >= len(best[1].rstrip("/") or "/"):
                best = (src, mp, fs)
    return best


def zfs_dataset_exists(name):
    """True, wenn `zfs list` das Dataset kennt."""
    try:
        r = subprocess.run([ZFS_BIN, "list", "-H", "-o", "name", name], capture_output=True, text=True, timeout=60)
    except (OSError, subprocess.SubprocessError):
        return False
    return r.returncode == 0 and r.stdout.strip() == name


def zfs_create(name):
    """`zfs create <name>` – Eigenschaften (auch mountpoint) werden vom Pool geerbt, wie bei Unraids shfs.
    Gibt (ok, meldung) zurück."""
    try:
        r = subprocess.run([ZFS_BIN, "create", name], capture_output=True, text=True, timeout=120)
    except (OSError, subprocess.SubprocessError) as e:
        return False, str(e)
    return r.returncode == 0, (r.stderr or r.stdout).strip()


def copy_owner_mode(dst, src, log):
    """Owner, Gruppe und Rechte von src auf dst übernehmen (wie mkdir_like für einen bestehenden Ordner)."""
    try:
        st = Path(src).stat()
        os.chown(dst, st.st_uid, st.st_gid)
        os.chmod(dst, stat.S_IMODE(st.st_mode))
    except OSError as e:
        log.warning(f"Owner/Rechte für {dst} nicht übernommen ({e})")


SHARE_ROOT_OK_MODES = ("yes", "prefer", "only")


def ensure_share_root(cfg, cache, array, share, run_mode, log, state):
    """Sorgt dafür, dass die Share-Wurzel /mnt/<pool>/<share> auf dem Pool existiert, so wie Unraid sie anlegt.
      ZFS:       fehlt sie, wird das Dataset <pool-dataset>/<share> angelegt (zfs create, Eigenschaften geerbt), danach
                 Owner/Rechte wie /mnt/user0/<share>. Ein bestehender normaler Ordner wird nie umgebaut – nur einmal gewarnt.
                 Angelegt wird nur, wenn der Share laut Share-Einstellung auf diesen Pool darf (shareCachePool = Pool,
                 shareUseCache yes/prefer/only).
      btrfs/XFS: mkdir_like (Unraid legt dort auch nur einen Ordner an).
    Nie zfs destroy/rename, nie bestehende Daten anfassen. Im Dry-Run wird nur gemeldet.
    state: dict pro Lauf ({share: True/False}), damit jede Meldung nur einmal erscheint.
    Rückgabe: True = Wurzel existiert (Dry-Run: würde angelegt), False = Dateien dieses Shares überspringen."""
    if share in state:
        return state[share]
    cache, array = Path(cache), Path(array)
    root = cache / share
    mounts = read_mounts()
    pool_mount = mount_of(cache, mounts)
    fstype = pool_mount[2] if pool_mount else "?"

    if root.is_dir():
        if fstype == "zfs":
            own = mount_of(root, mounts)
            if not own or own[2] != "zfs" or (own[1].rstrip("/") != str(root).rstrip("/")):
                dataset = f"{pool_mount[0]}/{share}"
                if zfs_dataset_exists(dataset):
                    log.error(f"Dataset {dataset} existiert, ist aber nicht unter {root} eingehängt – Kopien landeten im "
                              f"Pool-Dataset statt im Share-Dataset. MediaCache ändert daran nichts (zfs mount prüfen), "
                              f"Dateien des Shares «{share}» übersprungen")
                    state[share] = False
                    return False
                log.warning(f"Share-Wurzel {root} ist auf dem ZFS-Pool ein normaler Ordner im Dataset "
                            f"{pool_mount[0]}, kein eigenes Dataset (keine eigenen Snapshots/Quota/Backups je Share; "
                            f"Unraid wandelt ihn nie um). MediaCache baut nichts um. Lösung: den Teil des Shares auf "
                            f"{cache} leeren lassen (Mover bzw. MediaCache-Cleanup) und den leeren Ordner entfernen – "
                            f"der nächste Lauf legt dann das Dataset {pool_mount[0]}/{share} an.")
        state[share] = True
        return True

    if not cfg.get("create_share_root", True):
        log.error(f"Share-Wurzel {root} fehlt und create_share_root=false – Dateien des Shares «{share}» übersprungen")
        state[share] = False
        return False

    if fstype not in ("zfs", "btrfs", "xfs"):
        log.error(f"Dateisystem von {cache} unbekannt ({fstype}; Pool nicht eingehängt oder {MOUNTS_FILE} nicht lesbar) – "
                  f"Share-Wurzel {root} nicht angelegt, Dateien des Shares «{share}» übersprungen")
        state[share] = False
        return False
    if fstype != "zfs":
        if run_mode:
            mkdir_like(root, array / share)
            log.info(f"Share-Wurzel als Ordner angelegt ({fstype}): {root}")
        else:
            log.info(f"PLAN: würde Ordner {root} anlegen ({fstype})")
        state[share] = True
        return True

    pool = cache.name
    mode, primary, _secondary, source = share_mover_mode(cfg, share)
    if mode not in SHARE_ROOT_OK_MODES or primary != pool:
        why = (f"keine Share-Konfiguration gefunden" if mode is None
               else f"shareUseCache={mode}, shareCachePool={primary} (aus {source})")
        log.error(f"Share «{share}» darf laut Share-Einstellung nicht auf den Pool «{pool}» ({why}) – "
                  f"kein Dataset angelegt, Dateien dieses Shares übersprungen. Share in Unraid auf Primary storage "
                  f"«{pool}» stellen oder Share aus den path_mappings nehmen.")
        state[share] = False
        return False

    dataset = f"{pool_mount[0]}/{share}"
    if zfs_dataset_exists(dataset):
        log.error(f"Dataset {dataset} existiert, ist aber nicht unter {root} eingehängt (Mountpoint/canmount prüfen) – "
                  f"MediaCache ändert daran nichts, Dateien des Shares «{share}» übersprungen")
        state[share] = False
        return False
    if not run_mode:
        log.info(f"PLAN: würde Dataset {dataset} anlegen (Mountpoint {root}, Owner/Rechte wie {array / share})")
        state[share] = True
        return True
    ok, msg = zfs_create(dataset)
    if not ok:
        log.error(f"zfs create {dataset} fehlgeschlagen: {msg} – Dateien des Shares «{share}» übersprungen")
        state[share] = False
        return False
    if not root.is_dir():
        log.error(f"Dataset {dataset} angelegt, aber nicht unter {root} eingehängt (Mountpoint des Pools prüfen) – "
                  f"Dateien des Shares «{share}» übersprungen")
        state[share] = False
        return False
    copy_owner_mode(root, array / share, log)
    log.info(f"Dataset {dataset} angelegt ({root}), Owner/Rechte wie {array / share}")
    state[share] = True
    return True


def summarize_mover_output(stdout, paths):
    """Ordnet jeder Datei die Mover-Meldung zu (z.B. 'No space left on device'); {path: message}."""
    result = {}
    for line in stdout.splitlines():
        for p in paths:
            if p in line and p not in result:
                msg = line.split(p, 1)[1].strip(" :-")
                result[p] = msg or line.strip()
    return result


def detect_mover_bin(cfg):
    if cfg.get("mover_bin"):
        return cfg["mover_bin"]
    for cand in MOVER_CANDIDATES:
        if os.path.exists(cand):
            return cand
    return None


def unraid_mover_running():
    """True, wenn der reguläre Unraid-Mover (mover-Script) gerade läuft."""
    try:
        pid = int(Path(MOVER_PIDFILE).read_text().strip())
    except (OSError, ValueError):
        return False
    return Path(f"/proc/{pid}").exists()


def run_mover(mover_bin, paths, debug_level, log):
    """Pipt Dateipfade ins Unraid move-Binary (Pool -> Array). Liefert (returncode, stdout, stderr)."""
    cmd = [mover_bin]
    if int(debug_level) > 0:
        cmd += ["-d", str(int(debug_level))]
    log.debug(f"Mover-Aufruf: {' '.join(cmd)} mit {len(paths)} Pfaden")
    try:
        proc = subprocess.run(cmd, input="\n".join(paths) + "\n", capture_output=True, text=True)
    except OSError as e:
        log.error(f"move-Binary konnte nicht gestartet werden ({mover_bin}): {e}")
        return 1, "", str(e)
    for line in proc.stdout.splitlines():
        log.debug(f"mover: {line}")
    for line in proc.stderr.splitlines():
        log.debug(f"mover: {line}")
    if proc.returncode != 0:
        log.error(f"move-Binary endete mit Code {proc.returncode}")
    return proc.returncode, proc.stdout, proc.stderr


# --------------------------------------------------------------------------- rsync / Status
_PROGRESS_RE = re.compile(r"^\s*([\d,.']+)\s+(\d+)%\s+(\S+/s)\s+(\d+:\d+:\d+)")


def run_rsync(cmd, on_progress=None):
    """Startet rsync, liest stdout+stderr laufend und meldet --info=progress2-Zeilen an on_progress(bytes, speed, eta).
    Liefert (returncode, Meldungen ohne Fortschrittszeilen)."""
    try:
        proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    except OSError as e:
        return 127, str(e)
    messages = []

    def handle(raw):
        line = raw.decode("utf-8", errors="replace").strip()
        if not line:
            return
        m = _PROGRESS_RE.match(line)
        if m:
            if on_progress:
                on_progress(int(re.sub(r"\D", "", m.group(1)) or 0), m.group(3), m.group(4))
        else:
            messages.append(line)

    buf = b""
    while True:
        chunk = proc.stdout.read1(65536)
        if not chunk:
            break
        parts = re.split(rb"[\r\n]", buf + chunk)
        buf = parts.pop()
        for part in parts:
            handle(part)
    handle(buf)
    return proc.wait(), "\n".join(messages)


def _fmt_duration(seconds):
    seconds = int(max(0, seconds))
    return f"{seconds // 3600}:{seconds % 3600 // 60:02d}:{seconds % 60:02d}"


class Status:
    """Live-Status des Laufs: schreibt mediacache_status.json (atomar, höchstens alle 2 s) und auf Wunsch
    periodisch eine Fortschrittszeile ins Log. Thread-sicher (Kopien laufen parallel)."""

    def __init__(self, mode, log, enabled=True, log_interval=60):
        self.log = log
        self.enabled = enabled
        self.log_interval = log_interval
        self._lock = threading.Lock()
        self._stop = threading.Event()
        self._thread = None
        self._dirty = True
        self._last_log = time.time()
        self.data = {"pid": os.getpid(), "version": __version__, "mode": mode, "state": "läuft",
                     "started": time.time(), "updated": time.time(), "phase": "Start", "phase_started": time.time(),
                     "files_total": 0, "bytes_total": 0, "files_done": 0, "files_failed": 0, "bytes_done": 0,
                     "jobs": {}, "recent": []}
        if self.enabled:
            self._write()
            self._thread = threading.Thread(target=self._ticker, name="status", daemon=True)
            self._thread.start()

    def phase(self, name, files=0, size=0):
        with self._lock:
            self.data.update(phase=name, phase_started=time.time(), files_total=files, bytes_total=size,
                             files_done=0, files_failed=0, bytes_done=0, jobs={})
            self._dirty = True
            self._last_log = time.time()

    def job_start(self, key, rel, disk, size, index):
        with self._lock:
            self.data["jobs"][key] = {"rel": str(rel), "disk": disk, "size": size, "done": 0, "speed": "",
                                      "eta": "", "started": time.time(), "index": index}
            self._dirty = True

    def job_progress(self, key, done, speed="", eta=""):
        with self._lock:
            job = self.data["jobs"].get(key)
            if job:
                job.update(done=min(done, job["size"]) if job["size"] else done, speed=speed, eta=eta)
                self._dirty = True

    def job_end(self, key, ok):
        with self._lock:
            job = self.data["jobs"].pop(key, None)
            if not job:
                return
            if ok:
                self.data["files_done"] += 1
                self.data["bytes_done"] += job["size"]
            else:
                self.data["files_failed"] += 1
            self.data["recent"] = ([{"rel": job["rel"], "disk": job["disk"], "size": job["size"], "ok": ok,
                                     "seconds": round(time.time() - job["started"], 1)}] + self.data["recent"])[:10]
            self._dirty = True

    def finish(self, state="fertig"):
        self._stop.set()
        if self._thread:
            self._thread.join(timeout=5)
        with self._lock:
            self.data.update(state=state, phase="Ende", jobs={}, finished=time.time())
            self._dirty = True
            if self.enabled:
                self._write()

    def summary(self):
        """Eine Zeile für das Log: Phase, Dateien, Bytes, aktive Kopien."""
        d = self.data
        done = d["bytes_done"] + sum(j["done"] for j in d["jobs"].values())
        pct = f" ({done / d['bytes_total'] * 100:.0f} %)" if d["bytes_total"] else ""
        elapsed = time.time() - d["phase_started"]
        rate = done / elapsed if elapsed > 0 else 0
        eta = f", Rest ca. {_fmt_duration((d['bytes_total'] - done) / rate)}" if rate > 0 and d["bytes_total"] > done else ""
        active = ", ".join(f"{j['disk']}: {Path(j['rel']).name} {j['done'] * 100 // j['size'] if j['size'] else 0} %"
                           for j in d["jobs"].values())
        return (f"Fortschritt {d['phase']}: {d['files_done']}/{d['files_total']} Dateien, {human(done)} von "
                f"{human(d['bytes_total'])}{pct}, {human(rate)}/s{eta}"
                + (f", {d['files_failed']} Fehler" if d["files_failed"] else "")
                + (f" | aktiv ({len(d['jobs'])}): {active}" if active else ""))

    def _ticker(self):
        while not self._stop.wait(2):
            with self._lock:
                if self._dirty:
                    self._write()
                if self.log_interval and self.data["jobs"] and time.time() - self._last_log >= self.log_interval:
                    self._last_log = time.time()
                    self.log.info(self.summary())

    def _write(self):
        """Nur unter self._lock bzw. vor dem Start des Tickers aufrufen."""
        self.data["updated"] = time.time()
        self._dirty = False
        try:
            tmp = STATUS_FILE.with_suffix(".tmp")
            tmp.write_text(json.dumps(self.data, ensure_ascii=False, indent=1), encoding="utf-8")
            os.replace(tmp, STATUS_FILE)
        except OSError as e:
            self.log.debug(f"Status-Datei nicht schreibbar ({STATUS_FILE}): {e}")


def format_status():
    """Text für --status aus mediacache_status.json."""
    try:
        d = json.loads(STATUS_FILE.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return f"Kein Status vorhanden ({STATUS_FILE}) – noch kein Lauf mit --run"
    ts = lambda t: datetime.datetime.fromtimestamp(t).strftime("%Y-%m-%d %H:%M:%S")
    alive = Path(f"/proc/{d.get('pid')}").exists()
    state = d.get("state", "?")
    if state == "läuft" and not alive:
        state = "abgebrochen (Prozess existiert nicht mehr)"
    lines = [f"MediaCache {d.get('version', '')} – Status: {state}, PID {d.get('pid')}, Modus {str(d.get('mode', '')).upper()}",
             f"Start {ts(d['started'])}, letzte Aktualisierung {ts(d['updated'])}"
             + (f", beendet {ts(d['finished'])}" if d.get("finished") else "")]
    jobs = d.get("jobs") or {}
    done = d.get("bytes_done", 0) + sum(j.get("done", 0) for j in jobs.values())
    total = d.get("bytes_total", 0)
    lines.append(f"Phase: {d.get('phase')}  –  {d.get('files_done', 0)}/{d.get('files_total', 0)} Dateien, "
                 f"{human(done)} von {human(total)}" + (f" ({done / total * 100:.0f} %)" if total else "")
                 + (f", {d['files_failed']} Fehler" if d.get("files_failed") else ""))
    if jobs:
        lines.append(f"Aktiv ({len(jobs)}):")
        for j in sorted(jobs.values(), key=lambda x: x.get("index", 0)):
            pct = j["done"] * 100 // j["size"] if j.get("size") else 0
            lines.append(f"  [{j.get('index', '?')}/{d.get('files_total', 0)}] {j['disk']:<8} {pct:>3} %  "
                         f"{human(j['done']):>10} / {human(j['size']):<10} {j.get('speed') or '':>12}  "
                         f"Rest {j.get('eta') or '?':<8} {j['rel']}")
    if d.get("recent"):
        lines.append("Zuletzt fertig:")
        for r in d["recent"]:
            lines.append(f"  {'ok    ' if r['ok'] else 'FEHLER'} {r['disk']:<8} {human(r['size']):>10}  "
                         f"{r['seconds']:>7.1f} s  {r['rel']}")
    return "\n".join(lines)


# --------------------------------------------------------------------------- Lock / Exclude
def acquire_lock(log):
    """Exklusives flock auf mediacache.lock; None, wenn bereits ein Lauf aktiv ist."""
    fh = open(LOCK_FILE, "w")
    try:
        fcntl.flock(fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        fh.close()
        log.warning(f"Ein anderer MediaCache-Lauf ist aktiv ({LOCK_FILE}) – Abbruch")
        return None
    fh.write(str(os.getpid()))
    fh.flush()
    return fh


def read_exclude():
    if not EXCLUDE_FILE.exists():
        return set()
    return {line.strip() for line in EXCLUDE_FILE.read_text(encoding="utf-8").splitlines() if line.strip()}


def write_exclude(paths):
    """Atomar schreiben (temp + rename), damit Mover Tuning nie eine halbe Liste liest."""
    tmp = EXCLUDE_FILE.with_suffix(".tmp")
    tmp.write_text("\n".join(sorted(set(paths))) + ("\n" if paths else ""), encoding="utf-8")
    os.replace(tmp, EXCLUDE_FILE)


def collect_sessions(cfg, log):
    """Gerade abgespielte Dateien aller Instanzen: relative Pfade plus Dateinamen (Fallback ohne Mapping).
    Liefert ((rels, names), ok) – ok=False, wenn eine Instanz nicht geantwortet hat."""
    rels, names = set(), set()
    ok = True
    for inst in cfg["instances"]:
        api = MediaServerApi.for_instance(inst, cfg["api_timeout"])
        loc = Locations(cfg, inst["_mappings"], inst["_skip"])
        try:
            sessions = api.sessions()
        except RuntimeError as e:
            log.warning(f"[{inst['servername']}] Sessions nicht abrufbar: {e}")
            ok = False
            continue
        for s in sessions:
            item = s.get("NowPlayingItem") or {}
            p = item.get("Path")
            if not p:
                continue
            names.add(os.path.basename(p))
            rel = loc.rel_from_docker(p)
            if rel is not None:
                rels.add(str(rel))
                log.info(f"[{inst['servername']}] Läuft gerade ({s.get('UserName', '?')}): {rel}")
    return (rels, names), ok


def is_playing(rel, sessions):
    rels, names = sessions
    return str(rel) in rels or Path(rel).name in names


# --------------------------------------------------------------------------- Herkunft / Status
def array_location(path):
    """Array-Disk einer Datei (z.B. 'disk3'): aus dem Pfad (/mnt/diskN/...) oder über das shfs-Attribut
    system.LOCATION der Array-Sicht (/mnt/user0/...). None, wenn es keine Array-Disk ist oder unbekannt."""
    parts = Path(path).parts
    name = parts[2] if len(parts) > 2 and parts[1] == "mnt" else ""
    if not name.startswith("disk") or not name[4:].isdigit():
        try:
            name = os.getxattr(str(path), "system.LOCATION").decode("utf-8", "replace").strip().strip("\0")
        except (OSError, AttributeError):   # AttributeError: no xattrs on this system (not Linux)
            return None
    return name if name.startswith("disk") and name[4:].isdigit() else None


def read_origin():
    """{Cache-Pfad: 'diskN'} – woher die Dateien auf dem Cache kamen."""
    try:
        data = json.loads(ORIGIN_FILE.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return {str(k): str(v) for k, v in data.items() if isinstance(v, str)} if isinstance(data, dict) else {}


def write_origin(origin):
    tmp = ORIGIN_FILE.with_suffix(".tmp")
    tmp.write_text(json.dumps(dict(sorted(origin.items())), indent=1, ensure_ascii=False) + "\n", encoding="utf-8")
    os.replace(tmp, ORIGIN_FILE)


def origin_target(cfg, rel, disk, size):
    """Ziel /mnt/diskN/<rel> für die Rückkehr an die Herkunfts-Disk – oder (None, Grund).
    Prüft: echte Disk eingehängt, Share-Wurzel dort vorhanden, Disk für den Share erlaubt (Include/Exclude),
    danach bleibt mindestens der Mindest-Freiplatz des Shares (shareFloor, in KB) frei."""
    root = Path(cfg["array_disks_glob"]).parent / disk
    if not os.path.ismount(root):
        return None, f"{root} ist nicht eingehängt"
    share = Path(rel).parts[0]
    vals = _read_share_cfg(Path(cfg["shares_cfg_dir"]) / f"{share}.cfg") or {}
    include = [d for d in (vals.get("shareInclude") or "").split(",") if d]
    exclude = [d for d in (vals.get("shareExclude") or "").split(",") if d]
    if (include and disk not in include) or disk in exclude:
        return None, f"{disk} gehört nicht (mehr) zum Share «{share}»"
    if not (root / share).is_dir():
        return None, f"«{share}» liegt nicht (mehr) auf {disk}"
    try:
        floor = int(vals.get("shareFloor") or 0) * 1024
    except ValueError:
        floor = 0
    if shutil.disk_usage(root).free - size < floor:
        return None, f"{disk} zu voll (Mindest-Freiplatz des Shares)"
    return root / rel, ""


def write_result(data):
    """Ergebnis eines Laufs nach $MEDIACACHE_RESULT (atomar), wenn gesetzt."""
    if not RESULT_FILE:
        return
    data = dict(data, version=__version__, warnings=LEVEL_COUNTER.counts["WARNING"], errors=LEVEL_COUNTER.counts["ERROR"])
    try:
        tmp = Path(RESULT_FILE + ".tmp")
        tmp.write_text(json.dumps(data, ensure_ascii=False) + "\n", encoding="utf-8")
        os.replace(tmp, RESULT_FILE)
    except OSError:
        pass


def write_progress(data):
    """Fortschritt des scharfen Laufs nach $MEDIACACHE_PROGRESS (atomar: neue Datei, dann umbenennen), wenn gesetzt."""
    if not PROGRESS_FILE:
        return
    try:
        tmp = f"{PROGRESS_FILE}.{os.getpid()}.tmp"
        fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            f.write(json.dumps(data, ensure_ascii=False) + "\n")
        os.replace(tmp, PROGRESS_FILE)
    except OSError:
        pass
