# Changelog

## Unreleased (Härtung)

Stabilitäts- und Korrektheits-Härtung des Bestands, breit mit Functional-Tests
abgesichert. Keine Schema-Änderungen, keine API-Brüche.

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
