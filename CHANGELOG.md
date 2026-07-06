# Changelog

## Unveröffentlicht

### Sicherheit
- **Bundle: Link-Member werden vor der Extraktion abgelehnt.** Archive mit
  Symlink-/Hardlink-Einträgen (`l`/`h` im tar-Inhaltsverzeichnis) werden per
  `tar -tvf`-Vorab-Check abgewiesen, bevor entpackt wird — bisher griff die
  Symlink-Prüfung erst nach der Extraktion. Die nachgelagerte Prüfung bleibt
  als zweite Verteidigungslinie für den PharData-Fallback bestehen.

### Geändert
- **Warnung bei großen `.json`-Importdateien:** ab 16 MB warnen Import-Konsole und
  Log vor dem Vollparse und empfehlen das streamende JSONL-Format (`--jsonl`).
  Der harte memory_limit-Guard bleibt unverändert. README-Abschnitt „Chunked
  Verarbeitung" auf den Stand nach 2.3.0 gebracht (JSONL streamt, `.json` parst
  voll; Hinweis zu Auxiliary-Tabellen inkl. Stellschrauben).

### Neu
- **Englische README** als Vorbereitung der TER-Veröffentlichung: `README.md` ist jetzt
  die englische Fassung, die bisherige deutsche Fassung liegt unverändert unter
  `README.de.md` (Sprach-Hinweis oben in beiden Dateien).
- **Englische CLI-/Log-Meldungen:** Konsolen-Ausgaben, Exception- und Logger-Texte in
  `Classes/**` sind jetzt englisch; Code-Kommentare bleiben unverändert deutsch.
- **reST-Handbuch für docs.typo3.org** (`Documentation/` mit `guides.xml`, englisch):
  Introduction, Installation, Quick start, Commands, Configuration, Security.
  Mit dem offiziellen `render-guides`-Container fehlerfrei gerendert.
- **TER-/Packagist-Feinschliff:** englische `description` + `keywords` + `support`-Links
  in `composer.json`; `ext_emconf.php` mit englischer Beschreibung, PHP-Constraint
  (8.2–8.4), `suggests: workspaces`, Kategorie `misc`; Extension-Icon
  (`Resources/Public/Icons/Extension.svg`).

### Intern
- **`SigningKeyProvider` extrahiert:** die Beschaffung des Signatur-Schlüsselmaterials
  (ExtConf/Umgebung) ist aus `IntegrityService` in eine eigene, abhängigkeitsfreie
  Klasse gewandert — der einzige rohe `$GLOBALS`/`getenv()`-Konfig-Zugriff ist damit
  gekapselt. Keine Verhaltensänderung.
- **`DryRunPreviewRenderer` extrahiert:** die Dry-Run-Präsentation (Diff-Vorschau,
  Asset-Preflight) ist aus `ImportCommand` (452 → 293 Zeilen) in einen eigenen
  Renderer gewandert. Keine Verhaltensänderung.
- **`ImportService` zerlegt** (Prüfbericht F-13, reines Refactoring ohne Verhaltensänderung):
  Aufteilung in fokussierte Kollaboratoren `RecordDataBuilder` (Record-Aufbereitung,
  von der Registry mitgenutzt statt Duplikat), `ExistingRecordLocator` (Herkunfts-Mapping-
  Lookup, von Import + Diff geteilt), `DiffAnalysisService` (Dry-Run-Differenzanalyse) und
  `SlugRegenerationService`. `ImportService` von ~1360 auf ~1020 Zeilen; der transaktionale
  Streaming-Schreibkern blieb unangetastet. Abgesichert über die Functional-Suite
  (SQLite + MariaDB) und den Real-World-E2E.

## 2.3.0 — Streamender Import, Push-Transport & Registry-Delta (2026-07-05)

Feature-Release. **Kein Schema-Update nötig** (keine neuen DB-Spalten). Abgesichert
mit der Functional-Suite (SQLite + MariaDB) und dem Real-World-E2E über zwei Instanzen
(inkl. neuer push/Inbox-Stufe).

