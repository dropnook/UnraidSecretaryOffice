#!/usr/bin/env python3
"""
EmbyCache – gemeinsame Funktionen für embycache_run.py, embycache_cleaner.py und embycache_setup.py.

Enthält: Config mit Defaults und Validierung, Pfad-Übersetzung (Docker → Host → relativ zum Share),
Emby-API-Client (nur Standardbibliothek, kein `requests`), Aufruf des Unraid move-Binaries,
Lockfile, Exclude-Liste und Logging.

Umgebungsvariablen (gelten für alle Scripte):
  EMBYCACHE_DIR        Arbeitsverzeichnis für Settings, Exclude-Liste, Lock und logs/
                       Default: das Verzeichnis, in dem die Scripte liegen
  EMBYCACHE_CONFIG     Pfad zur Settings-JSON; Default: $EMBYCACHE_DIR/embycache_settings.json
  EMBYCACHE_LOG_LEVEL  DEBUG | INFO | WARNING; Default INFO
  EMBYCACHE_STATUS     optional: JSON-Datei, in die embycache_run.py am Ende das Ergebnis schreibt
                       (Modus, Zähler, Fehler) – für Programme, die EmbyCache starten (Unraid Secretary Office)
  EMBYCACHE_PROGRESS   optional: JSON-Datei, die embycache_run.py im scharfen Lauf an jeder Dateigrenze neu schreibt
                       (Phase, pro Benutzer geplant/erledigt, die Datei in Arbeit) – für eine Fortschrittsanzeige
"""
import copy
import fcntl
import glob
import json
import logging
import os
import shutil
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from logging.handlers import RotatingFileHandler
from pathlib import Path

__version__ = "7.3.0 (2026-10-04)"

BASE_DIR = Path(os.environ.get("EMBYCACHE_DIR") or Path(__file__).resolve().parent)
CONFIG_FILE = Path(os.environ.get("EMBYCACHE_CONFIG") or (BASE_DIR / "embycache_settings.json"))
EXCLUDE_FILE = BASE_DIR / "embycache_exclude.txt"
LOCK_FILE = BASE_DIR / "embycache.lock"
LOG_DIR = BASE_DIR / "logs"
ORIGIN_FILE = BASE_DIR / "embycache_origin.json"   # Cache-Pfad -> Array-Disk, von der die Datei kam
STATUS_FILE = os.environ.get("EMBYCACHE_STATUS") or ""
PROGRESS_FILE = os.environ.get("EMBYCACHE_PROGRESS") or ""

MOVER_CANDIDATES = ["/usr/libexec/unraid/move", "/usr/local/sbin/move", "/usr/local/bin/move"]
MOVER_PIDFILE = "/var/run/mover.pid"

