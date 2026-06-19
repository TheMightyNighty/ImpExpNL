# Changelog

## 2.0.0

Kompatibilität mit **TYPO3 v14 LTS**. Die v13.4-Linie wird im Branch `13.x` (Releases `1.x`) weitergepflegt.

### Geändert
- `composer.json`: `typo3/cms-core` auf `^14.0`, `typo3/testing-framework` auf `^9.3`, `typo3/cms-workspaces` auf `^14.0`.
- `ext_emconf.php`: Constraint auf `14.0.0-14.99.99`.
- `rector.php`: TYPO3-Set auf `UP_TO_TYPO3_14`.
- CI-Matrix um PHP 8.4 erweitert.

### Hinweise
- Keine Code-Anpassungen an den Klassen nötig: Commands nutzen bereits `#[AsCommand]` + Constructor-DI, DI-Container via `autoconfigure`, keine Frontend-Abhängigkeit (`TypoScriptFrontendController`-Wegfall irrelevant), DataHandler-Nutzung ohne die in v14 entfernten Properties.

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