### Neu
- **Streamender Import (JSONL):** JSONL-Importdateien werden records-weise verarbeitet,
  statt die dekodierte Datei komplett in den Speicher zu laden. Der Peak ist damit von
  der Datei-*Größe* entkoppelt und hängt nur noch an der Record-*Zahl* (UID-Map, ints).
  Nachweis (isoliert, 43-MB-JSONL / 180 000 Records): Voll-Dekodierung ~276 MB vs.
  Streaming ~4 MB Peak; der Import läuft dadurch auch unter knappem `memory_limit`.
  JSON bleibt unverändert in-memory (mit Größen-/Memory-Guard). Umgesetzt über
  `JsonlStreamReader` + `ManifestReader`-Abstraktion (In-Memory für JSON, Streaming
  für JSONL) inkl. streamender Prüfsummen-/Signaturverifikation ohne Vollparse.
  Hinweis: Registry-, FAL- und IRRE-Tabellen (klein) werden weiterhin materialisiert;
  gestreamt werden `pages`/`tt_content` (der Speicherhebel). Das Post-Import-Event
  (`ModifyImportDataEvent`) trägt jetzt die relationalen Aux-Daten plus die vollständige
  UID-Map – nicht mehr den pages/tt_content-Bulk (dessen Ergebnis steht in der UID-Map).
- **Ausgehender Transport `push` + Inbox:** `--transport=push` erzeugt ein signiertes
  Bundle und legt es **atomar** (`.incoming/*.part` → `rename`) in eine Outbox ab
  (`--outbox=<pfad>` oder `assetTransports.<name>.outbox`); der Transfer über die
  Zonengrenze bleibt Operator-Sache (kein Netzwerk-/Empfangskanal im Core).
  `impexpnl:inbox` listet eingegangene Pakete **read-only** (`--json`); der Import bleibt
  ein separater, expliziter Schritt (Empfang ≠ Anwendung, kein Auto-Import).
- **`--transport` löst benannte `assetTransports` auf:** Beim Export wird ein benannter
  Transport-Eintrag aus der YAML-Config (inkl. dessen Optionen) angewandt; CLI-Optionen
  haben Vorrang. Bisher wirkte auf der Export-Seite nur der reine Typ.
- **Delta-Idempotenz für Registry-`record`-Tabellen (Multi-Source):** Im
  Delta-Modus werden bereits importierte Registry-Records (z. B. `sys_redirect`,
  News, tt_address) über das Herkunfts-Mapping `tx_impexpnl_uid_map` aufgelöst
  und bei Gleichheit übersprungen bzw. aktualisiert — statt wie bisher bei jedem
  Lauf dupliziert. Voraussetzung ist `uid_remap: true`. Gematchte Bestands-Records
  gehören nicht zur Rollback-Basis (ein Undo löscht weiterhin nur, was der
  jeweilige Import selbst angelegt hat). Der deklarative Profil-Contract prüft
  die Idempotenz jetzt für alle Profile (Klausel 3 inkl. Mapping-Zählung).
- **Inkrementelles Bundle (`--bundle-baseline=<manifest>`):** Beim Bundle-Export
  kann das Asset-Manifest des Ziels (`impexpnl_assets.json`) als Baseline
  übergeben werden; dort bereits inhaltsgleich (sha256) vorhandene Dateien werden
  nicht eingepackt. Fehlt eine übersprungene Datei wider Erwarten, meldet der
  Import das über den Asset-Preflight (Exit-Code `7`) — kein stiller Verlust.
  Unlesbare/fehlende Baselines werden ignoriert (Bundle wird vollständig gepackt).
- **`SECURITY.md`:** Meldeweg für Sicherheitslücken (GitHub Private Vulnerability
  Reporting), unterstützte Versionen, Reaktionszeiten.

### Geändert
- `ROADMAP.md` wird nicht mehr im Repository veröffentlicht (internes
  Arbeitsdokument); maßgeblich sind CHANGELOG und Release Notes.

