# unraid-backup — der Motor von Herrn Backup

Teil des [Unraid Secretary Office](../README.md): Herr Backup zeigt und steuert diesen Motor im Browser. Er läuft aber auch ganz ohne Oberfläche — User Scripts startet ihn nachts, `setup.sh` richtet ihn im Terminal ein.

> Dieser Teil ist noch deutsch; die Übersetzung ins Englische folgt vor der Veröffentlichung.

Nächtliches Backup für Unraid-Server. Es macht konsistente **ZFS-/btrfs-Snapshots** und **Datenbank-Dumps**, setzt Nextcloud dafür in den **Wartungsmodus** und schickt auf Wunsch alles mit **Kopia** verschlüsselt offsite. Alles Serverspezifische steht in `settings.ini`. Diese Datei erzeugt `setup.sh` nach Rückfrage. Der nächtliche Lauf `backup.sh` meldet jede Abweichung zwischen System und `settings.ini`, ändert sie aber nie selbst.

Version **2.11** (3.10.2026). Die Version steht im Kopf von `setup.sh` und `backup.sh`, in `lib/common.sh` (`UB_VERSION`) und in jedem Protokoll.

---

## Voraussetzungen

- Unraid 6.12 oder neuer. Für Snapshots müssen Pools bzw. Array-Disks ZFS oder btrfs sein; Shares auf XFS werden ohne Snapshot („live“) gesichert.
- Plugin **User Scripts** für den Zeitplan.
- Optional: **Compose Manager**, wenn Stacks mit Datenbanken im Spiel sind.
- Optional: ein **Kopia**-Container (z. B. `imagegenius/kopia` aus den Community Apps), wenn offsite gesichert werden soll.
- `jq`, `flock`, `timeout` und Co. bringt Unraid mit; `setup.sh` prüft das.

## Installation

