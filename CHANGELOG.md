# Changelog

## 5.0.0 — TYPO3 v13

Dedizierte, gehärtete Version für **TYPO3 v13.4 LTS** (Doctrine DBAL 4).

### Breaking Changes
- Nur noch **TYPO3 v13.4** (`typo3/cms-core: ^13.4`); v12/v14-Constraints entfernt.
- **Prüfsummen-Format** geändert: Die Integritäts-Prüfsumme deckt nun den gesamten
  Datenblock ab und trägt ein Schema-Präfix (`sha256:`/`hmac-sha256:`). Alte Exporte
  (Legacy-SHA256 nur über pages + tt_content) werden beim Import weiterhin akzeptiert.
- Neue Datenbanktabelle `tx_robbicopy_lock` → `database:updateschema` erforderlich.

### Sicherheit
- Prüfsumme über **alle** Tabellen statt nur pages/tt_content.
- Optionaler **HMAC-Manipulationsschutz** via `ROBBICOPY_SIGNING_KEY` bzw.
  `$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['robbi_copy']['signingKey']`.
- Import bricht nie mit nicht-rückrollbaren Geisterdaten ab (Notfall-Protokoll).
- Cluster-weiter DB-Lock (Heartbeat, Shutdown-Release, konfigurierbarer Timeout
  `import.lock_stale_seconds`) zusätzlich zum Datei-Lock.
- Import-Quelldatei auf Projektverzeichnis begrenzt; CSV-Export gegen Formula-Injection
  entschärft; `undo` mit Vorschau, Bestätigung und Warnung bei lokal geänderten Records.
- Bootstrap nutzt den nativen TYPO3 `_cli_`-Backend-User statt eines Admin-Fallbacks.

### v13-Kompatibilität / Zukunft
- DBAL 4: `introspectTable()` statt entferntem `listTableColumns()`.
- **FAL-Regression behoben**: `getFileObjectFromStorageByFileId()` existiert in v13 nicht
  mehr → Auflösung jetzt über `ResourceStorage::getFile()`.
- TCA-Zugriff über die **TCA Schema API** (`TcaSchemaFactory`) statt `$GLOBALS['TCA']`.
- Rector mit TYPO3-Ruleset als Dev-Werkzeug.

### Performance (große Bäume)
- Seitenbaum-Export ebenen-weise statt N+1; Slug-Regenerierung und Site-Config-Auflösung
  ohne N+1; MM-/Registry-Import als Bulk-Insert; inkrementelle Prüfsummenberechnung.

### Architektur / Wartbarkeit
- `ImportService` entzerrt: `ImportLockService`, `ImportLogRepository`, `ConflictResolver`,
  `ConfigurationService`.
- Typisiertes Domänenmodell: `UidMap`, `ExportManifest`, `ConflictStrategy` (Enum),
  `SystemFields`, `PageLinkRewriter` (zentralisiert, dedupliziert).
- Maschinenlesbare Ausgabe (`--json`) für `import` und `status`.

### Tooling / Tests
- CI (GitHub Actions): Lint, CGL (php-cs-fixer/TYPO3 CGL), PHPStan (Level 6), Unit-
  und Functional-Tests (PHP 8.2/8.3, Functional via SQLite).
- Erweiterte Unit-Testabdeckung über die öffentliche API der neuen Komponenten.

### Weitere Verbesserungen
- **Delta-Rollback korrigiert**: `undo` löscht nur tatsächlich angelegte Records,
  nie vorbestehende, nur gematchte Records.
- **Abbruch-Schutz**: Ein abgebrochener Import wird standardmäßig automatisch
  zurückgerollt (`import.auto_rollback_on_failure`).
- **Slug-Eindeutigkeit** auf dem Zielsystem (keine Kollisionen beim Einspielen in
  bestehende Bäume).
- **FAL-Storage** pro Referenz bzw. konfigurierbar (`import.fal.storage_id`),
  Multi-Storage-fähig.
- Konfigurierbar: `import.batch_size`, `import.lock_stale_seconds`.
- **JSONL-Format** (`export --jsonl`, Import erkennt `.jsonl`): zeilenweises,
  speicherschonendes Format für große Bäume; Default-JSON bleibt kompatibel.
- `robbicopy:list` mit `--json`; Registry-Konfiguration wird von `robbicopy:check`
  validiert.
- Zusätzliche Functional-Tests: Delta-Undo, Registry/Kategorie-Pfad, conflict=ask,
  JSONL-Round-Trip.