## 2.2.0 — Sicherheit & Betriebsstabilität (2026-07-03)

Sicherheits-Härtung nach interner Code-Review (`Prüfbericht.md`, F-01–F-14), Fokus:
Import über ein Sicherheitsgefälle — plus acht Real-World-Funde aus dem neuen
E2E-Release-Ritual (zwei echte Instanzen, MariaDB, 391 MB Assets).
**Schema-Änderung:** neue Spalte `tx_impexpnl_lock.owner` → nach dem Update
`vendor/bin/typo3 database:updateschema` ausführen.

### Sicherheit
- **Signaturpflicht (`import.require_signature`):** Importdateien ohne
  `hmac-sha256:`-Signatur werden abgewiesen (Exit-Code `4`) — auch Dateien ganz ohne
  `_meta.checksum`. `impexpnl:check` warnt bei Signing-Key ohne Signaturpflicht und
  meldet einen Fehler bei Signaturpflicht ohne Key.
- **Downgrade-Sperre:** Ist ein Signing-Key konfiguriert, sind bloße `sha256:`- und
  Legacy-Prüfsummen bei der Verifikation ungültig — eine manipulierte Datei kann die
  HMAC-Prüfung nicht mehr per neu berechneter Prüfsumme umgehen.
- **Bundle-Härtung:** Asset-Dateien aus einem `.tar.gz`-Bundle werden erst NACH
  erfolgreicher Prüfsummen-/Signaturprüfung platziert; Quelle ist ausschließlich das
  signierte Asset-Manifest (`assets`-Block), jede Datei wird gegen ihre signierte
  SHA256 verifiziert. Identifier werden gegen Path-Traversal geprüft
  (`..`/Backslash/NUL + realpath-Containment), Bundles mit Symlink-Einträgen werden
  abgelehnt, Entpacken mit `--no-same-owner --no-same-permissions`.
- **Import-Lock mit Inhaber-Token** (`tx_impexpnl_lock.owner`): Heartbeat und
  Freigabe wirken nur noch auf den eigenen Lock. Wurde die eigene Lock-Zeile
  zwischenzeitlich entfernt (Stale-Reap/unlock), bricht der Import mit Exit-Code `3`
  ab, statt parallel zu einem fremden Import zu schreiben. `impexpnl:unlock` löst
  weiterhin jeden Lock.
- **PII-Pseudonymisierung:** Die Sanitize-Strategie `hash` nutzt HMAC-SHA256 mit
  `export.sanitize.hash_key` bzw. `IMPEXPNL_SANITIZE_KEY` (Schlüssel wird nicht
  mitexportiert). Ohne Schlüssel ungesalzenes SHA256 (statt bisher SHA1) plus
  Log-Warnung.
- **Kompressor-Allowlist:** `tar --use-compress-program` akzeptiert nur noch
  `gzip`/`pigz`; andere Werte fallen mit Warnung auf `gzip` zurück.
- **Pfad-Containment:** Alle Verzeichnis-Prüfungen (Import-Quelldatei,
  Export-Zielpfad, Bundle-Cleanup, Profile) vergleichen mit Verzeichnistrenner —
  Nachbarpfade mit gleichem Präfix gelten nicht mehr als „innerhalb".
- **Dateirechte:** Export-Artefakte (JSON/JSONL, Asset-Listen, CSV, Bundle-Archiv)
  werden auf `0600` beschränkt.
- **PID-Containment:** Registry-Records und IRRE-Relationen werden nur noch auf
  Seiten geschrieben, die dieser Import selbst angelegt/gemappt hat. Der bisherige
  Fallback auf die unveränderte Quell-PID entfällt — Records ohne auflösbare
  Ziel-Seite werden mit Warnung übersprungen. Ein manipulierter Export kann damit
  keine Records (z. B. Redirects) an beliebige Stellen des Zielsystems schreiben.
- **Tabellen-Allowlist (`allowedTables`) pro Transfer-Profil:** begrenzt, welche
  Registry-Tabellen ein Import anfassen darf (nicht gesetzt = alle, leere Liste =
  keine; `pages`/`tt_content`/FAL unberührt). Übersprungene Tabellen werden geloggt.
