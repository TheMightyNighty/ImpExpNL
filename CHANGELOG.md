# Changelog

## Unreleased (Härtung)

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
- **Rollback in einer DB-Transaktion** gebündelt (wie der Import): die vielen einzelnen
  DataHandler-Deletes liefen zuvor im Autocommit. Messung (SQLite, 1.000 Seiten / 5.000
  Inhalte): Rollback **~135 s → ~36 s**; kleine Klasse ~2,4 s. Der Rollback liegt damit
  in der Größenordnung des Imports.

### Tests
- Neu: `LanguageImportTest`, `RollbackSafetyTest`, `FalEdgeCasesTest`,
  `WorkspacePublishTest` (WS-Import/Delta/Publish/Rollback), `PerformanceBaselineTest`
  (small/medium/large, Export-/Import-/Rollback-Dauer + Peak als Regressionsschutz).
- `.php-cs-fixer`: Demo-Projekt (`Build/demo`) vom Coding-Standards-Check ausgenommen.

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
