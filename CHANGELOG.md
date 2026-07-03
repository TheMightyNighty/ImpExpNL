# Changelog

## 1.3.0 — Sicherheit & Betriebsstabilität (Backport aus 2.2.0) (2026-07-03)

Backport der sicherheits- und stabilitätskritischen Fixes aus der v14-Linie
(v2.2.0, dortiger Sicherheits-Maßnahmenplan F-01–F-14 und Real-World-Funde
E-01–E-08; siehe `Prüfbericht.md` auf `main`). **Schema-Änderung:** neue Spalte
`tx_impexpnl_lock.owner` → nach dem Update `vendor/bin/typo3 extension:setup`
bzw. Datenbank-Schema-Update ausführen.

### Sicherheit
- **Signaturpflicht (`import.require_signature`):** Importdateien ohne
  `hmac-sha256:`-Signatur werden abgewiesen — auch Dateien ganz ohne
  `_meta.checksum` (das Weglassen der Prüfsumme ist kein Umgehungsweg mehr).
- **Downgrade-Sperre:** Ist ein Signing-Key konfiguriert, sind bloße `sha256:`-
  und Legacy-Prüfsummen bei der Verifikation ungültig.
- **PID-Containment:** Registry-Records und IRRE-Relationen werden nur noch auf
  Seiten geschrieben, die dieser Import selbst angelegt/gemappt hat; der Fallback
  auf die unveränderte Quell-PID entfällt (Records ohne auflösbare Ziel-Seite
  werden mit Warnung übersprungen).
- **Import-Lock mit Inhaber-Token** (`tx_impexpnl_lock.owner`): Heartbeat und
  Freigabe wirken nur auf den eigenen Lock; eine verlorene Lock-Zeile bricht den
  Import ab, statt parallel zu einem fremden Import zu schreiben (inkl. Schutz
  gegen die MariaDB-affected-rows-Semantik bei Heartbeats in derselben Sekunde).
  `impexpnl:unlock` löst weiterhin jeden Lock.
- **Pfad-Containment mit Verzeichnistrenner** (Import-Quelldatei, Export-Zielpfad,
  Profile) — Nachbarpfade mit gleichem Präfix gelten nicht mehr als „innerhalb".
- **Dateirechte:** Export-Artefakte werden auf `0600` beschränkt.

### Behoben
- **Delta-Idempotenz unter Realbedingungen:** Der Feldvergleich meldete Records
  wegen `''`/`'0'`-Repräsentation (z. B. `fe_group`) und des beim Import
  regenerierten `slug` dauerhaft als geändert. Vergleich normalisiert jetzt
  Leerwerte und ignoriert `slug` — wiederholte Delta-Läufe sind wieder ein No-Op
  (real verifiziert: 8 001 skipped auf dem 2-Instanzen-Setup).
- **`RootlineCacheGuard`:** Der TYPO3-Kern flusht beim Anlegen/Löschen von Seiten
  den Rootline-Cache per `TRUNCATE` pro Record — in MySQL/MariaDB ein implizites
  COMMIT, das die gebündelten Import-/Rollback-Transaktionen aufbricht (der
  Rollback-Speedup aus 1.2.0 war auf MariaDB wirkungslos). Der Cache wird während
  Import/Rollback prozesslokal auf ein Memory-Backend umgelenkt und die
  DB-Cache-Tabellen werden am Ende einmalig geleert.
- **Speicher-/Größen-Guard:** `import.max_file_size_mb` (Default 512, 0 = aus)
  plus Plausibilitätsprüfung gegen das PHP `memory_limit` VOR dem Parsen — statt
  stillem OOM-Tod ohne Fehlermeldung.
- **`impexpnl:undo` läuft unter dem Import-Lock** (Rollback parallel zu einem
  laufenden Import wird abgewiesen).
- Fehlschlägt das Anlegen des lokalen Datei-Locks (z. B. read-only Filesystem),
  wird das als Warnung geloggt statt still übergangen.

### Neu
- **`impexpnl:unlock --json`** (war in der README dokumentiert, fehlte aber).
- **`Build/test-functional-mariadb.sh`:** Functional-Suite gegen echtes MariaDB
  (Release-Pflichtlauf; die SQLite-CI zeigt MariaDB-Fehlerklassen nicht).

Nicht übernommen (Features der 2.x-Linie ohne Gegenstück in 1.x): Bundle-Transport-
Härtung, Transfer-Profile/`allowedTables`, Sanitize-HMAC, Key-Rotation,
Batch-Checkpoint-System. Hinweis: Ohne Checkpoint-System hinterlässt ein harter
Crash (SIGKILL/OOM) in der 1.x einen nicht per `undo` rückrollbaren Teilimport —
dokumentierte Einschränkung dieser Linie.

## 1.2.0 — Kategorie-Relationen & Recovery (2026-07-01)

Backport realer Bugfixes aus der v14-Linie (`main`) plus v13-spezifische Robustheit.
Abgesichert mit der Functional-Suite (32 grün) und einem 2-Instanzen-Real-World-Test
(2000 Seiten / 2000 Assets, Export → Cross-Instance-Import → Abbruch/Wiederanlauf →
Rollback). Keine Schema-Änderungen, keine API-Brüche.