- **Dateigrößen-Limit (`import.max_file_size_mb`, Standard 512, 0 = aus):**
  Übergroße Importdateien werden vor dem In-Memory-Parsen mit klarer Fehlermeldung
  abgewiesen statt den Prozess in ein OOM (Pod-Kill, hängender Lock) zu laufen.
  Gilt auch für die aus einem Bundle entpackte Export-Datei.

### Neu
- **Schlüssel-Rotation:** Optionale Key-ID (`signingKeyId`/`IMPEXPNL_SIGNING_KEY_ID`)
  erweitert die Signatur zu `hmac-sha256:<key-id>:<hash>`; Alt-Schlüssel bleiben über
  `signingKeys` (Map key-id → Schlüssel) während der Übergangszeit verifizierbar.
  Downgrade-Schutz greift, sobald irgendein Schlüssel (aktiv oder Alt) konfiguriert ist.
- **README-Kapitel „Einsatz über ein Sicherheitsgefälle":** Konfigurations- und
  Ablauf-Leitfaden (Key-Management, Signaturpflicht, Transfer-Profil, Schleusenprozess,
  Rollen, Revisionsnachweis).

### Geändert
- **ManifestLoaderService:** Laden/Validieren der Importdatei (Pfad-Containment,
  Größenlimit, Parsing, Signaturprüfung) aus dem ImportService in einen eigenen
  Service extrahiert (Beginn der Zerlegung, kein Verhaltensunterschied).
- Fehlschlägt das Anlegen des lokalen Datei-Locks (z. B. read-only Filesystem),
  wird das jetzt als Warnung geloggt statt still übergangen (DB-Lock trägt die
  Concurrency-Garantie weiter).

### Behoben (Funde aus dem Real-World-/E2E-Test, 2 Instanzen, 2.000 Seiten / 6.000 Inhalte / 391 MB Assets)
- **Lock-Heartbeat-Fehlabbruch:** MySQL/MariaDB melden 0 „affected rows", wenn zwei
  Heartbeats in derselben Sekunde laufen (Wert unverändert) – der Import brach dann
  fälschlich mit „Lock verloren" ab (in SQLite-CI unsichtbar). Der Heartbeat weist
  den Lock-Verlust jetzt per Nachweis-SELECT nach, bevor er abbricht.
- **Delta-Idempotenz unter Realbedingungen:** Der Feldvergleich meldete JEDEN Record
  als geändert (8 002 Updates statt 8 002 Skips). Ursachen: `''` vs. `'0'`
  (DB-Default vs. Export-Leerwert, z. B. `fe_group`) und der beim Import ohnehin
  regenerierte `slug`. Vergleich normalisiert jetzt Leerwerte und ignoriert `slug`.
- **Stiller OOM-Tod:** Eine 21-MB-Datei bei `memory_limit=128M` beendete den Prozess
  mit Exit 255 ohne jede Meldung. Neue Speicher-Plausibilitätsprüfung vor dem Parsen
  bricht mit klarer Fehlermeldung und Handlungsempfehlung ab.
- **`impexpnl:undo` läuft jetzt unter dem Import-Lock:** Ein Rollback parallel zu
  einem laufenden Import wird mit Exit-Code 3 abgewiesen (vorher ungeschützt).
- **`impexpnl:unlock --json`** ergänzt (war in der README dokumentiert, fehlte aber;
  ohne `--force` wird im JSON-Modus nur gemeldet, nicht gelöst).
- **`impexpnl:check`:** Fehlende Tabellen optionaler Profile (z. B. `sys_redirect`,
  `tt_address` nicht installiert) sind jetzt Warnungen statt Fehler – das
  Preflight-Gate bricht auf schlanken Installationen nicht mehr grundlos.