1. Das Sekretariat installieren (siehe [README](../README.md)); der Motor liegt dann in `/mnt/user/appdata/UnraidSecretaryOffice/backup/`.
2. Mit Kopia: Container einrichten und **einmal in der KopiaUI mit dem Repository verbinden** (siehe [Kopia – optional](#kopia--optional)), dazu das [Mapping](#kopia-container-das-eine-mapping) und PUID/PGID 0.
3. Im Terminal (SSH oder Unraid-Web-Terminal): `/mnt/user/appdata/UnraidSecretaryOffice/backup/setup.sh`. Es prüft alles, erklärt jeden Schritt und schreibt `settings.ini`. Auf Wunsch legt es auch den Eintrag in User Scripts an.
4. In Settings › User Scripts beim Eintrag `unraid-secretary-office_backup` den Zeitplan setzen (oder bei Herrn Backupsi unter «Zeitplan…»), z. B. Custom `0 3 * * *`.
5. Trockenlauf: bei Herrn Backup „Jetzt sichern… › Trockenlauf“, oder im Terminal `UB_DRY_RUN=1 /mnt/user/appdata/UnraidSecretaryOffice/backup/backup.sh`
6. Erster echter Lauf: bei Herrn Backup „Jetzt sichern…“, in User Scripts „Run in Background“ oder bis zum Zeitplan warten.

Ein bestehendes Backup-Script ablösen: `setup.sh` warnt, wenn andere User Scripts ebenfalls Snapshots oder Kopia-Läufe machen. Dort den Zeitplan deaktivieren, sonst laufen beide. Kopia-Quellen, deren Pfad im Container nicht mehr existiert, bietet `setup.sh` an, auf „manuell“ zu stellen. Nach einem erfolgreichen neuen Lauf kann es sie auch löschen (Bestätigung durch Eintippen von `LOESCHEN`).

---

## Ablauf eines Laufs

```
 1  settings.ini laden, Inventar aufnehmen (Pools, Disks, Shares, Datasets, Container)
 2  Abweichungen prüfen und melden (neu / umbenannt / gelöscht / Policies / Mapping)
 3  Nextcloud → Wartungsmodus
 4  Manifest (Images, Templates, Share-Configs, settings.ini, Abweichungen)
 5  Apps anhalten, dann Datenbank-Dumps (sofort geprüft)              ┐
 6  Datenbanken → Netzwerk-Container anhalten                         │
 7  ZFS-Snapshots (je Pool atomar), btrfs-Snapshots (je Disk/Pool)    │ Unterbrechung
 8  Container starten (DBs erst "healthy"), Wartungsmodus aus         ┘
 9  Snapshots read-only einhängen: /mnt/backup-snapshots/<share>       ┐ nur mit
10  Kopia sichert jeden Share aus seinem Snapshot (Kopia läuft durch)  ┘ Kopia
11  Aushängen, aufräumen (ZFS t/w/m, btrfs Tage + Notbremse, Dumps, Logs), Mitteilung
```

Bricht ein Lauf hart ab (Absturz, `kill -9`), stehen die angehaltenen Container und der Nextcloud-Wartungsmodus in `state/`. Der nächste Start von `backup.sh` oder `setup.sh` stellt beides wieder her und meldet es. Bei einem normalen Abbruch (User Scripts „Abort“, SIGTERM) erledigt das sofort der `trap`.

## Dateien

```
/mnt/user/appdata/UnraidSecretaryOffice/
├── backup/                 der Motor (im Git)
│   ├── setup.sh            Prüfen, vorschlagen, settings.ini schreiben, Kopia einrichten
│   ├── backup.sh           der nächtliche Lauf
│   └── lib/common.sh       gemeinsame Funktionen
└── data/unraid-backup/     seine Daten (nicht im Git, nur root: 0700)
    ├── settings.ini        von setup.sh (von Hand änderbar)
    ├── state/              Sperre, Status für das Sekretariat, Abweichungen, Merkzettel
    ├── logs/               run-*.log je Lauf, check-/dryrun-/setup-*.log, latest.log
    └── dumps/<zeit>/       DB-Dumps, Manifest, ggf. flash.tar.gz
```

Auf dem Flash liegt nur der Aufruf für User Scripts (drei Zeilen). Die Dumps liegen im Datenordner, also im Share `appdata`. `backup.sh` meldet, wenn dieser Share selbst nicht gesichert wird. Einen anderen Datenordner setzt `UB_DATA`.

---

## Kopia – optional

Ohne Kopia bleibt alles auf dem Server: lokale ZFS-/btrfs-Snapshots und Datenbank-Dumps. Das hilft gegen versehentliches Löschen und kaputte Updates, aber nicht gegen Brand, Diebstahl, Überspannung oder einen Totalausfall. `setup.sh` fragt in Schritt 3, ob Kopia dazukommt, und erklärt dort:

- **Was Kopia tut:** Jede Nacht, direkt nach den Snapshots, liest Kopia die eingefrorenen Stände unter `/mnt/backup-snapshots/<share>` und lädt nur Neues hoch. Dateien werden in Blöcke zerlegt und dedupliziert (auch über Shares hinweg), auf Wunsch komprimiert.
- **Verschlüsselung:** Alles wird **vor** dem Hochladen auf dem Server verschlüsselt (AES-256-GCM oder ChaCha20-Poly1305, Schlüssel aus dem Repository-Passwort). Der Speicheranbieter sieht nur unlesbare Blöcke, weder Inhalte noch Dateinamen.
- **Ziel:** ein S3-kompatibler Speicher (z. B. Backblaze B2, Wasabi, MEGA S4, Hetzner, eigener MinIO/Garage). Kopia kann auch SFTP, WebDAV, Azure, GCS oder lokal.
- **Versionen:** Kopia behält Stände nach der Aufbewahrung (z. B. 7 täglich, 4 wöchentlich, 12 monatlich, 3 jährlich) und räumt den Rest selbst weg.
- **Die Repository-Verbindung richtest du einmal selbst in der KopiaUI ein** (Repository › S3: Endpoint, Bucket, Access Key, Secret Key, Repository-Passwort). `setup.sh` fragt diese Daten aus Sicherheitsgründen bewusst nicht ab: Zugangsschlüssel und Passwort sollen nie in Scripts, Protokollen oder `settings.ini` landen. Kopia verwahrt sie in seiner eigenen Config.
- **Ohne Repository-Passwort ist das Backup nicht wiederherstellbar**, auch nicht vom Anbieter. Das Passwort getrennt aufbewahren (Passwortmanager und auf Papier).

Ein- oder ausschalten: `setup.sh` erneut aufrufen, Schritt 3. Mit Kopia aus gibt es für Shares nur `snapshot` oder `off`. Schaltest du Kopia später ein, schlägt `setup.sh` die bisher lokalen Shares wieder für Kopia vor. Fehlt `/mnt/backup-snapshots`, legt `setup.sh` den Ordner an. Unraid hält `/mnt` im RAM; nach einem Neustart legt Docker den Ordner beim Start des Kopia-Containers wieder an.

## Kopia-Container: das eine Mapping

| | |
|---|---|
| Container Path | `/mnt/backup-snapshots` |
| Host Path | `/mnt/backup-snapshots` |
| Access Mode | **Read Only – Slave** |

Mehr braucht `unraid-backup` nicht. Weitere Daten-Mappings brauchst du nur für Quellen, die du selbst in der KopiaUI pflegst; `setup.sh` zeigt solche Quellen an.

### Warum „Read Only – Slave“ und nicht nur „Read Only“?

Das Script startet Kopia selbst, direkt nach den Snapshots (`docker exec <kopia> kopia snapshot create …`). Dieser Kopia-Prozess läuft aber **im schon laufenden Container**. Ein Container bekommt beim Start eine eigene Sicht auf die Mounts, eine Kopie des Zustands in diesem Moment. Die Snapshots hängt das Script erst nachts ein, also **nach** dem Containerstart.

- **Read Only:** Der Container sieht `/mnt/backup-snapshots` so wie beim Start, also leere Ordner. Kopia würde leere Verzeichnisse sichern. Man müsste Kopia jede Nacht anhalten, mounten und neu starten.
- **Read Only – Slave:** Neue Mounts und Unmounts auf dem Host werden in den laufenden Container weitergereicht, nur in diese Richtung (Host → Container). Kopia sieht die Snapshots sofort. KopiaUI und Wartung laufen ungestört weiter.

„Read Only“ schützt die Daten, „Slave“ macht die nächtlichen Mounts für Kopia sichtbar. Unraid übersetzt die Einstellung in `-v …:ro,slave`.

Getestet mit Docker 29, Kernel 6.18 und imagegenius/kopia 0.23:

| Fall | Im Container |
|---|---|
| Mount, der beim Containerstart schon da war | read-only, auch Unter-Mounts (Docker ≥ 25, Kernel ≥ 5.12) |
| Später angelegter Mount mit `-o ro` (ZFS-Snapshot, overlay) | sichtbar, read-only |
| Später angelegter Bind-Mount, danach `remount,ro` | sichtbar, **beschreibbar** – das ro erreicht den Container nicht |
| Bind im privaten Bereich angelegt, dort ro gestellt, dann `mount --move` | sichtbar, read-only |
| Unmount auf dem Host | verschwindet auch im Container |

Darum legt `backup.sh` jeden Bind-Mount (btrfs-Snapshots, Live-Shares) zuerst in einem privaten Bereich (`/run/unraid-backup-stage`) an, stellt ihn dort auf ro und verschiebt ihn erst dann nach `/mnt/backup-snapshots/<share>`.

Vor jeder Quelle prüft `backup.sh` in `/proc/self/mountinfo` des Containers, ob der Mount dort wirklich sichtbar ist. Wenn nicht, sichert es diese Quelle nicht, statt einen leeren Ordner hochzuladen. Beim Einrichten macht `setup.sh` einen Live-Test: Es hängt kurz ein winziges tmpfs ein und prüft, ob der Container es sieht.

### Warum PUID=0 / PGID=0 im Kopia-Template?

Snapshots müssen als **root** laufen, sonst fehlen alle Dateien, die nur ihren Besitzern gehören (Nextcloud-Daten, Datenbank-Ordner …). Images im linuxserver-Stil wie `imagegenius/kopia` starten den Kopia-Server aber als `abc` (UID 99). Jeder Kopia-Aufruf, auch ein simples `repository status`, schreibt in Cache und Logs. Läuft er als root, entstehen dort root-eigene Ordner mit Rechten 0700. Der Server kann das Repository danach nicht mehr öffnen:

```
unable to complete ReadDir:/cache/metadata/f7 despite 10 retries: open /cache/metadata/f7: permission denied
```

Abhilfe, einmalig: **PUID = 0, PGID = 0** im Kopia-Template. Dann laufen Server und Script als derselbe Benutzer, und vorhandene Dateien kann root ohnehin lesen. Die Identität im Repository (`benutzer@host`) bleibt gleich, denn sie steht in der Repository-Config und hängt nicht am Linux-Benutzer.

Solange der Server nicht als root läuft, ruft das Script Kopia nur als dessen UID auf und **startet keine Snapshots**. Es macht also nichts kaputt, und die Fehlermeldung nennt die Abhilfe.

---

## Shares

Ein Share ist die Einheit, über die du entscheidest:

| Modus | Bedeutung |
|---|---|
| `kopia` | lokaler Snapshot + Kopia (offsite); Kopia-Quelle `/mnt/backup-snapshots/<share>` |
| `snapshot` | nur lokaler Snapshot |
| `off` | nichts |

Vorschläge von `setup.sh` für neue Shares:

| Share | Vorschlag | Grund |
|---|---|---|
| `system` | off | Docker-Image/libvirt, wird neu erzeugt |
| `domains` | off | VM-vDisks laufender VMs sind nicht konsistent; VM-Backup separat |
| Time-Machine-Ziel | off | enthält schon Sicherungen anderer Rechner. Erkannt am SMB-Export „Yes/Time Machine“, an einem Time-Machine-Container (Image mit `timemachine` im Namen), der den Share einbindet, oder am Namen (`timemachine`, `time_machine`, `time-machine`, `time machine`) |
| Arbeitsverzeichnis des Kopia-Containers | off | Cache/tmp |
| Container-Daten (`appdata` oder ein Share mit Ordnern von mindestens drei Containern), auch über 500 GB | kopia | wird nicht wegen der Grösse abgeschaltet. Gross sind dort meist einzelne Ordner (Caches, Blockchains, Mediadaten); die nimmst du gleich danach aus |
| andere Shares über 500 GB (oder Messung dauert zu lange) | off | bewusst entscheiden |
| Grösse unbekannt (kein ZFS und nicht gemessen) | snapshot | nichts Grosses ungefragt offsite – messen oder bewusst entscheiden |
| Share ohne Daten (nur in der Unraid-Config) | kopia | „noch leer“; sobald Daten da sind, kommen sie mit |
| alles andere | kopia (bzw. snapshot ohne Kopia) | |

In den Container-Ordnern eines Shares (z. B. `appdata`) schlägt `setup.sh` vor, den Ordner des Kopia-Containers zu ignorieren. Auf Wunsch zeigt es die grössten Ordner, damit du Caches, Blockchains oder Mediendaten ausnehmen kannst. Jeder Ordner wird höchstens 60 Sekunden gemessen; was länger braucht, steht als „> 60 s“ ganz oben, denn das ist fast immer ein sehr grosser Ordner.

In der Share-Tabelle nennt die Spalte „Lage“ Pools mit Namen und fasst mehrere Array-Disks zusammen, z. B. `cache + 3 Disks`. Die einzelnen Disks zeigt `d <Nr>` (Details).

### Wie ein Share eingehängt wird

| Lage | Methode | Kopia sieht |
|---|---|---|
| ein ZFS-Dataset | Snapshot direkt eingehängt, Kind-Datasets darunter | `<share>/…` |
| Ordner im Pool-Wurzel-Dataset | Wurzel-Snapshot privat eingehängt, Unterordner ro gebunden | `<share>/…` |
| eine btrfs-Disk | Disk-Snapshot `-r`, Unterordner ro gebunden | `<share>/…` |
| mehrere Basen (Cache + Array, mehrere Disks) | overlayfs über alle Snapshots, Pool vorne | `<share>/…` |
| mehrere Basen **und** Kind-Datasets | je Basis ein Unterordner (overlayfs zeigt keine Unter-Mounts) | `<share>/<basis>/…` |
| XFS u. ä. (keine Snapshots) | `/mnt/user/<share>` live, ro gebunden | `<share>/…` |

---

## Container

Für die Sekunden der Snapshots werden alle laufenden Container angehalten, die in gesicherte Shares schreiben. Weiterlaufen dürfen nur:

- Kopia,
- Container, deren Pfade nur in `off`-Shares oder in Kopia-ignorierten Ordnern liegen (Anhalten brächte dort nichts),
- Container, die du ausdrücklich auf „weiterlaufen“ stellst. Schreibt so ein Container in gesicherte Daten, markiert `setup.sh` das mit ACHTUNG: Der Snapshot davon ist nur absturzkonsistent.

Reihenfolge: erst Apps, dann die Datenbank-Dumps, dann Datenbanken, zuletzt Netzwerk-Container (z. B. ein VPN, dessen Netz andere mitbenutzen). Weil die Apps schon stehen, schreibt während des Dumps niemand mehr: Dump und Dateien im Snapshot passen zusammen – auch bei Apps ohne Wartungsmodus wie Immich. Die Apps stehen dafür so viel länger, wie die Dumps dauern (meist Sekunden). Gestartet wird umgekehrt, Datenbanken erst, wenn sie „healthy“ sind.

Apps mit eigener SQLite-Datenbank (Emby, Jellyfin, Plex, *arr …) haben keinen Dump; sauber sind sie nur, wenn sie für die Snapshots angehalten werden. Medienserver (Emby, Jellyfin, Plex) schlägt `setup.sh` trotzdem zum Weiterlaufen vor, weil Anhalten laufende Streams abbräche; ihre Datenbank ist im Snapshot dann nur absturzkonsistent, was bei SQLite meist genügt.

## Datenbanken

`setup.sh` sucht in allen Containern, ob einzeln angelegt oder aus einem Compose-Stack. Zusätzlich liest es die Stack-Dateien des Compose Managers (`/boot/config/plugins/compose.manager/projects/*`, auch „indirect“-Stacks, ausgewertet mit `docker compose config` inklusive `.env`). So findet es auch Datenbank-Dienste von Stacks, die gerade nicht laufen.

Eine Datenbank wird an drei Merkmalen erkannt:

1. an **Umgebungsvariablen, die das Server-Image selbst setzt** – `PG_MAJOR`, `MARIADB_VERSION`, `MONGO_VERSION`, `REDIS_VERSION` … Das trifft auch umbenannte oder abgeleitete Images.
2. am **Image-Namen** (mariadb, mysql, postgres, pgvecto-rs, vectorchord, mongo, redis …),
3. am **Standard-Port** (3306, 5432, 27017, 6379).

Werkzeuge wie phpMyAdmin, Adminer, pgAdmin oder Exporter zählen nicht.

| Art | Vorschlag |
|---|---|
| MariaDB/MySQL, Postgres, MongoDB | logischer Dump vor dem Snapshot, sofort geprüft (Abschlusszeile, Tabellenzahl bzw. `mongorestore --dryRun`) |
| Redis, Valkey, KeyDB, Memcached | kein Dump – Zwischenspeicher; was auf Platte liegt, steckt im Snapshot |
| InfluxDB, CouchDB, Elasticsearch … | Snapshot des angehaltenen Containers |
| Stack-Dienst ohne Container | Warnung: Stack starten, `setup.sh` erneut aufrufen, dann wird der Dump vorgeschlagen |

Die Zugangsdaten für die Dumps liest das Script zur Laufzeit aus den Umgebungsvariablen des Datenbank-Containers (`MARIADB_ROOT_PASSWORD`, `POSTGRES_USER`, `MONGO_INITDB_ROOT_USERNAME` …). In `settings.ini` stehen keine Passwörter.

Die Tabelle zeigt auch, wo die Daten liegen. Rot markiert sind `Volume:<pfad>!` (Docker-Volume im Docker-Verzeichnis, nicht im Backup) und `im Container!` (bei einem Image-Update weg). Neue Datenbank-Container meldet `backup.sh` nachts als Abweichung.

Nextcloud-Container (mit `occ`) gehen während Dump und Snapshot in den Wartungsmodus. War der Wartungsmodus schon an, bricht der Lauf ab (`preexisting_maintenance = abort`); mit `continue` wird trotzdem gesichert, und der Modus bleibt danach an.

Bedienen mehrere Container dieselbe Nextcloud (z.B. `nextcloud-app` und `nextcloud-cron` mit gemeinsamer `config.php`), erkennt das Script sie an der `instanceid` als **eine** Instanz: `setup.sh` fragt sie zusammen, `backup.sh` schaltet den Wartungsmodus nur über den ersten Container ein und wieder aus. Scheitert `occ` beim Einschalten, versucht es das Script dreimal und schreibt die Meldung von `occ` ins Protokoll.

---

## setup.sh

Läuft im Terminal (SSH oder Unraid-Web-Terminal), nicht in User Scripts, weil es dort keine Eingabe gibt. Jeder Schritt erklärt, was er prüft und warum; `UB_EXPLAIN=0` kürzt das.

| Aufruf | Wirkung |
|---|---|
| `setup.sh` | alles prüfen, fragen, schreiben, Kopia einrichten |
| `setup.sh --check` | nur prüfen und berichten (inkl. Live-Test des Mappings) |
| `setup.sh --kopia` | nur Kopia-Teil mit bestehender settings.ini (Policies angleichen) |
| `setup.sh --yes` | alle Vorschläge übernehmen, ohne zu fragen (auch mit `--kopia`) |
| `setup.sh --plan` | wie `--yes`, schreibt aber nichts: Vorschläge, Begründungen (als Codes) und Prüfergebnisse nach `state/setup-plan.json` |
| `setup.sh --apply=<datei>` | Entscheidungen (JSON: settings.ini-Schlüssel wie im Plan → Wert oder Liste) über die bisherigen Werte legen, dann wie `--yes` prüfen, schreiben, Policies angleichen |

`--plan` und `--apply` sind die Schnittstelle des Setup-Assistenten von Herrn Backup („Einrichten…“). Beide melden ihren Fortschritt in `state/setup-status.json` (`mode`, `result`, `written`, Meldungen mit Schritt und Stufe). Dumps und Nextclouds gelten bei `--apply` nur, wenn sie in den Entscheidungen stehen; alte Kopia-Quellen werden nur mit `_retire_sources = yes` auf „manuell“ gestellt, gelöscht wird nie.

Umgebungsvariablen: `UB_SETUP`, `UB_YES`, `UB_EXPLAIN`, `UB_SIZE_TIMEOUT` (Sekunden je Share für `du`, 0 = nicht messen), `UB_SETTINGS`, `UB_STRIPES`.

Im Terminal ist jeder Schritt ein farbiger Balken, Tabellen haben eine unterstrichene Kopfzeile, und jede zweite Zeile ist ganz leicht hinterlegt. Für die Streifen fragt `setup.sh` das Terminal nach seiner Hintergrundfarbe; antwortet es nicht, gilt dunkel (wie im Unraid-Web-Terminal). `UB_STRIPES=light` oder `dark` legt das fest, `UB_STRIPES=off` schaltet die Streifen ab. Im Protokoll steht alles ohne Farben.

Ruf `setup.sh` erneut auf, wann immer `backup.sh` Abweichungen meldet. Bestehende Entscheidungen sind dann die Vorgaben. Umbenannte Shares erkennt `setup.sh` an der Kennung (ZFS-GUID bzw. Inode) und übernimmt ihre Einstellungen.

Was `setup.sh` nie tut: Container-Templates ändern, Kopia mit einem Repository verbinden, Zugangsdaten abfragen, Snapshots oder Daten löschen. Einzige Ausnahme beim Löschen ist eine alte Kopia-Quelle, wenn du das ausdrücklich mit `LOESCHEN` bestätigst.

## backup.sh

| Aufruf | Wirkung |
|---|---|
| `backup.sh` | voller Lauf |
| `backup.sh --check` / `UB_MODE=check` | nur Abweichungen prüfen und melden |
| `backup.sh --dry-run` / `UB_DRY_RUN=1` | Plan zeigen, nichts verändern |
| `backup.sh --no-kopia` / `UB_SKIP_KOPIA=1` | Dumps und Snapshots ja, Kopia nein |
| `backup.sh --unmount` / `UB_MODE=unmount` | alle Snapshot-Mounts lösen |
| `UB_NC_PREEXISTING=continue` | Nextcloud war schon im Wartungsmodus: trotzdem sichern, Modus danach **an** lassen |
| `UB_NO_NOTIFY=1` | keine Unraid-Mitteilungen |
| `backup.sh --about` | Name, Version, Schnittstelle, Code- und Datenordner als JSON |

### Status für andere Programme

Für Herrn Backup im Sekretariat (und jede andere Oberfläche) schreibt `backup.sh` seinen Zustand mit festen, englischen Schlüsseln nach `state/`. Die Texte im Protokoll dürfen sich ändern, diese Dateien nicht; wer sie liest, prüft `interface` (derzeit `1`).

| Datei | Inhalt |
|---|---|
| `status.json` | laufender oder zuletzt beendeter Lauf (Prüfung, Trockenlauf, Backup): `mode`, `phase`, `result` (`running`, `ok`, `warnings`, `errors`, `failed`, `aborted`), `pid`, Zeiten, Unterbrechung, Abweichungen, Kopia-Plan mit aktueller Quelle und Ergebnis je Quelle |
| `last-run.json` | dasselbe für den letzten echten Backup-Lauf |
| `history.jsonl` | eine Zeile je echtem Backup-Lauf, die letzten 200 |
| `drift.json` | Abweichungen der letzten Prüfung (`level`, `text`) |
| `setup-plan.json` | letzter Plan von `setup.sh --plan` |
| `setup-status.json` | Fortschritt und Meldungen von `setup.sh --plan` / `--apply` |

Abbrechen: `SIGTERM` an die `pid` aus `status.json`. Der `trap` beendet einen laufenden Kopia-Snapshot im Container sauber (SIGINT), startet angehaltene Container, schaltet den Wartungsmodus aus, hängt aus und setzt `result` auf `aborted`.

## Was backup.sh meldet (und nicht selbst ändert)

| Abweichung | Stufe | Folge |
|---|---|---|
| neuer Share | Warnung | wird nicht gesichert, bis `setup.sh` lief |
| Share umbenannt (gleiche GUID/Inode) | Warnung | neuer Name wird erst nach `setup.sh` gesichert |
| Share gelöscht | Warnung (bei `off`: Hinweis) | – |
| Share steht nur noch in der Unraid-Config, hatte aber Daten | Warnung | Daten weg oder verschoben? |
| Share war beim Setup leer und hat jetzt Daten | Hinweis | wird schon gesichert; `setup.sh` merkt sich die Kennung für Umbenennungen |
| Share liegt jetzt zusätzlich woanders | Hinweis | wird automatisch mitgenommen (overlay) |
| Share kann keine Snapshots mehr (z. B. XFS) | Warnung | Kopia liest live |
| neuer Container / neue Datenbank / neue Nextcloud | Hinweis / Warnung | neue Container werden angehalten, DB ohne Dump |
| Docker-Volumes bei neuem Container | Warnung | liegen im Docker-Verzeichnis, nicht im Backup |
| Kopia-Policy weicht ab | Warnung | `setup.sh --kopia` gleicht an |
| Kopia-Policy fehlt eine Share-Ignore-Regel | **Fehler** | dieser Share geht nicht an Kopia (sonst würde z. B. ein ausgenommener Riesen-Ordner hochgeladen) |
| Mapping fehlt / ohne Slave / Server nicht root | **Fehler** | Kopia wird übersprungen, Snapshots und Dumps laufen |

Dieselbe Meldung kommt nicht jede Nacht. Erneut gemeldet wird sie, wenn sich etwas ändert, und sonst alle `remind_days` Tage. Den aktuellen Stand zeigt `state/drift.txt`.

---

## settings.ini

| Abschnitt / Schlüssel | Bedeutung |
|---|---|
| `[general] mount_root` | Snapshot-Ordner für Kopia (`/mnt/backup-snapshots`) |
| `view_root` | btrfs-Snapshots durchstöbern: `<view_root>/<disk>` (Symlinks) |
| `snap_prefix` | ZFS-Snapshot-Präfix (Vorgabe `unraidbackup-`); aufgeräumt wird nur dieser, Snapshots anderer Werkzeuge bleiben unangetastet |
| `keep_runs`, `keep_logs` | Dump-Ordner bzw. Protokolle behalten |
| `keep_mounts` | `yes` = Snapshots bleiben bis zum nächsten Lauf eingehängt (dann zusätzlich User Script „At Stopping of Array“ mit `backup.sh --unmount`) |
| `[zfs] retention` | `t w m`: täglich, wöchentlich, monatlich |
| `[btrfs] keep_days`, `min_free_gb`, `snapshot_all` | Aufbewahrung, Notbremse, alle Array-Disks snapshotten |
| `[drift] ignore`, `remind_days` | nicht gemeldete Share-Muster, Erinnerungsabstand |
| `[docker] stop`, `no_stop`, `known` | `all`/`none`, Ausnahmen, bekannte Container |
| `[flash] mode` | `snapshot` (nur /boot auf ZFS) / `tar` / `off` |
| `[libvirt] mode` | `tar` (Vorgabe) = Inhalt von libvirt.img (XML, NVRAM, TPM-Zustand aller VMs) als Archiv `libvirt.tar.gz` zu den Dumps / `off` |
| `[kopia] enabled` | `yes` = Kopia an, `no` = nur lokale Snapshots und Dumps |
| `[kopia] keep_*`, `compression`, `ignore` | Policy auf `mount_root`, alle Shares erben sie |
| `[nextcloud "<container>"] preexisting_maintenance` | `abort` / `continue` |
| `[dump "<container>"] type` | `mariadb` / `postgres` / `mongodb` |
| `[share "<name>"] mode` | `kopia` / `snapshot` / `off` |
| `retention` | ZFS-Aufbewahrung nur für diesen Share |
| `kopia_retention` | Kopia-Aufbewahrung nur für diesen Share: `latest hourly daily weekly monthly annual` |
| `kopia_ignore` | Ignore-Regel relativ zum Share (mehrfach) |
| `exclude_dataset` | Kind-Dataset weder snapshotten noch sichern |
| `method` | `auto` / `live` |

**Policies pro Share:** Die Kopia-Policy auf `/mnt/backup-snapshots` gilt für alle Shares. Ein Share trägt in Kopia nur das, was abweicht (eigene Ignores, eigene Aufbewahrung). Beides steht in `settings.ini` und wird von `setup.sh` gesetzt. Die lokale ZFS-Aufbewahrung (`retention`) ist davon getrennt und gilt nur auf dem Server.

---

## Wiederherstellen

- **Einzelne Dateien von gestern:** ZFS unter `/mnt/<pool>/<share>/.zfs/snapshot/unraidbackup-…/`, btrfs unter `/mnt/btrfs-snap/<disk>/<zeit>/<share>/`.
- **Aus Kopia:** KopiaUI › Quelle `/mnt/backup-snapshots/<share>` › Snapshot › Restore/Download. Der Container sieht nur read-only-Pfade. Für eine Rücksicherung direkt auf den Server vorübergehend ein beschreibbares Ziel mappen (z. B. `/mnt/user/restore`).
- **MariaDB/MySQL:**
  `zcat dumps/<zeit>/db/mariadb_<container>_<db>.sql.gz | docker exec -i <container> sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"'`
- **Postgres:**
  `zcat dumps/<zeit>/db/postgres_<container>.sql.gz | docker exec -i <container> psql -U postgres -d postgres`
- **MongoDB:**
  `docker exec -i <container> sh -c 'mongorestore --drop --archive --gzip -u "$MONGO_INITDB_ROOT_USERNAME" -p "$MONGO_INITDB_ROOT_PASSWORD" --authenticationDatabase admin' < dumps/<zeit>/db/mongodb_<container>.archive.gz`
- **Welche Image-Stände liefen:** `dumps/<zeit>/manifest/docker-images.txt`; Templates und Compose-Projekte liegen daneben.

---

## Getestet

Getestet in einer nachgestellten Unraid-Umgebung mit:

- echtem Docker 29 und Docker Compose v5,
- imagegenius/kopia 0.23 (Filesystem-Repository),
- MariaDB 11 (auch unter eigenem Image-Namen), Postgres 16, MongoDB 7, Redis 7,
- einem laufenden und einem nicht gestarteten Compose-Stack,
- einer Nextcloud-Attrappe, einem VPN-Netzwerk-Container und einem Container mit Docker-Volume.

Durchgespielt mit und ohne Kopia sowie das Wieder-Einschalten. Echt liefen dabei Mount-Weitergabe, overlayfs, read-only-Verhalten, Abbruch per SIGTERM und `kill -9`, Umbenennungen und manipulierte Policies. ZFS und btrfs wurden über Ersatzbefehle nachgebildet.

**Noch nicht auf echter Hardware geprüft:**
- `mount -t zfs` von Snapshots als overlay-Unterlage (ein Rückfall auf Unterordner ist eingebaut),
- btrfs-`-r`-Snapshots auf verschlüsselten Disks,
- Binden von `/mnt/user/<share>` (FUSE),
- Unraid-`notify` und der User-Scripts-Aufruf.

Darum auf jedem neuen Server zuerst `setup.sh --check` und einen Trockenlauf.

## Versionen

- **2.11** – Der User-Scripts-Eintrag heisst `unraid-secretary-office_backup` wie alle Einträge des Sekretariats; der alte Eintrag `unraid-backup` zieht samt Zeitplan um.
- **2.10** – Die VM-Konfiguration aus libvirt.img (XML, NVRAM, TPM-Zustand) kommt jede Nacht als Archiv zu den Dumps und damit offsite, ohne den ganzen Share `system` zu sichern. Vorgabe auch ohne Eintrag in settings.ini.
- **2.9** – Neue Shares, deren Grösse unbekannt ist (kein ZFS, nicht gemessen – im Assistenten ohne „Grössen messen“), werden nur lokal vorgeschlagen statt für Kopia. Vorgabe für den Snapshot-Präfix ist `unraidbackup-`. Medienserver (Emby, Jellyfin, Plex) werden zum Weiterlaufen vorgeschlagen.
- **2.8** – Apps werden vor den Datenbank-Dumps angehalten, nicht erst danach: Dumps und Dateien im Snapshot passen so zusammen, auch bei Apps ohne Wartungsmodus (Immich & Co.).
- **2.7** – `setup.sh --plan` und `--apply=<datei>`: Der Setup-Assistent von Herrn Backup fragt nicht im Terminal, sondern bekommt alle Vorschläge mit Begründungs-Codes als JSON und gibt die Entscheidungen als JSON zurück. Dieselbe Prüf- und Schreiblogik wie im Terminal.
- **2.6** – Teil des Unraid Secretary Office: Code in `backup/`, Einstellungen, Zustand, Protokolle und Dumps in `data/unraid-backup/` (0700, `UB_DATA`). `--about` nennt beide Ordner.
- **2.5** – `backup.sh` schreibt seinen Zustand für andere Programme nach `state/` (`status.json`, `last-run.json`, `history.jsonl`, `drift.json`), dazu `--about`. Kopia läuft im Hintergrund, damit Abbrechen sofort greift; der Snapshot im Container wird dabei sauber beendet.
- **2.4** – Fix: Bedienen zwei Container dieselbe Nextcloud (App + Cron, gemeinsame `config.php`), fand der zweite den eben eingeschalteten Wartungsmodus vor, hielt ihn für „schon an“ und brach den Lauf ab. Beide werden jetzt an der `instanceid` als eine Instanz erkannt. Wartungsmodus mit bis zu drei Versuchen, Meldungen von `occ` landen im Protokoll. `setup.sh` ist besser lesbar: Schritte als Balken, Tabellen mit Kopfzeile und Streifen, Fragen hervorgehoben.
- **2.3** – Fix für `gone_by_id: bad array subscript`: ein Share, der nur in der Unraid-Config steht und noch keine Daten hat, brach die Umbenennungs-Erkennung ab. Time Machine wird auch am Namen und an einem Time-Machine-Container erkannt. Container-Daten wie `appdata` werden nicht mehr wegen der Grösse abgeschaltet. Die Spalte „Lage“ ist kürzer. `backup.sh` meldet einen leeren Share, der nur in der Unraid-Config steht, nicht mehr jede Nacht als gelöscht. Ordnermessung mit Zeitlimit zeigt „> 60 s“ statt einer falschen Null.
- **2.2** – Allgemeine Fassung ohne serverspezifische Übernahmen. Time-Machine-Shares werden erkannt. Eigener Snapshot-Präfix `ub-`; Snapshots anderer Werkzeuge bleiben unangetastet.
- **2.1** – Kopia optional (mit Erklärung zu Verschlüsselung, S3 und warum die Verbindung selbst in der KopiaUI angelegt wird). `/mnt/backup-snapshots` wird angelegt. Datenbanken werden in Containern und Compose-Stacks erkannt. MongoDB-Dumps.
- **2.0** – Erstfassung.
