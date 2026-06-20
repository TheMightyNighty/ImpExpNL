# Roadmap

Lebendes Dokument für geplante Arbeiten an ImpExpNL. Reihenfolge = grobe Priorität.

## Jetzt: Härtung des Bestands

Schwerpunkt nach der v14-Migration ist die Robustheit des vorhandenen Funktionsumfangs
(CLI-first), nicht neue Features.

- [ ] **CLI-Fixes auf die `13.x`-Linie backporten** *(kritisch)*
  Die beim DDEV-Test in 2.0.0 gefundenen Bugs betreffen sehr wahrscheinlich auch
  TYPO3 v13 (1.x), d. h. der CLI-Import ist dort vermutlich ebenfalls gebrochen:
  - `--verbose`-Options-Kollision mit dem Symfony-Builtin
  - relative Dateipfade Import vs. Export
  - `_cli_`-Backend-User wird nicht authentifiziert (DataHandler-Rechte)
  Auf `13.x` verifizieren, fixen, als `1.0.2` releasen.
- [ ] **Test-Abdeckung der realen CLI-Pfade ausbauen**
  Alle Commands über eine echte `Application` ausführen (Dry-Run), den
  `_cli_`-Auth-Pfad und Rollback per `importId` abdecken — damit solche nur
  zur Laufzeit sichtbaren Fehler künftig die CI bricht.
- [ ] **Eingabe-/Fehler-Robustheit**
  Pfad- und PID-Validierung, klare Fehlermeldungen und Exit-Codes, definierte
  Behandlung fehlender/half-konfigurierter Storages und Tabellen.
- [ ] **Sicherheit**
  Pfad-Traversal-Schutz für Export-/Import-Ziele schärfen, Handhabung des
  `IMPEXPNL_SIGNING_KEY`, Reduktion von `sys_log`-Rauschen beim Massenimport.
- [ ] **CI-Härtung**
  Functional-Tests zusätzlich gegen MariaDB (nicht nur SQLite) in der Matrix;
  Prüfen, ob das PHPStan-Level angehoben werden kann.

## Später

- [ ] **Backend-Modul (GUI)** — *zurückgestellt*
  Export/Import sind primär Admin-/Automatisierungs-Werkzeuge (CLI-first bleibt
  der Kern). Für Redaktionen/Setups ohne Konsolenzugriff ist ein schlankes,
  berechtigungsgeschütztes Backend-Modul denkbar:
  - MVP: Export (Tree-Picker → JSON-Download), Import (Upload + dry-run/delta/
    conflict), Undo-Übersicht aus `tx_impexpnl_import_log`. Aufwand grob 3–5 Tage.
  - Große Importe über den Scheduler/Queue statt im Web-Request
    (`max_execution_time`).
  Da die gesamte Logik in entkoppelten Services liegt, ist die GUI eine dünne
  Schicht (Controller + Fluid) ohne Umbau der Kernlogik.
- [ ] **Dependabot** (`composer` + `github-actions`) zur automatischen Pflege der Dev-Deps/Actions.
- [ ] **Multi-Source-Delta für Registry-Tabellen** — Idempotenz über `tx_impexpnl_uid_map`
  auch für registrierte Tabellen nutzen (heute nur `pages`/`tt_content`).
- [ ] **TER-Veröffentlichung** der 2.x-Linie.