- **Checkpoint-Atomarität:** Batch-Daten und Protokoll-Fortschreibung committen
  jetzt in EINER Transaktion. Vorher konnte ein SIGKILL zwischen Batch-Commit und
  Checkpoint committete Records ohne Undo-Eintrag hinterlassen (im Kill-Test real
  aufgetreten: 32 verwaiste Seiten).
- **Rootline-Cache-TRUNCATE zerstörte Transaktionen (`RootlineCacheGuard`):**
  Der TYPO3-Kern flusht beim Anlegen/Löschen von Seiten den Rootline-Cache; mit dem
  DB-Cache-Backend ist das ein `TRUNCATE cache_rootline` **pro Record** – und
  TRUNCATE ist in MySQL/MariaDB ein implizites COMMIT. Die Batch-Transaktionen von
  Import UND Rollback waren dadurch auf MySQL/MariaDB wirkungslos (Atomarität und
  fsync-Bündelung). Während Import/Rollback wird der Rootline-Cache jetzt
  prozesslokal auf ein Memory-Backend umgelenkt und die DB-Cache-Tabellen werden
  nach Abschluss einmalig geleert. Nebeneffekt: Tausende TRUNCATE-Statements pro
  Lauf entfallen.

### Veraltet
- **Legacy-Prüfsummenformat (Export-Format 1** aus ImpExpNL 1.x, roher SHA256 nur
  über `pages`+`tt_content`): wird beim Import noch akzeptiert, löst aber eine
  Log-Warnung aus und **wird mit Version 3.0 entfernt**.

## 2.1.0 — Härtung (2026-06-21)

Stabilitäts- und Korrektheits-Härtung des Bestands, breit mit Functional-Tests
abgesichert. Keine Schema-Änderungen, keine API-Brüche. Enthält neue, abwärts-
kompatible Features (differenzierte Exit-Codes, `--conflict=abort`).

### Behoben
- **Übersetzungs-Import:** Relations-Container-Felder (inline/file/category, die nur
  Zähler statt UID-Listen enthalten) brachen den `DataMapProcessor` bei Übersetzungen
  (`trimExplode … int given`). Diese Felder werden in `buildRecordData` nicht mehr als
  Datamap-Werte übergeben.
- **Übersetzungs-Import:** `l10n_parent` / `l18n_parent` wurden nicht auf die neuen
  Eltern-UIDs aufgelöst (blieben 0). Ein Nachpass (`applyL10nFixups`) schreibt sie nach
  dem Batch auf die remappten Eltern um.
- **Workspace-Rollback:** Der Rollback lief im Live-Workspace (0) und ließ die im
  Workspace angelegten Versionen liegen. `RollbackService` initialisiert den Kontext jetzt
  mit der `workspace_id` aus dem Import-Protokoll → Workspace-Importe werden rückstandsfrei
  zurückgenommen.
- **FAL-Referenz-Import:** Referenz-Metadaten (`crop`, `alternative`, `title`,
  `description`, `link`, `sorting_foreign`) gingen beim Import verloren; sie werden nun
  übernommen. Eine auf dem Zielsystem fehlende Datei wird ohne DataHandler-Fehler
  übersprungen.

### Geändert
- **Rollback-Sicherheit:** Nach dem Import lokal geänderte Ziel-Records (neuerer `tstamp`)
  führen jetzt zum **Abbruch** des Rollbacks (mit Auflistung), statt nur zu warnen. Mit
  `impexpnl:undo --force` wird trotzdem gelöscht; der Auto-Rollback nach einem
  Importabbruch nutzt diesen Pfad für seine eigenen Records.

### Performance
- **Rollback in einer DB-Transaktion** gebündelt (wie der Import): die vielen einzelnen
  DataHandler-Deletes liefen zuvor im Autocommit. Messung (SQLite, 1.000 Seiten / 5.000
  Inhalte): Rollback **~135 s → ~29 s**; kleine Klasse ~13 s → ~2,6 s. Der Rollback liegt
  damit wieder in der Größenordnung des Imports.