# Alle Config-Schlüssel mit Default. Unbekannte Schlüssel werden ignoriert, fehlende ergänzt.
DEFAULTS = {
    "cache_path": "/mnt/cache",          # Pool, auf den gecacht wird (z.B. /mnt/cache oder /mnt/maple)
    "array_path": "/mnt/user0",          # Array-Sicht ohne Pools (FUSE)
    "user_path": "/mnt/user",            # Zusammengeführte Sicht (Emby sieht diese Pfade)
    "array_disks_glob": "/mnt/disk[0-9]*",  # Echte Array-Disks (nur für array_source = disk)
    "array_source": "user0",             # rsync-Quelle: user0 = /mnt/user0/... wie das Original, disk = echte /mnt/diskN-Pfade
    "instances": [],                     # [{"servername": "...", "url": "...", "api_key": "...", "path_mappings": {...}}]
    "path_mappings": {},                 # Globales Mapping Docker-Pfad -> Host-Pfad (/mnt/user/...);
                                         #   ein leerer Host-Pfad heisst: diesen Ordner absichtlich nicht cachen
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
    "use_next_up": True,                 # Auch Embys «Als Nächstes» (/Shows/NextUp) als Quelle – hält Serien zwischen zwei Folgen im Cache
    "min_free_percent": 20,              # Unter diesem Freiplatz (Prozent) wird nichts mehr auf den Cache kopiert
    "movie_mode": "folder",              # folder = ganzer Filmordner mitnehmen, file = nur Dateien mit gleichem Namen
    "create_share_root": False,          # Share-Wurzel auf dem Pool automatisch anlegen (ZFS: besser vorher als Dataset!)
    "mover_bin": "",                     # Leer = automatisch erkennen
    "mover_debug_level": 0,              # Parameter -d für das move-Binary (0 = still, 1..3 = ausführlicher)
    "rsync_args": ["-aAX", "--numeric-ids"],  # Vollständige rsync-Optionen (Default = wie das Original)
    "fill_tool": "rsync",                # Array -> Cache: rsync (kopieren + Quelle löschen) oder mover (Unraid move-Binary)
    "cleanup_tool": "mover",             # Cache -> Array: mover (Unraid move-Binary, wie das Original) oder rsync
                                         #   (rsync /mnt/<pool>/<rel> -> /mnt/user0/<rel>, unabhängig von der Mover-Richtung des Shares)
    "return_to_origin": True,            # Cache -> Array: zuerst zurück auf die Disk, von der die Datei kam (embycache_origin.json),
                                         #   per rsync nach /mnt/diskN/<rel>; nur wenn das nicht geht, cleanup_tool.
                                         #   Beim Befüllen bleibt der (leere) Ordner auf der Disk dann stehen – als Wegweiser
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
    """Rotierendes Logfile (10 MB x 20) plus Konsole. Level über EMBYCACHE_LOG_LEVEL."""
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    level = getattr(logging, os.environ.get("EMBYCACHE_LOG_LEVEL", "INFO").upper(), logging.INFO)
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
        raise ConfigError(f"Konfiguration nicht gefunden: {CONFIG_FILE} – zuerst embycache_setup.py ausführen")
    try:
        cfg = json.loads(CONFIG_FILE.read_text(encoding="utf-8"))
    except json.JSONDecodeError as e:
        raise ConfigError(f"Konfiguration ist kein gültiges JSON ({CONFIG_FILE}): {e}")
    for key, val in DEFAULTS.items():
        cfg.setdefault(key, copy.deepcopy(val))

    # Umgebungsvariablen überschreiben einzelne Werte
    env_min = os.environ.get("EMBYCACHE_MIN_FREE_PERCENT")
    if env_min:
        cfg["min_free_percent"] = float(env_min)
    env_dbg = os.environ.get("EMBYCACHE_MOVER_DEBUG")
    if env_dbg:
        cfg["mover_debug_level"] = int(env_dbg)
    env_rsync = os.environ.get("EMBYCACHE_RSYNC_ARGS")
    if env_rsync is not None:
        cfg["rsync_args"] = env_rsync.split()
    env_fill = os.environ.get("EMBYCACHE_FILL_TOOL")
    if env_fill:
        cfg["fill_tool"] = env_fill.lower()

    for key in ("cache_path", "array_path", "user_path"):
        cfg[key] = str(cfg[key]).rstrip("/") or "/"

    env_budget = os.environ.get("EMBYCACHE_CACHE_BUDGET")
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
        raise ConfigError("Keine Emby-Instanz konfiguriert (instances)")
    for i, inst in enumerate(cfg["instances"], 1):
        inst["url"] = str(inst.get("url", "")).rstrip("/")
        inst.setdefault("servername", f"Server{i}")
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
    env_cleanup = os.environ.get("EMBYCACHE_CLEANUP_TOOL")
    if env_cleanup:
        cfg["cleanup_tool"] = env_cleanup.lower()
    if cfg["cleanup_tool"] not in ("rsync", "mover"):
        raise ConfigError("cleanup_tool muss 'rsync' oder 'mover' sein")
    if cfg["array_source"] not in ("user0", "disk"):
        raise ConfigError("array_source muss 'user0' oder 'disk' sein")
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


# --------------------------------------------------------------------------- Emby API
class EmbyApi:
    """Minimaler Emby-Client auf urllib-Basis. API-Key geht als Header X-Emby-Token, nicht in die URL."""

    def __init__(self, url, api_key, timeout=10):
        self.url = url.rstrip("/")
        self.api_key = api_key
        self.timeout = timeout

    def get(self, path, **params):
        query = urllib.parse.urlencode({k: v for k, v in params.items() if v is not None})
        full = f"{self.url}{path}" + (f"?{query}" if query else "")
        req = urllib.request.Request(full, headers={"X-Emby-Token": self.api_key, "Accept": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                return json.loads(resp.read().decode("utf-8") or "null")
        except urllib.error.HTTPError as e:
            raise RuntimeError(f"HTTP {e.code} bei {path}") from e
        except (urllib.error.URLError, TimeoutError, OSError) as e:
            raise RuntimeError(f"{path} nicht erreichbar: {e}") from e

    def items(self, path, **params):
        data = self.get(path, **params)
        if isinstance(data, dict):
            return data.get("Items", [])
        return data or []


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


def free_percent_after(path, size, simulated_delta=0):
    """Freiplatz in Prozent, nachdem `size` Bytes geschrieben wären (bei ZFS-Quota zählt die Quota).
    simulated_delta: im Dry-Run der noch nicht ausgeführte Platzgewinn (Cleanup) minus geplante Kopien,
    damit der Dry-Run denselben Füllstand sieht wie der scharfe Lauf an dieser Stelle."""
    u = shutil.disk_usage(path)
    if u.total == 0:
        return 0.0
    return max(0.0, (u.free + simulated_delta - size) / u.total * 100.0)


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


# --------------------------------------------------------------------------- Lock / Exclude
def acquire_lock(log):
    """Exklusives flock auf embycache.lock; None, wenn bereits ein Lauf aktiv ist."""
    fh = open(LOCK_FILE, "w")
    try:
        fcntl.flock(fh, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        fh.close()
        log.warning(f"Ein anderer EmbyCache-Lauf ist aktiv ({LOCK_FILE}) – Abbruch")
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
        api = EmbyApi(inst["url"], inst["api_key"], cfg["api_timeout"])
        loc = Locations(cfg, inst["_mappings"])
        try:
            sessions = api.get("/Sessions") or []
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
        except OSError:
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


def write_status(data):
    """Ergebnis eines Laufs nach $EMBYCACHE_STATUS (atomar), wenn gesetzt."""
    if not STATUS_FILE:
        return
    data = dict(data, version=__version__, warnings=LEVEL_COUNTER.counts["WARNING"], errors=LEVEL_COUNTER.counts["ERROR"])
    try:
        tmp = Path(STATUS_FILE + ".tmp")
        tmp.write_text(json.dumps(data, ensure_ascii=False) + "\n", encoding="utf-8")
        os.replace(tmp, STATUS_FILE)
    except OSError:
        pass


def write_progress(data):
    """Fortschritt des scharfen Laufs nach $EMBYCACHE_PROGRESS (atomar: neue Datei, dann umbenennen), wenn gesetzt."""
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