### Behoben
- **FAL-Zählfeld beim Import:** Der Import legt `sys_file_reference` standalone an, pflegte
  aber nicht den denormalisierten Zähler am Eltern-Feld (z. B. `tt_content.image`). Blieb er
  0, verwarf ein späterer Backend-Save die FAL-Relation. Der Zähler wird jetzt verifizierend
  nachgezogen – inkl. Drift nach unten über den Upsert-Delete-Pfad.
- **Kategorie-Relationen registrierter Record-Tabellen:** `sys_category`-MM wurde für
  registrierte Record-Tabellen (z. B. `tx_news_domain_model_news`) still NICHT exportiert
  (`uid_foreign`-Matching lief nur gegen `pages`/`tt_content`). Export/Import jetzt zweiphasig
  (Records vor MM); das Matching bezieht generisch alle registrierten Record-UIDs ein.
- **Zählfelder beim Registry-Record-Import:** Relations-Container-Zählfelder
  (category/inline/file) wurden an den DataHandler durchgereicht (`categories: 1` erzeugte
  eine Falsch-Relation zu Kategorie-UID 1). Sie werden jetzt per TCA ausgeblendet.
- **`impexpnl:unlock` nach hartem Crash:** unlock entfernte nur den DB-Lock; ein verwaister
  Datei-Lock (`var/impexpnl_import.lock`) blockierte den Wiederanlauf. unlock räumt jetzt
  DB- UND Datei-Lock ab (auch wenn nur der Datei-Lock existiert).

### Robustheit
- Die Table-Registry überspringt registrierte, aber nicht installierte Tabellen still –
  optionale Tabellen (z. B. `tx_news_*`) können in der Config stehen, ohne Installationen
  ohne die Extension mit Warnungen zu fluten.

### CI
- Dependabot (composer + github-actions); `actions/checkout` v4 → v7.

## 1.1.0 — Härtung (2026-06-21)

Backport der v14-Härtung (`main`) auf die v13.4-Linie. Reale Bugs, breit mit
Functional-Tests abgesichert. Keine Schema-Änderungen, keine API-Brüche.

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
- **Import in DB-Transaktionen** gebündelt (Nachzug der v14-Optimierung, die auf v13 fehlte):
  alle DataHandler-Läufe (Seiten/Inhalte, Slug, IRRE) laufen je in einer Transaktion statt
  im Autocommit. Messung (SQLite, 1.000 Seiten / 5.000 Inhalte): Import **~147 s → ~40 s**.
- **Rollback in einer DB-Transaktion** gebündelt (wie der Import): die vielen einzelnen
  DataHandler-Deletes liefen zuvor im Autocommit. Messung (SQLite, 1.000 Seiten / 5.000
  Inhalte): Rollback **~135 s → ~36 s**; kleine Klasse ~2,4 s. Der Rollback liegt damit
  in der Größenordnung des Imports.

### Tests
- Neu: `LanguageImportTest`, `RollbackSafetyTest`, `FalEdgeCasesTest`,
  `WorkspacePublishTest` (WS-Import/Delta/Publish/Rollback), `PerformanceBaselineTest`
  (small/medium/large, Export-/Import-/Rollback-Dauer + Peak als Regressionsschutz).
- `.php-cs-fixer`: Demo-Projekt (`Build/demo`) vom Coding-Standards-Check ausgenommen.
- CI: zusätzlicher Functional-Lauf gegen **MariaDB** (10.11 + 11.4) neben SQLite (lokal
  gegen MariaDB 11.4 verifiziert: 32 Tests grün).
- README: Titel neutral auf „TYPO3 v13.4 & GSB 11 (1.x)".

## 1.0.2

Hardening der CLI-Befehle (TYPO3 v13.4 / Symfony 6). Beim realen DDEV-/Container-Test gefunden.

### Behoben
- `impexpnl:import` lief unter Symfony 6 nicht: eigene `--verbose`-Option kollidierte mit dem globalen `-v` → entfernt, Feld-Diff nun über die eingebaute Verbosity (`-v`).
- `impexpnl:import`: Ziel-PID `0` (Wurzel) wurde fälschlich als „fehlt" gewertet.
- `impexpnl:import`: relative Dateipfade werden wie beim Export aufgelöst (`getFileAbsFileName`).
- `BootstrapService`: CLI-Backend-User (`_cli_`) wird korrekt authentifiziert und der Backend-Request mit `applicationType` (Int `REQUESTTYPE_BE`) versehen — behebt „Attempt to modify table … without permission" bzw. „No valid attribute applicationType found in request object" beim CLI-Import.
- `ImportLogRepository::findLatest()`: eindeutiger Tie-Break (`uid`) bei Importen in derselben Sekunde.

### Geändert
- Composer-Paketname auf `themightynighty/impexpnl` (einheitlich über alle Branches für Packagist).
- `phpunit.functional.xml`: `memory_limit=512M` (v13.4 `TcaSchemaFactory`).

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