- **Performance-Baseline** dokumentiert (`Documentation/PERFORMANCE.md`) + reproduzierbar
  über `PerformanceBaselineTest` (Größenklassen small/medium/large, JSON vs. JSONL,
  Export-/Import-/Rollback-Dauer + Speicher-Peak) als Regressionsschutz.

### Neu
- **Differenzierte Exit-Codes** für CI/CD (`Domain\ExitCode` + typisierte
  `Exception\*`): `0` ok, `1` generisch, `2` ungültige Config/Profil, `3` Lock aktiv,
  `4` Prüfsumme/Signatur, `5` Konflikte (mit neuer Strategie `--conflict=abort`),
  `6` Teilimport zurückgerollt. Code `7` (Assets fehlen) ist reserviert. Mit `--json`
  steht der Code zusätzlich im Feld `exitCode`.
- **`--conflict=abort`**: bricht den (Delta-)Import beim ersten Konflikt ab (Exit-Code 5),
  inkl. Auto-Rollback bereits geschriebener Teil-Records.

### Doku
- README: neue Sektion **„Grenzen & Nicht-Ziele"** (kein DB-Dump-Ersatz, kein generischer
  Import ohne Profil, kein Datei-Transfer, kein Merge-Tool, Rollback ≠ Snapshot-Restore usw.).
- README: Installation **Composer-first** geordnet (`composer require` als Hauptweg; Path-/VCS-Repo
  für Entwicklung, TER optional, DDEV-Demo nur zum Ausprobieren). Die beiden Exit-Code-Tabellen
  zu einer kanonischen zusammengeführt.
- README: Titel neutral auf „TYPO3 v14 (2.x)" (keine harte Versionsnummer mehr im Kopf).
- README: Abschnitt **„Systemprüfung"** auf den aktuellen `check`-Stand gebracht (Registry-Preflight
  wie `validate-config`, Laufzeit-Checks, Exit-Code `2` statt `1`, Verweis auf die Exit-Code-Tabelle).

### Tests
- Neu: `DryRunMatchesImportTest`, `RollbackSafetyTest`, `FalEdgeCasesTest`,
  `LanguageImportTest`, `ImportLockTest`, `WorkspacePublishTest` (WS-Import/Delta/Publish/
  Rollback), `ExitCodeTest`.
- Neu: Profil-Contract-Harness (`Tests/Functional/Profile/AbstractProfileContract`) mit
  Pflichtklauseln (Export/Import/Delta-Idempotenz/Rollback) und optionalen Klauseln
  (Link-Rewrite/Kategorie/FAL); `CoreProfileContractTest` für Seiten + Inhalte.
- CI: zusätzlicher Functional-Lauf gegen **MariaDB** (10.11 + 11.4) neben SQLite.
- Ergänzte Edge-Cases: Dry-Run-Parität unter `conflict=skip` (Prognose „geändert" =
  aktualisiert + konfliktbedingt übersprungen), Rollback toleriert bereits gelöschte
  Ziel-Records, FAL-Import mehrerer Referenzen auf dieselbe Datei.
- **Contract-Tests für die mitgelieferten Registry-Profile**: `RedirectProfileContractTest`
  (`sys_redirect`: Export/Remap/`target`-Link-Rewrite/Rollback) und `CategoryProfileContractTest`
  (`sys_category_record_mm`: Pfad-Mapping/Delta-Idempotenz/Rollback). Dienen als Vorlage für
  eigene Profile. Dafür `typo3/cms-redirects` als `require-dev` aufgenommen.

## 2.0.0

Kompatibilität mit **TYPO3 v14 LTS**. Die v13.4-Linie wird im Branch `13.x` (Releases `1.x`) weitergepflegt.

