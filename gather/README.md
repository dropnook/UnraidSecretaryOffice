> **In the Unraid Secretary Office** this is Jack Emby's "Consolidate folders": taken from
> [helmi1987/media-disk-gather-for-unraid](https://github.com/helmi1987/media-disk-gather-for-unraid)
> (V11.0) and since changed here (V11.4). Jack writes its settings (`data/gather/consolidate.ini`)
> and runs it — never while EmbyCache runs; the cache only when switched on in his settings
> (`MOVE_CACHE`/`CACHE_ONLY_TARGET` in the ini, #14 — EmbyCache's list is the exclude file, so
> what it keeps on the pool always stays). Changes against V11.0:
>
> * `CONSOLIDATE_CONFIG`: where the ini is (default: next to the script).
> * `CONSOLIDATE_STATUS`: a JSON file with the result (mode, counters, exit code).
> * The status carries `sizes` (V11.4): per share and disk/pool the bytes and files the index saw
>   (`{"Filme": {"disk2": {"bytes": n, "files": n}, "master": {…}}}`, cache/pool roots included; no
>   extra disk reads), kept up to date by a real run's moves and deletions, and `sizes_at` (when those
>   numbers were true: after the index in a dry run, the end of a real one). Missing when the run ended
>   before its index. Jack shows them on his page.
> * `CONSOLIDATE_STOP`: a file; once it exists the run stops after the folder it is on (no retry,
>   no deep clean; result `stopped`, exit 3, `folders`/`folders_done` in the status) — Jack writes it
>   when someone starts watching Emby during a real run.
> * `CONSOLIDATE_LOCK`, `CONSOLIDATE_USER_ROOT`: the lock file and `/mnt/user` — for the tests only.
> * Deep clean keeps an empty array folder when the same folder exists on a cache/pool: its
>   content lies there right now and the folder shows which disk it belongs to.
> * Not taken over: `setup_consolidate.sh` (Jack is the setup), the zip and the test suite.
>
> **Licence:** helmi1987's original is under the GNU GPL v3 — its
> [LICENSE](https://github.com/helmi1987/media-disk-gather-for-unraid/blob/main/LICENSE) is the GPL v3
> text, its README says «Copyright (C) 2026 helmi1987» and SPDX `GPL-3.0-or-later` (since 2026-10-06).
> This modified copy is likewise GPL-3.0-or-later, as part of the office.

# Unraid Media Consolidator & Cleaner (V11.0)

Ein Bash-Script-Set für Unraid-Systeme. Es dient dazu, zersplitterte Medienbibliotheken (Filme, Serien) zu konsolidieren, zusammengehörige Dateien ("Sidecars" wie NFOs, Bilder) auf derselben Disk zusammenzuführen und verwaiste leere Ordnerstrukturen tiefenrein zu entfernen.

- - -

## Features (V11.0)

### Smart Weight Logic (Intelligente Gewichtung)

Das Script analysiert, auf welcher **Array-Disk** (`/mnt/disk[0-9]*`) bereits die grösste Datenmenge (in Bytes) eines Films oder einer Serie liegt, und zieht die restlichen Dateien des Ordners dorthin.

*   Der Cache wird bei der Ziel-Ermittlung ignoriert. Der Datenfluss geht immer Richtung Array.
*   Gezählt wird die **physische** Grösse jeder Kopie. Bei Gleichstand gewinnt die Disk mit der kleineren Nummer (deterministisch).
*   Disk-Namen werden exakt verglichen (`disk1` ist nicht `disk10`).

### Duplikate – nur identische Kopien werden gelöscht

Liegt eine Datei auf der Ziel-Disk und zusätzlich auf einer anderen Disk oder auf dem Cache, wird die zweite Kopie nur gelöscht, wenn sie **identisch** ist:

*   `DUP_CHECK=size` (Standard): gleiche Grösse.
*   `DUP_CHECK=cmp`: zusätzlich Byte-für-Byte-Vergleich (langsam, aber sicher).
*   Ungleiche Kopien (z.B. abgebrochene Kopiervorgänge) werden **nie** gelöscht, sondern als **Konflikt** gemeldet und protokolliert.

### Cache Handling (Mover-Trennung)

*   **Standard:** Dateien auf dem Cache werden ignoriert und nicht verschoben. Das Script überlässt diese Aufgabe dem nativen Unraid Mover. Identische Cache-Duplikate einer Array-Datei werden trotzdem entfernt.
*   **Optional:** Mit `--include-cache` werden auch Dateien vom Cache auf das Array verschoben. Ordner, die **nur** auf dem Cache liegen, landen dabei auf der Array-Disk mit dem meisten freien Platz (`CACHE_ONLY_TARGET=most-free`) oder bleiben liegen (`CACHE_ONLY_TARGET=skip`).
*   Läuft der Unraid-Mover gerade, bricht ein scharfer Lauf ab (Dryrun warnt nur).

### Deep Clean (Rekursive Tiefenreinigung)

Nach der Verschiebung startet Phase 3:

*   Loop-Reinigung: Das Script durchsucht die Disks in Schleifen nach leeren Ordnern, bis alles sauber ist. Nicht löschbare Ordner (z.B. Mountpoints, schreibgeschützte Disks) werden gemeldet und übersprungen – kein Endlos-Loop.
*   Root-Protection: Die Share-Wurzeln auf den Disks (z.B. `/mnt/disk1/Filme`) werden **niemals** gelöscht.
*   Im Dryrun wird das Ergebnis simuliert: auch Ordner, die erst durch die geplanten Verschiebungen leer würden, werden angezeigt.

### Weitere Sicherheitsmechanismen

*   Neu angelegte Zielordner übernehmen Besitzer und Rechte des Quellordners (auf Unraid typischerweise `nobody:users 0777`), damit Docker-Container und SMB-Benutzer weiterhin schreiben können.
*   Die Konfiguration wird beim Start geprüft (`DRYRUN` nur `true`/`false`, `MIN_FREE_GB` nur Zahlen, `BASE_DIRS` nur unter `/mnt/user/`). Ungültige Werte führen zum Abbruch statt zu einem unbeabsichtigten scharfen Lauf.
*   Sperrdatei: es läuft immer nur eine Instanz.
*   Zusammenfassung und Exit-Code (`0` ok, `1` Konfigfehler, `2` Lauf mit Fehlern/Konflikten) eignen sich für Benachrichtigungen über das User-Scripts-Plugin.

- - -

## Voraussetzungen

*   OS: Unraid (getestet auf Version 6.x / 7.x, bash ≥ 4.4)
*   Zugriff: Terminal (SSH) oder das "User Scripts" Plugin.
*   Tools: `rsync`, `find`, `flock` (Standardmässig in Unraid enthalten).

- - -

## Installation

### 1\. Verzeichnis erstellen

Erstelle einen Ordner auf deinem Cache oder USB-Stick, damit die Scripte Reboot-sicher sind.

```
mkdir -p /mnt/user/system/scripts/consolidate/
cd /mnt/user/system/scripts/consolidate/
```

### 2\. Dateien platzieren

Kopiere deine beiden Script-Dateien in diesen Ordner:

*   `consolidate_master.sh`
*   `setup_consolidate.sh`

### 3\. Berechtigungen setzen

```
chmod +x consolidate_master.sh setup_consolidate.sh
```

- - -

## Konfiguration

Nutze den Assistenten, um die Datei `consolidate.ini` zu erstellen. Sie wird immer neben die Scripte geschrieben, egal aus welchem Ordner du den Assistenten startest.

```
./setup_consolidate.sh
```

Der Assistent führt dich durch folgende Schritte:

1.  Quellverzeichnisse: Welche User-Shares sollen aufgeräumt werden? Mehrere mit `;` trennen (Leerzeichen in Pfaden sind erlaubt).
2.  Logdatei: Wo soll das Protokoll gespeichert werden? (Ordner wird beim scharfen Lauf angelegt)
3.  Array-Disks: Wo sollen die Daten dauerhaft liegen? (Standard: `/mnt/disk[0-9]*`)
4.  Cache/Pools: Wo liegen temporäre oder neue Daten? (z.B. `/mnt/cache /mnt/nvme`)
5.  Exclude-Datei: Pfad zu einer Datei mit Ausnahmen (optional). Eine Datei **oder ein Ordner** pro Zeile, als `/mnt/user/...`- oder `/mnt/diskN/...`-Pfad.
6.  Mindestspeicherplatz: Wieviel Platz muss auf einer Disk frei bleiben? (Standard: 256 GB)
7.  Dryrun-Standardmodus: `true` oder `false`.
8.  Verhalten für Cache-only-Ordner bei `--include-cache`: `most-free` oder `skip`.
9.  Duplikat-Prüfung: `size` oder `cmp`.

Einzelne Werte lassen sich für einen Lauf per Umgebungsvariable überschreiben, z.B. `CONSOLIDATE_DUP_CHECK=cmp ./consolidate_master.sh --run` (Liste: `./consolidate_master.sh --help`).

- - -

## Nutzung

### 1\. Test-Lauf (Dryrun)

Führe das Script ohne Argumente aus. Dies ist der Standardmodus. Es werden keine Dateien bewegt oder gelöscht, und es wird nichts ins Log geschrieben.

```
./consolidate_master.sh
```

`--dryrun` erzwingt den Testmodus auch dann, wenn in der `consolidate.ini` `DRYRUN=false` steht.

### 2\. Ernstfall (Live Mode)

Nur Array aufräumen (Standard):

```
./consolidate_master.sh --run
```

Array aufräumen UND Cache leeren (alles zum Array schieben):

```
./consolidate_master.sh --run --include-cache
```

Hinweis: Nicht gleichzeitig mit dem Unraid-Mover laufen lassen – das Script bricht in dem Fall ab.

- - -

## Fehlerbehebung

**Fehler: "Keine Ordner auf den Disks gefunden!"**  
Prüfe in der Config, ob die Pfade korrekt geschrieben sind (Gross-/Kleinschreibung beachten).

**"VOLL" / "Nicht verschoben (Disk voll)"**  
Die Ziel-Disk hat weniger freien Speicher als in `MIN_FREE_GB` definiert. Das Script überspringt diese Datei, versucht es am Ende des Laufs erneut (Retry-Queue) und protokolliert den Fehlschlag.

**"KONFLIKT (ungleich, behalten)"**  
Zwei Kopien derselben Datei haben unterschiedliche Grösse/Inhalt (z.B. abgebrochener Kopiervorgang). Das Script löscht nichts – prüfe die Kopien von Hand.

**"Der Unraid-Mover läuft gerade"**  
Warte, bis der Mover fertig ist, und starte das Script erneut.

- - -

## Haftungsausschluss

Dieses Script manipuliert Dateien (Verschieben/Löschen) auf Systemebene. Obwohl umfangreiche Sicherheitsmechanismen (Dryrun, Space-Check, Duplikat-Prüfung, Root-Protection, Mover-Check) eingebaut sind:

Die Nutzung erfolgt auf eigene Gefahr. Stelle sicher, dass du regelmässige Backups deiner wichtigen Daten hast!
