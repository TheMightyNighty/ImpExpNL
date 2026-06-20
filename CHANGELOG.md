# Changelog

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