### Geändert
- `composer.json`: `typo3/cms-core` auf `^14.0`, `typo3/testing-framework` auf `^9.3`, `typo3/cms-workspaces` auf `^14.0`, `saschaegerer/phpstan-typo3` auf `^3.0`.
- Paket-Metadaten v14-konform (#108345): hartes Root-`version` entfernt, Version unter `extra.typo3/cms.version` deklariert (plus `providesPackages`).
- `ext_emconf.php`: Constraint auf `14.0.0-14.99.99`.
- `rector.php`: TYPO3-Set auf `UP_TO_TYPO3_14`.
- CI-Matrix um PHP 8.4 erweitert; `memory_limit=512M` für Functional-Tests.

### Architektur
- Herkunfts-Mapping aus den Core-Tabellen herausgelöst: Die Spalten
  `tx_impexpnl_remote_uid` auf `pages`/`tt_content` entfallen zugunsten der
  Tabelle `tx_impexpnl_uid_map` (Quellsystem + Quell-UID → Ziel-UID). Vorteile:
  Core-Tabellen bleiben sauber, das Mapping gilt einheitlich für alle Tabellen,
  Quellsysteme sind über `source_id` unterscheidbar (Multi-Source), und ein
  DataHandler-Copy dupliziert keine Herkunft mehr. `impexpnl:migrate-legacy-schema`
  überführt bestehende `remote_uid`-Spalten in die neue Tabelle (`--drop-legacy`
  entfernt die Alt-Spalten). Neue Konfiguration `source_id` (Default: leer).
- `findLatest()` der Import-Logs ist jetzt eindeutig sortiert (Tie-Break über
  `uid`), sodass bei zwei Importen in derselben Sekunde verlässlich der jüngste
  zurückgerollt wird.

### Behoben / v14-API
- `FalResolverService`: `StorageRepository::getStorageObject()` statt der in v14 entfernten `ResourceFactory::getStorageObject()`.
- `impexpnl:import`: eigene `--verbose`-Option entfernt (kollidierte in Symfony 7 / TYPO3 v14 mit dem globalen `-v`); Feld-Diff läuft nun über die eingebaute Verbosity (`-v`).
- `impexpnl:import`: Ziel-PID `0` (Wurzel) wird nicht mehr fälschlich als „fehlt" behandelt.

### Performance
- Massenimport: `DataHandler`-Läufe (Seiten-/Inhalts-Batches, Slug, IRRE) je in einer DB-Transaktion gebündelt — ~70 s → ~15 s pro 1000 Seiten (SQLite-Messung). Logging/Fehlererkennung unverändert.

### Tests
- Neu: FAL-Referenz-Import (`FalReferenceImportTest`) und Console-Command-Smoke-Tests (`CommandSmokeTest`).

### Hinweise
- Keine funktionalen Code-Anpassungen an den Klassen nötig: Commands nutzen bereits `#[AsCommand]` + Constructor-DI, DI-Container via `autoconfigure`, keine Frontend-Abhängigkeit (`TypoScriptFrontendController`-Wegfall irrelevant), DataHandler-Nutzung ohne die in v14 entfernten Properties.

## 1.0.0

Erste Version als **ImpExpNL** — strukturierter Export/Import von TYPO3-Seitenbäumen
zwischen Instanzen, ausgelegt für **TYPO3 v13.4 LTS** und den Government Site Builder 11
(GSB 11), Doctrine DBAL 4.

### Features
- Export/Import ganzer oder partieller Seitenbäume (pages, tt_content, registrierte Tabellen).
- Delta-Modus (identische Records überspringen) inkl. Konfliktstrategien (overwrite/skip/ask).
- UID-Remapping, Link-Rewriting (`t3://page`), FAL-Referenzen über Asset-Liste.
- Rollback/Undo, Import-Lock (clusterfähig), Integritäts-/HMAC-Signatur.
- JSON und JSONL (zeilenweise, speicherschonend für große Bäume).
- CLI-first: `impexpnl:*`-Commands, pipeline-tauglich (Exit-Codes, dry-run).

### Migration aus „robbi_copy"
- `impexpnl:migrate-legacy-schema` übernimmt das Alt-Schema
  (`tx_robbicopy_*` → `tx_impexpnl_*`); idempotent, `--drop-legacy` entfernt die Altbestände.
- Voraussetzung: zuvor neues Schema anlegen (`extension:setup` / DB-Compare).
