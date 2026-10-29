> **In the Unraid Secretary Office** this is the MediaCache Jack Streamer looks after (Emby, Jellyfin and Plex, several
> servers at once): taken from [helmi1987/mediacache-for-unraid](https://github.com/helmi1987/mediacache-for-unraid)
> (main `2a91ef1`, 7.4.0, 2026-10-15), with the office's changes merged in from its earlier EmbyCache copy (embycache 7.3.0
> of the office, itself from helmi1987's embycache 7.2.1). Jack Streamer sets it up, runs and schedules it; its data lives in
> `data/mediacache` (`MEDIACACHE_DIR`). Every change is marked `USO` in the code. Shipped: `mediacache_lib.py`,
> `mediacache_run.py` (+ his `tests/`, not in the package). Not shipped: `mediacache_setup.py` (Jack is the setup) and
> `mediacache_cleaner.py` (on a share whose primary is the pool it would take every new film for an orphan).
>
> Changes against 2a91ef1:
>
> * **Safety (offered to helmi1987):** free space on ZFS is the whole pool (`pool_usage()`: the dataset from
>   `/proc/mounts`, the pool's `used + avail` from `zfs list -Hp` as size, the dataset's `avail` as free; else `statvfs`;
>   `MEDIACACHE_ZFS` / `MEDIACACHE_MOUNTS` point it at stand-ins for the tests). The key/token goes only to the server's own
>   host: unredirected headers and `_SameHostRedirect` (all three types, Plex's plex.tv calls too). A path with `..` is never
>   followed out of the share (`Locations.to_rel`). A path mapping with an empty host path means «deliberately not cached»
>   (`Locations(…, skip)`), the longest mapping wins — never a shorter one. An answer without an item list, a server
>   without users, or a claimed Plex whose shared users plex.tv didn't give is an incomplete plan (no cleanup that run),
>   never «nothing watched». `PlexApi.resume_items` honours `IncludeItemTypes` (the separate limits below).
> * (Gone in 1.65.0, the owner: the office's «back to the disk they came from» — Unraid's share rules and its move binary
>   decide where a file goes back; an old `mediacache_origin.json` stays in the data folder, unused.)
> * Separate limits for started films and series (`max_resume_movies`, `max_resume_series`; default `max_resume_items`).
> * `MEDIACACHE_RESULT`: a JSON file with the result of a run (mode, counts, warnings, errors, `pool`, `give_back`).
> * `--release` (mode `release`): everything on the exclude list goes back to the array the cleanup's way, nothing is
>   filled; what is playing stays. Jack runs it once when he is let go and the user ticks «bring the films back».
> * **A sensible order both ways:** every on-deck file knows, per person, how far ahead it lies (`ahead`); filling goes
>   round-robin across the persons by rank (`Planner.fill_order`), giving back over the limit the farthest ahead first
>   (`give_back_order`). `MEDIACACHE_KEEP_BYTES`, `MEDIACACHE_NO_FILL`, `MEDIACACHE_WATCHED_ONLY` serve the night run.
> * `not_in_budget` in the result (2026-10-16): what the persons' budgets leave out — {items, films, episodes, bytes}, each
>   entry once; Jack's run summary says «budget full» with it. `persons` (2026-10-29): the same per person — an entry two
>   persons want counts at each.
> * **A file several persons have on deck counts at each** (2026-10-29): their rank (`ranks`), their planned and done in
>   the progress (`owners()`; `fill` counts the fill once, `shared` names the others at its place in the queue, a file
>   in flight its `users`) — copied once.
> * `MEDIACACHE_STOP` (a file): a real run stops after the file it is on — never inside a copy (copying in parallel:
>   after the files in flight, no new one begins); result `stopped`, exit 3.
> * `MEDIACACHE_PROGRESS` (a file): rewritten at every file boundary — phase, per person planned/done, the file in work
>   with its target, person and reason, the fill's queue, the last 50 files done. Jack's live panel reads it. A run with
>   `MEDIACACHE_NO_FILL` (only giving back) plans no fill into it (2026-10-25): its counts are the give-back's alone.
>   Parallel copies (2026-10-29): `active` lists every file in flight (`current` stays the first, `q` its place in the
>   queue); a file done before an earlier one is `[-1, 0]` in the queue, `pos` counts the done ones from the front;
>   `Progress` and `halt()` are thread-safe.
> * **Tidied per disk** (`remove_empty_on_disk`, 2026-10-20): a file filled from `/mnt/user0` leaves its folder empty on its
>   disk while another disk still has something in it (Season 01: E01/E02 on disk2) — the folder on the disk it lay on
>   goes as with Unraid's move; no other disk is read, the share's folder on the disk stays.
> * **A copy whose temporary file vanished is tried once more** (`VANISHED_RE`, «vanished», 2026-10-22, seen on a real
>   server: a User Script mirroring into the share with `rsync --delete` through /mnt/user removed two of rsync's
>   `.<name>.XXXXXX` before their rename — code 23, «rename … No such file or directory»): the fill and the rsync
>   give-back copy such a file once more; any other error or a stop stays as before. Lost again: one ERROR line in plain
>   words («Ein anderes Programm hat die halb kopierte Datei gelöscht … die Quelle ist unversehrt»), not rsync's. The
>   result's `failed` counts the files not copied / not brought back (`fill`, `back`, of them `vanished`, the first 20
>   `files` with `why`, `retried`) — per file, where the log has two ERROR lines for one.
> * **With a progress or a stop file, Unraid's move binary gets one file per call** (the cleanup): so the progress names
>   the file in work and «stop after this file» holds exactly. Standalone: one call for all, as before. (The target of a
>   file the binary moves is unknown until it is done — the progress gives it empty, the panel shows no part inside it.)
>
> **How the office runs it — every deviation from MediaCache's defaults, and why** (the owner, 2026-10-15: MediaCache's own
> ways wherever they don't collide with the office's logic):
>
> * Its own tools: filling `rsync`, giving back Unraid's `move` binary (through shfs: the share's «Minimum free space»,
>   allocation and split level, a ZFS array disk's dataset made by Unraid). Jack writes them into the settings; no
>   environment forces them. Jack's shares are «Cache: yes» on his pool, so the binary moves them Cache → Array.
> * `parallel_per_disk` from Jack's setup («copies at once per disk», 1–4, default 1 — MediaCache's default and meaning:
>   per source disk); `parallel_total` = the array's data disks × that, at most 8 (`MEDIACACHE_PARALLEL_TOTAL` at each run;
>   in the settings file the same as per disk). Before 2026-10-29 both were written fixed to 1.
> * `create_share_root` on (its default — written fixed): the share's ZFS dataset is MediaCache's, Jack makes none.
> * `MEDIACACHE_RSYNC_ARGS` = `-aAX --numeric-ids` (its default): rsync runs as root — no option from a settings file.
> * `MEDIACACHE_GIVE_BACK_FREE=1`: `min_free_percent` is a limit the run gives back to, not only one it stops filling at;
>   with Ms. Moverelli the office sets `min_free_percent` = 100 − her limit X and `MEDIACACHE_WATCHED_ONLY=1`.
> * `MEDIACACHE_LIVE_STATUS` = a file in RAM: MediaCache's live `mediacache_status.json` would be rewritten every 2 s on
>   the pool (the data folder lies there); the office never reads it.
> * Budget mode always (`cache_budget` set by Jack's setup) — a choice of the setup, not a change of the code.

> **Licence:** helmi1987's original is under the GNU GPL v3 ([LICENSE](LICENSE), «Copyright (C) 2025–2026 helmi1987»,
> SPDX `GPL-3.0-or-later`). This modified copy is likewise GPL-3.0-or-later, as part of the office.

