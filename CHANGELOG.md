# Changelog

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