# 🎬 MediaCache für Unraid

Für **Emby**, **Jellyfin** und **Plex** (pro Instanz `type`), auch gemischt. Jellyfin ab 10.9 (neue Endpunkte `/Items?userId=`, `/UserItems/Resume`, Auth-Header `Authorization: MediaBrowser Token="…"`).

**Plex** (Logik wie [PlexCache](https://github.com/bexem/PlexCache)): Quelle ist **On Deck** pro Benutzer, nur Einträge, die in den letzten `days_to_monitor` Tagen (Default 99) geschaut wurden; dazu die nächsten Folgen in Serienreihenfolge (`number_episodes` / `cache_budget` wie bei Emby). `api_key` = X-Plex-Token des Admins. Benutzer = Admin plus alle Benutzer, mit denen der Server geteilt ist (Token über plex.tv). Plex kennt keine Favoriten und kein separates «Als Nächstes» – beides entfällt dort. Bei mehrteiligen Medien zählt der erste Part (im `movie_mode: folder` wird der ganze Ordner genommen).

Verschiebt die Medien, die Emby-, Jellyfin- und Plex-Benutzer als Nächstes sehen werden («Weiterschauen», die nächsten Folgen, Favoriten-Serien), vom Array auf den Cache-Pool und räumt sie wieder aufs Array, sobald sie nicht mehr gebraucht werden. Die Array-Platten können dadurch schlafen, und die Wiedergabe startet ohne Spin-up.

Der Medienserver merkt davon nichts, weil er die Medien über `/mnt/user/...` sieht – egal ob eine Datei gerade auf dem Pool oder auf einer Array-Disk liegt.

## Was das Script macht

| Phase | Aktion | Beschreibung |
| --- | --- | --- |
| 1. Schutz | Sessions | Fragt alle Instanzen, was **gerade läuft**. Diese Dateien werden nie verschoben. |
| 2. Analyse | On-Deck | Pro Benutzer: «Weiterschauen»-Einträge und «Als Nächstes» des Servers (NextUp – damit eine Serie zwischen zwei Folgen nicht aus dem Cache fällt), bei Serien die nächsten Folgen **in Serienreihenfolge** (nach der aktuellen Folge), plus die nächsten ungesehenen Folgen von Favoriten-Serien. Umfang nach Zähler (`number_episodes`) oder nach **Budget** (`cache_budget`, siehe unten). |
| 3. Cleanup | Cache → Array | Dateien aus der letzten Exclude-Liste, die nicht mehr on deck sind, gehen zurück aufs Array – über das Unraid-`move`-Binary (`cleanup_tool: mover`, Default wie im Original; setzt Mover-Richtung Cache → Array voraus, das Script prüft und meldet sie) oder per `rsync` nach `/mnt/user0` (`cleanup_tool: rsync`, unabhängig von der Share-Einstellung). **Zuerst**, damit Platz frei wird. |
| 4. Befüllen | Array → Cache | On-Deck-Dateien kommen auf den Pool – per `rsync -aAX --numeric-ids /mnt/user0/… /mnt/<pool>/…` **exakt wie im Original**, Quelle erst gelöscht, wenn rsync mit 0 endet und die Grösse stimmt (`fill_tool: rsync`, Default). Optional liest rsync vom echten `/mnt/diskN`-Pfad (`array_source: disk`) oder die Array-Pfade gehen ans Unraid-`move`-Binary wie beim Stock-Mover für «prefer»-Shares (`fill_tool: mover`). Der Freiplatz wird vorher für alle geplanten Kopien zusammen geprüft. rsync-Kopien laufen auf Wunsch parallel pro Quell-Disk (`parallel_per_disk`, `parallel_total`, Default: eine nach der anderen). |
| 5. Exclude-Liste | `mediacache_exclude.txt` | Alle On-Deck-Dateien, die jetzt auf dem Cache liegen – für Mover Tuning («File list path»), damit der reguläre Mover sie in Ruhe lässt. |

Der Dry-Run trifft dieselben Entscheidungen wie der scharfe Lauf: Er schreibt nichts (auch nicht die Exclude-Liste), rechnet aber beim Freiplatz-Check den Platz ein, den der Cleanup freigäbe, und zieht jede geplante Kopie ab – so sieht er den Pool an jeder Stelle so, wie ihn der scharfe Lauf dort sähe.

Ist ein Medienserver nicht (vollständig) erreichbar, wird der Cleanup übersprungen und die alte Exclude-Liste bleibt geschützt – sonst würde ein kurzer Server-Neustart den ganzen Cache leeren.

## Installation

1. Python 3 auf Unraid (z.B. Plugin «Python 3 for UNRAID»). Weitere Pakete braucht es **nicht** – die Scripte nutzen nur die Standardbibliothek.
2. Ordner anlegen, z.B. `/mnt/user/system/scripts/mediacache`, und `mediacache_lib.py`, `mediacache_run.py`, `mediacache_setup.py`, `mediacache_cleaner.py` hineinkopieren.
3. Wizard ausführen: `python3 mediacache_setup.py`
4. Prüfen: `python3 mediacache_run.py --show-on-deck` (Report) und `python3 mediacache_run.py` (Dry-Run)
5. Scharf schalten, z.B. als User Script stündlich:
   ```
   #!/bin/bash
   cd /mnt/user/system/scripts/mediacache && python3 mediacache_run.py --run
   ```

## Dateien

| Datei | Zweck |
| --- | --- |
| `mediacache_setup.py` | Interaktiver Konfigurator: Instanzen, Bibliotheken, Pfad-Mapping, Benutzer, Systemwerte |
| `mediacache_run.py` | Hauptscript (Report / Dry-Run / Run) |
| `mediacache_cleaner.py` | Findet Dateien auf dem Cache, die nicht in der Exclude-Liste stehen (Waisen), und räumt sie auf Wunsch weg |
| `mediacache_lib.py` | Gemeinsamer Code (Config, Pfade, Emby-/Jellyfin-/Plex-API, Mover, Lock, Logging) |
| `mediacache_settings.json` | Konfiguration (siehe unten) |
| `mediacache_exclude.txt` | Aktuell gecachte Dateien; wird atomar geschrieben (über `mediacache_exclude.tmp`) |
| `mediacache.lock` | Verhindert parallele Läufe |
| `mediacache_status.json` | Live-Status während `--run` (für `--status`); wird atomar geschrieben (über `mediacache_status.tmp`) |
| `logs/` | Rotierende Logs (10 MB × 20) |
| `tests/` | Unit-Tests ohne Server (`python3 -m unittest discover -s tests`); auf Unraid nicht nötig |

Alle Pfade sind relativ zum **Script-Verzeichnis**, nicht zum Arbeitsverzeichnis – das Script läuft also auch aus cron/User Scripts ohne `cd`. Mit `MEDIACACHE_DIR` lässt sich das Arbeitsverzeichnis trotzdem umbiegen.

## Konfiguration (`mediacache_settings.json`)

```jsonc
{
    "cache_path": "/mnt/cache",            // Pool, auf den gecacht wird (z.B. /mnt/master)
    "array_path": "/mnt/user0",            // Array-Sicht ohne Pools
    "user_path": "/mnt/user",              // Sicht, die der Medienserver gemountet hat
    "array_disks_glob": "/mnt/disk[0-9]*", // echte Array-Disks: Quelle bei array_source: disk, Disk-Zuordnung für Parallelität/Status
    "array_source": "user0",               // rsync-Quelle: user0 = wie das Original, disk = /mnt/diskN (am FUSE vorbei)

    "instances": [
        {
            "servername": "Nostromo",
            "type": "emby",                    // emby | jellyfin | plex (Default: emby); bei plex optional "days_to_monitor": 99
            "url": "http://192.168.7.10:8096",
            "api_key": "…",
            "path_mappings": {                 // Docker-Pfad -> Host-Pfad, nur gecachte Bibliotheken
                "/media/Filme":  "/mnt/user/Filme",
                "/media/Serien": "/mnt/user/Serien"
            }
        }
    ],
    "path_mappings": {},                   // optional: globales Mapping für alle Instanzen
    "libraries": [],                       // nur informativ (vom Wizard gesetzt); gefiltert wird über path_mappings

    "valid_users": [],                     // User-IDs; leer = alle Benutzer (auch Dict {id: {...}} wird akzeptiert)
    "number_episodes": 3,                  // Zähl-Modus: Folgen nach der aktuellen vorladen
    "cache_budget": "",                    // Budget-Modus: z.B. "2.5T" – leer = Zähl-Modus
    "movie_share_percent": 50,             // Budget-Modus: Anteil Filme am Benutzer-Budget (weich, nach Bytes)
    "max_episodes_per_series": 0,          // Budget-Modus: Folgen pro Serie höchstens (0 = das Budget entscheidet)
    "max_resume_items": 10,                // Weiterschauen-Einträge pro Benutzer (gilt auch als Limit für «Als Nächstes»)
    "max_favorite_series": 10,             // Favoriten-Serien pro Benutzer (0 = aus)
    "use_next_up": true,                   // «Als Nächstes» des Servers als Quelle (fertige Folge, nächste noch nicht gestartet)
    "min_free_percent": 20,                // darunter wird nichts mehr kopiert (ZFS: nicht unter 15–20 gehen)
    "movie_mode": "folder",                // folder = ganzer Filmordner, file = nur gleichnamige Dateien
    "create_share_root": true,             // fehlende Share-Wurzel anlegen: ZFS = Dataset, btrfs/XFS = Ordner (siehe ZFS-Hinweis)
    "mover_bin": "",                       // leer = erstes vorhandene von /usr/libexec/unraid/move, /usr/local/sbin/move, /usr/local/bin/move
    "mover_debug_level": 0,                // -d für das move-Binary (1 = Dateien loggen)
    "rsync_args": ["-aAX", "--numeric-ids"], // vollständige rsync-Optionen, Default = Original
    "fill_tool": "rsync",                  // Array -> Cache: rsync oder mover (Unraid move-Binary, siehe Hinweise)
    "cleanup_tool": "mover",               // Cache -> Array: mover (wie Original) oder rsync (unabhängig von der Mover-Richtung)
    "parallel_per_disk": 1,                // Befüllen per rsync: gleichzeitige Kopien pro Quell-Disk (1 = wie das Original)
    "parallel_total": 1,                   // gleichzeitige Kopien insgesamt (0 = ohne Limit); 1/1 = sequentiell wie das Original
    "copy_progress": true,                 // rsync zusätzlich mit --info=progress2 (nur Ausgabe) – Fortschritt pro Datei
    "status_log_interval": 60,             // Sekunden zwischen Fortschrittszeilen im Log (0 = aus)
    "api_timeout": 10,
    "shares_cfg_dir": "/boot/config/shares" // für die Prüfung der Mover-Richtung pro Share
}
```

### Status und paralleles Kopieren

Während `--run` schreibt das Script laufend `mediacache_status.json` (alle 2 s, atomar). Aus einer zweiten Shell:

```
python3 mediacache_run.py --status          # einmal anzeigen
python3 mediacache_run.py --status --watch  # alle 2 s neu (--watch 5 = alle 5 s), Ende mit Ctrl+C
```

```
MediaCache 7.3.0 – Status: läuft, PID 930, Modus RUN
Start 2026-10-09 03:00:01, letzte Aktualisierung 2026-10-09 03:01:12
Phase: Befüllen (Array -> Cache)  –  3/6 Dateien, 10.73 GB von 17.17 GB (62 %)
Aktiv (2):
  [3/6] disk1     25 %   732.42 MB / 2.86 GB   180.00MB/s  Rest 0:00:12  Serien/Show1/E3.mkv
  [5/6] disk2     25 %   732.42 MB / 2.86 GB   175.00MB/s  Rest 0:00:13  Serien/Show2/E2.mkv
Zuletzt fertig:
  ok     disk1       2.86 GB     16.0 s  Serien/Show1/E2.mkv
```

Im Log steht pro Datei `[n/N] Start disk3 …` und `[n/N] Fertig … in X s (Y/s)`, dazu alle `status_log_interval` Sekunden eine Zeile «Fortschritt …» mit Gesamtstand, Rate, Restzeit und den aktiven Kopien. Der Fortschritt innerhalb einer Datei kommt aus `--info=progress2` von rsync (reine Ausgabe-Option, ändert nichts am Kopieren; `copy_progress: false` schaltet sie ab, dann zeigt der Status nur ganze Dateien). Endet der Prozess ohne Abschluss, zeigt `--status` «abgebrochen». `--watch` funktioniert nur zusammen mit `--status` (zweite Shell), nicht mit `--run`.

Beim move-Binary (`cleanup_tool: mover`, `fill_tool: mover`) gibt es keine Fortschrittsausgabe. Das Script prüft deshalb alle 2 s, welche Quellen schon weg sind: Log-Zeilen `[n/N] -> ARRAY läuft …` / `… fertig … in X s`, und im Status die aktuelle Datei mit der Grösse, die am Ziel schon sichtbar ist (Cleanup: `/mnt/diskN/<Datei>`, Befüllen: Pool). Die aktuelle Datei ist abgeleitet (das Binary arbeitet die Pfade der Reihe nach ab), nicht vom Binary gemeldet.

**Parallel (nur `fill_tool: rsync`).** Die Dateien werden nach Quell-Disk (`/mnt/diskN`, auch bei `array_source: user0` ermittelt) gruppiert. `parallel_per_disk` = gleichzeitige Kopien pro Disk, `parallel_total` = Obergrenze über alle Disks (0 = keine). Beispiel `parallel_per_disk: 1`, `parallel_total: 4`: bis zu 4 Disks lesen gleichzeitig, jede eine Datei. Der Freiplatz-Check passiert vor dem Start für alle Kopien zusammen (geplante Kopien werden abgezogen). Drei rsync-Fehler in Folge stoppen neue Kopien, laufende werden fertig.

Hinweise:
* Mehr als 1 pro Disk heisst: eine HDD liest zwei Dateien gleichzeitig, der Kopf springt zwischen beiden. Bei HDDs bringt das in der Regel keinen höheren Gesamtdurchsatz, oft weniger. Der Gewinn kommt aus mehreren Disks parallel. Selbst messen: `parallel_per_disk` 1 vs. 2 bei gleichem `parallel_total`.
* Mit `array_source: user0` läuft jede Kopie über shfs (FUSE); `array_source: disk` liest direkt von `/mnt/diskN`.
* `fill_tool: mover` und der Cleanup über das move-Binary bleiben sequentiell (ein Aufruf mit allen Pfaden). Ob mehrere move-Binaries gleichzeitig laufen dürfen, ist von Unraid nicht dokumentiert – deshalb nicht parallelisiert. Der Cleanup per rsync läuft ebenfalls sequentiell (Ziel-Disk wählt shfs, Paritäts-Schreiben ist der Engpass).

### Budget-Modus

Zähler wissen nichts über Dateigrössen: drei Folgen einer SD-Serie sind 1 GB, drei Remux-Folgen 30 GB. Mit `cache_budget` (z.B. `"2.5T"`) rechnet das Script in Bytes:

* Das Budget wird **fair auf die aktiven Benutzer** verteilt (Benutzer ohne «Weiterschauen»/Favoriten zählen nicht). Was ein Benutzer nicht braucht, fliesst automatisch an die anderen (Water-Filling). Einzelnen Benutzern lässt sich ein festes Budget geben: `"valid_users": {"<id>": {"budget": "300G"}, "<id2>": {}}`.
* Innerhalb des Benutzer-Budgets werden **Filme und Serien nach Bytes** im Verhältnis `movie_share_percent` gefüllt – weich: ist eine Kategorie leer, bekommt die andere den Rest. Filme in der Reihenfolge der Weiterschauen-Liste (neueste zuerst).
* Serien werden **reihum** gefüllt: jede laufende Serie und jeder Favorit bekommt pro Runde eine Folge. Kleine Folgen ergeben so automatisch viele Stück, grosse wenige, und keine Serie kann das ganze Budget belegen. `max_episodes_per_series` deckelt zusätzlich (0 = nur das Budget entscheidet).
* Passt ein Eintrag nicht mehr, wird seine Serie gestoppt (nie Folge 5 ohne Folge 4); ein zu grosser Film wird übersprungen, der nächste probiert.
* Was nicht ins Budget passt, bleibt auf dem Array bzw. wird beim nächsten Lauf zurückgeräumt. Das Log zeigt pro Benutzer `Budget <Name>: belegt von zugeteilt (Filme …, Serien …) – nicht im Budget: …`.

Der Freiplatz-Check (`min_free_percent`) prüft das Share-Dataset **und** die Pool-Wurzel und nimmt den kleineren Wert – eine grosszügige Dataset-Quota kann den Pool mit appdata darauf also nicht vollaufen lassen.

Das Budget bezieht sich auf den Inhalt der Exclude-Liste, also auf das, was das Script auf dem Pool hält. `min_free_percent` und eine ZFS-Quota bleiben die harte Grenze darunter.

Filme: Liegt der Film in einem eigenen Ordner, wird der ganze Ordner mitgenommen (Untertitel, nfo, Extras). Liegt er direkt im Bibliotheksordner (flache Ablage), werden nur die Dateien mit gleichem Basisnamen genommen – die Bibliothek wird nie als Ganzes verschoben. Ordner-Items (DVD/BluRay-Struktur) werden als Ordner behandelt.

Rechte: Dateien behalten Owner, Gruppe, Rechte, ACLs und xattrs (rsync `-aAX --numeric-ids`). Fehlende Zielordner werden mit Owner, Gruppe und Rechten des entsprechenden Quellordners angelegt; bestehende Ordner bleiben unverändert.

Sidecars bei Episoden und gleichnamigen Dateien: alle Dateien mit gleichem Basisnamen (mkv, srt, nfo, Emby-`.bif` …) plus der Jellyfin-Trickplay-Ordner `<Basisname>.trickplay/` (Vorschaubilder, wenn «Trickplay neben den Medien speichern» aktiv ist).

## Befehle

| Befehl | Wirkung |
| --- | --- |
| `python3 mediacache_run.py --show-on-deck` | Report **pro Benutzer**: Filme, dann jede Serie mit ihren Folgen, dann «Nicht im Budget»; je Eintrag Quelle, Speicherort (CACHE/ARRAY/TEILS), Grösse und Dateien |
| `… --show-on-deck --user Benj,Kid` | Report nur für diese Benutzer (Name oder ID). Die Planung läuft immer für alle – der Filter betrifft nur die Anzeige und ist deshalb mit `--run` nicht erlaubt |
| `… --show-on-deck --compact` | Report ohne einzelne Dateien |
| `python3 mediacache_run.py` | Dry-Run: alle geplanten Aktionen, keine Dateioperationen |
| `python3 mediacache_run.py --run` | Scharf |
| `python3 mediacache_run.py --status [--watch [SEK]]` | Live-Status des laufenden bzw. letzten Laufs (aus `mediacache_status.json`) |
| `python3 mediacache_cleaner.py` | Waisen auf dem Cache anzeigen |
| `python3 mediacache_cleaner.py --run` | Waisen aufs Array verschieben – per `cleanup_tool` (mover oder rsync), mit derselben Prüfung der Mover-Richtung wie das Hauptscript |
| `python3 mediacache_cleaner.py --add-to-list` | Waisen in die Exclude-Liste aufnehmen |

Umgebungsvariablen: `MEDIACACHE_MODE` (dry / report / run), `MEDIACACHE_DIR`, `MEDIACACHE_CONFIG`, `MEDIACACHE_LOG_LEVEL`, `MEDIACACHE_MIN_FREE_PERCENT`, `MEDIACACHE_MOVER_DEBUG`, `MEDIACACHE_RSYNC_ARGS`, `MEDIACACHE_FILL_TOOL`, `MEDIACACHE_CLEANUP_TOOL`, `MEDIACACHE_CACHE_BUDGET`, `MEDIACACHE_REPORT_USER`, `MEDIACACHE_PARALLEL_PER_DISK`, `MEDIACACHE_PARALLEL_TOTAL`, `MEDIACACHE_STATUS_LOG_INTERVAL`. Der Hilfetext im Kopf jedes Scripts beschreibt sie. `MEDIACACHE_MOVER_DEBUG`, `MEDIACACHE_LOG_LEVEL`, `MEDIACACHE_CLEANUP_TOOL` und `MEDIACACHE_RSYNC_ARGS` gelten auch für `mediacache_cleaner.py`.

Beispiel-Report (`--show-on-deck`, mit `--compact` ohne die Dateizeilen):

```
=== Benj @ Nostromo ===  Budget: 312.40 GB von 833.33 GB (Filme 120.10 GB, Serien 192.30 GB)
  Filme: 2 Einträge, 61.70 GB
    • Inception   [CACHE] 60.20 GB, 4 Dateien   (Weiterschauen)
      • [CACHE]   60.10 GB  Filme/Inception (2010)/Inception (2010).mkv
      …
  Serien: 3 Serien, 9 Folgen, 12.70 GB
    • South Park   (Als Nächstes)  4 Folgen, 1.60 GB  [ARRAY]
      • S13E02 The Coon   [ARRAY] 410.00 MB, 1 Datei   (Als Nächstes)
      • S13E03 Margaritaville   [ARRAY] 405.00 MB, 1 Datei   (Nächste Folge)
      …
    • The Expanse   (Weiterschauen)  3 Folgen, 9.10 GB  [TEILS]
      • S03E05 Triple Point   [CACHE] 3.10 GB, 2 Dateien   (Weiterschauen)
      • S03E06 Immolation   [ARRAY] 3.00 GB, 2 Dateien   (Nächste Folge)
    • Dark   (Favorit)  2 Folgen, 2.00 GB  [ARRAY]
  Nicht im Budget: 1 Einträge, 82.00 GB  (bleiben auf dem Array bzw. werden zurückgeräumt)
    ✗ Dune Part Two   [ARRAY] 82.00 GB, 3 Dateien   (Weiterschauen)
```

Pro Benutzer zuerst die Filme, dann jede Serie als Block mit ihren Folgen in Reihenfolge; hinter der Serie steht, warum sie on deck ist (Weiterschauen, Als Nächstes, Favorit), hinter jeder Folge, woher sie kommt. `[TEILS]` heisst: ein Teil liegt schon auf dem Cache.

## Worauf du auf Unraid achten musst

**Share-Einstellung der Medien-Shares – entscheidend.** Das `move`-Binary nimmt die Richtung aus `/boot/config/shares/<Share>.cfg`, nicht aus dem Pfad, den es bekommt. Steht der Share auf **prefer** oder **only** (Primary: Cache, Mover Array → Cache bzw. kein Array), schiebt das Binary Dateien dieses Shares *immer Richtung Cache* – der Cleanup Cache → Array ist damit unmöglich und endet bei vollem Pool mit `No space left on device` und `create_parent: /mnt/cache/…`. Das Script liest vor dem Cleanup pro Share die Konfiguration (`/boot/config/shares/<Share>.cfg`, sonst die Standardwerte aus `/boot/config/share.cfg`), schreibt immer eine Zeile `Share «…»: shareUseCache=… (Primary …, Secondary …; aus …)` ins Log und überspringt Shares, bei denen der Mover nicht Cache → Array kann. Wer die Share-Einstellung nicht ändern will, nimmt `cleanup_tool: rsync`: Das ist das Spiegelbild des Befüllens (`rsync -aAX --numeric-ids /mnt/<pool>/<Datei> /mnt/user0/<Datei>`, shfs wählt die Disk, Grössenvergleich, dann Quelle löschen) und hängt nicht an der Mover-Richtung. Richtig ist *Primary: Cache, Secondary: Array, Mover: Cache → Array* (alt: «Cache: Yes»). Dann schiebt allerdings auch der reguläre Mover die gecachten Dateien nachts zurück – dagegen hilft **Mover Tuning** («File list path» auf `mediacache_exclude.txt`) oder der Smart Mover mit `excludes=`. Bei *Array only* (`no`) fasst der reguläre Mover den Share nicht an; ob das Binary Pool-Pfade dann aufs Array schiebt, ist ungetestet – mit einer Datei und `mover_debug_level: 1` prüfen.

**Log lesen.** Dry-Run und Run listen Cleanup und Befüllen pro Serie bzw. Filmordner mit den Dateien darunter (beim Befüllen mit der Quell-Disk). Im scharfen Lauf folgt pro Datei eine Start- und eine Fertig-Zeile `[n/N] …` sowie periodisch eine Zeile «Fortschritt …» (siehe «Status und paralleles Kopieren»). Nach dem Mover fasst das Script zusammen, was liegen blieb und warum (die Meldung des Binaries pro Ursache, z.B. `No space left on device`), und am Ende stehen zwei Ergebniszeilen: «Ergebnis Cleanup: X von Y Dateien aufs Array verschoben» und «Ergebnis Befüllen: X von Y Dateien auf den Cache kopiert». Die rohen Mover-Zeilen stehen im DEBUG-Log.

**ZFS-Pool als Cache.** Unraid legt auf ZFS jeden Share als eigenes Dataset an (shfs: `zfs create <pool>/<Share>`), ein `mkdir` direkt auf dem Pool ergäbe dagegen einen normalen Ordner im Root-Dataset (keine eigenen Snapshots, Quota oder Backups je Share; Unraid wandelt ihn nie um). Fehlt `/mnt/<pool>/<Share>`, macht MediaCache es deshalb wie Unraid: Dateisystem des Pools aus `/proc/mounts`, auf **ZFS** `zfs create <pool-dataset>/<Share>` (alle Eigenschaften inkl. Mountpoint geerbt), danach Owner und Rechte wie `/mnt/user0/<Share>`; Unterordner bleiben normale Ordner (wie bei Unraid). Angelegt wird nur, wenn der Share laut Share-Einstellung auf diesen Pool darf (`shareCachePool` = dieser Pool, `shareUseCache` yes/prefer/only) – sonst werden seine Dateien übersprungen und das Log sagt warum. Auf **btrfs/XFS** entsteht wie bei Unraid ein normaler Ordner. Der Dry-Run meldet nur «würde Dataset … anlegen». Existiert die Share-Wurzel auf ZFS schon als normaler Ordner (ältere Installationen), baut das Script nichts um und warnt einmal pro Lauf; Lösung: den Teil des Shares auf dem Pool leeren lassen und den leeren Ordner entfernen, der nächste Lauf legt das Dataset an. MediaCache führt nie `zfs destroy` oder `zfs rename` aus. `create_share_root: false` überspringt Dateien von Shares ohne Wurzel auf dem Pool. Eine `zfs set quota=…` auf das Dataset ist die harte Grenze gegen einen vollen Pool – zusätzlich zu `min_free_percent`, das bei einem Dataset mit Quota gegen die Quota rechnet. Der Rückweg (Cleanup über `move` bzw. rsync nach `/mnt/user0`) läuft über shfs: Unraid wählt die Disk, legt auf ZFS-Disks das Dataset selbst an und beachtet «Minimum free space».

**Kopieren und Mover wie im Original.** Mit den Defaults sind die beiden Aufrufe identisch mit der seit über einem Jahr produktiven Version – bis auf `--info=progress2`, das nur die Fortschrittsausgabe einschaltet (`copy_progress: false` = exakt wie das Original): `rsync -aAX --numeric-ids /mnt/user0/<Datei> /mnt/<pool>/<Datei>` pro Datei, eine nach der anderen, danach `unlink` der Quelle; Cache → Array über `/usr/libexec/unraid/move` (sonst `/usr/local/sbin/move`, `/usr/local/bin/move`) mit den Cache-Pfaden zeilenweise auf stdin, ohne `-d`, solange `mover_debug_level` 0 ist. Neu ist nur, was *nach* dem Aufruf passiert: Exit-Code und stderr werden geloggt, die Grösse wird vor dem Löschen verglichen, und nach dem Mover wird geprüft, was noch auf dem Cache liegt. `MEDIACACHE_LOG_LEVEL=DEBUG` zeigt jeden Aufruf wörtlich.

**Array → Cache über den Unraid-Mover (`fill_tool: mover`).** Der Stock-Mover bringt Dateien von «prefer»-Shares mit `find /mnt/user0/<share> | move` auf den Pool; das Script übergibt dem Binary dieselben Pfade (bevorzugt den echten `/mnt/diskN`-Pfad). Welchen Pool das Binary nimmt, steht in der Share-Konfiguration – für einen Share auf *Array only* gibt es keinen, dann bleibt die Datei liegen und das Script meldet es. Deshalb: mit `mover_debug_level: 1` und einer einzelnen Datei testen, bevor du umstellst. Vorteil gegenüber rsync: fuser-Check und keine doppelte Datei bei Abbruch. Nachteil: kein paralleles Kopieren. Das Werkzeug für den Cleanup (Cache → Array) wählt unabhängig davon `cleanup_tool`.

**Server-Mounts.** Emby, Jellyfin bzw. Plex muss die Medien über `/mnt/user/...` sehen (nicht `/mnt/user0` oder `/mnt/diskN`), sonst bricht der transparente Wechsel zwischen Pool und Array.

**Hardlinks.** Sind Medien per Hardlink mit einem Download-Ordner verbunden (*arr-Setups), trennt rsync + löschen den Hardlink; nach dem Zurückschieben liegt die Datei doppelt.

**Duplikate.** Bricht ein Kopiervorgang ab, bleibt die Quelle auf dem Array und rsync räumt seine Temp-Datei weg. Bleibt trotzdem einmal eine Datei doppelt liegen (z.B. Löschen fehlgeschlagen), steht es als ERROR im Log; `mediacache_cleaner.py` findet solche Dateien.

## FAQ

**Warum passiert nichts?** Meist fehlt das Path-Mapping: Ohne Übersetzung des Docker-Pfads wird ein Item mit WARNING übersprungen (`--show-on-deck` zeigt es). Prüfe auch `valid_users` – leer heisst alle.

**Wird mein laufender Film unterbrochen?** Nein. Laufende Wiedergaben werden über `/Sessions` erkannt und weder kopiert noch zurückgeschoben. Wenn Sessions nicht abrufbar sind, wird nichts vom Cache entfernt.

**Wo sind die Logs?** `logs/mediacache.log` und `logs/mediacache_cleaner.log`, mit vollständigen Pfaden. `MEDIACACHE_LOG_LEVEL=DEBUG` zeigt zusätzlich die Mover-Aufrufe.

## Lizenz

Copyright (C) 2025–2026 helmi1987

Dieses Programm ist freie Software: Du kannst es unter den Bedingungen der
GNU General Public License, Version 3, wie von der Free Software Foundation
veröffentlicht, weitergeben und/oder verändern.

Es wird in der Hoffnung verbreitet, dass es nützlich ist, aber **ohne jede
Garantie** – sogar ohne die implizite Garantie der Marktreife oder der Eignung
für einen bestimmten Zweck. Details stehen in der Datei [LICENSE](LICENSE)
(GNU GPL v3, SPDX: `GPL-3.0-or-later`).
