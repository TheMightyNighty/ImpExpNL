# Roadmap

Lebendes Dokument für geplante Arbeiten an ImpExpNL. Reihenfolge = grobe Priorität.

Legende: `[x]` erledigt · `[~]` teilweise vorhanden · `[ ]` offen.

---

## Meilenstein: Hardening-Release

Nächster großer Schritt ist **kein** Feature-Release, sondern Härtung des Bestands:
Validierung, Tests, Doku-Klarheit, CI/MariaDB, Crash-/Rollback-Sicherheit. Grundlage
ist die Vorschlagsliste (`vorschläge.txt`).

> Offene Entscheidung: Versionsnummer des Hardening-Release — 2.0.0 noch einmal
> sauber neu schneiden (solange unveröffentlicht) **oder** als 2.0.1 herausgeben.

### A. Konfiguration & Registry härten  *(Vorschlag #1, #9)*

Architekturmodell: Jede Extension liefert ihre `Configuration/ImpExpNL.yaml` selbst mit
(`ConfigurationService::getRegisteredTables()`); diese Registry muss sehr gut validiert sein.

- [x] `impexpnl:validate-config` + `ConfigValidationService` — prüft: Tabelle existiert,
  `pid_field`, `rewrite_links`-Felder, MM-Felder (`match_field`/`match_tablenames_field`),
  `match_tables` referenzieren bekannte TCA-Tabellen, `type` gültig.
- [x] Fehlermeldungen um **Extension-Key** (Herkunft) und „Did you mean?"-Vorschlag (Levenshtein) erweitert.
- [x] Prüfung: `uid_remap` fehlt bei `record`-Tabellen → Warnung (Rollback erfasst sie sonst nicht).
- [x] `validate-config` als **Preflight-Gate** in `impexpnl:check` integriert (Registry-Validierung)
      + Laufzeit-Checks: FAL-Default-Storage, Import-Lock-Status, Signing-Key-Modus, Profil-Parsing
      inkl. Ziel-Workspace-Existenz. (`check`/`validate-config` haben `--json`.)
- [x] JSON-Schema für `ImpExpNL.yaml` (`Configuration/Schema/ImpExpNL.schema.json` + Modeline für
      IDE-Autocomplete; CI-Validierung via `opis/json-schema` im Unit-Test).

### B. Tests: Vertrauen härten  *(#2, #3, #4, #6, #7, #8)*

- [ ] **Dry-Run == realer Import**: Prognose (new/updated/skipped/conflicts) deckt sich exakt
      mit dem echten Effekt — für neuer Baum, `--delta`, lokale Änderung, `conflict=skip`,
      versteckte Records, Sprachversionen, Registry-Tabelle, MM-Tabelle, FAL-Referenz.
- [ ] **Rollback-Semantik glasklar**: gefährliche Fälle (Teil-Import, zweiter Delta, lokal
      geänderte Ziel-Records, Registry/MM, Workspace, gelöschte Zielseite, fehlende FAL-Datei).
      Invariante: Rollback löscht **nie** Records, die nicht durch genau diesen Import entstanden;
      lokal veränderte → Warnung statt blindem Löschen (ohne `--force`). Pro Record prüfen,
      nicht nur Anzahl.
- [ ] **FAL-Edge-Cases**: Datei fehlt im Ziel, Datei vorhanden aber nicht indexiert, anderer
      Storage, gleicher Identifier/anderer Inhalt, fehlende Metadaten, mehrere Referenzen auf
      dieselbe Datei, Felder `crop`/`alternative`/`title`/`description`, Referenzen in
      übersetzten Inhalten und Container-Kindern.
- [ ] **Sprach- & Workspace-Tests** (Hochrisiko): Default+Übersetzung, Übersetzung ohne
      übersetzten Parent, `l10n_parent`, WS-Import/Delta/Rollback, Freigabe nach WS-Import,
      Slug-Regeneration pro Sprache.
- [~] **Locking/Crash simulieren**: `FullRoundtripAbortTest` deckt Crash mitten im Import +
      Auto-Rollback bereits ab. Ergänzen: Lock gesetzt / zweiter Import abgewiesen / Dry-Run
      ohne Lock / stale-Lock erkannt / `unlock` (mit und ohne `--force`) / Status zeigt Abbruch.
- [ ] **Profil-Contract-Tests** (`Tests/Profile/<ext>/` mit YAML + Fixtures + Test): Ein Profil
      gilt erst als „unterstützt", wenn Export/Import/Delta-Idempotenz/Rollback/Link-Rewrite/
      Kategorie-Mapping/FAL bestehen.

### C. CLI & CI  *(#10, #11, #12)*

- [ ] **Differenzierte Exit-Codes** für CI/CD: `0` ok, `1` generisch, `2` ungültige Config,
      `3` Lock aktiv, `4` Prüfsumme/Signatur, `5` Konflikte, `6` Teil-Import/Rollback nötig,
      `7` Dateien/Assets fehlen.
- [ ] **CI um MariaDB ergänzen** (10.11/11.x) zusätzlich zu SQLite — realistischer für
      DBAL/Locks/Transaktionen/Unique-Keys/MM/große Inserts.
- [ ] **Performance-Baseline** festschreiben (Small 100/500, Medium 1.000/5.000, Large
      10.000/20.000) und je Release dokumentieren: Export-/Import-/Rollback-Dauer, Peak
      JSON vs. JSONL — als Regressionsschutz, nicht als Marketing.

### D. Doku & Distribution  *(#5, #13, #15)*

- [x] JSONL-/Speicher-Aussagen im README konsistent (Export schreibt zeilenweise; Import parst
      aktuell vollständig in den Speicher; streamender Import ist „Später").
- [ ] **„Grenzen / Nicht-Ziele"-Sektion** im README (kein DB-Dump-Ersatz, kein generischer
      Import ohne YAML-Profil, kein Datei-Transfer ohne rsync, kein Merge-Tool, Rollback ≠
      Snapshot-Restore, direkte MM-Imports umgehen DataHandler bewusst, Workspaces projektspezifisch).
- [ ] **Installation Composer-first** ordnen: Packagist als Hauptweg, Path-/VCS-Repo nur für
      Entwicklung, TER optional, DDEV-Demo separat.

### E. Beispielprofile als Qualitätsmaßstab  *(#14)*

- [ ] 2–3 Profile **hart** machen statt 10 halb: `sys_redirect`, `sys_category_record_mm`,
      optional `tx_news_domain_model_news`. Je YAML + Fixture + Export-/Import-/Delta-/
      Rollback-Test + README-Beispiel. Dient als Muster für Community-Beiträge.

---

## Meilenstein: Transfer- & Transport-Profile *(nach dem Hardening-Release)*

Deklarative, **mitgelieferte und zugleich user-/extension-erweiterbare** Profile, die
*Was/Policy* (`transferProfiles`) von *Wie* (`assetTransports`) trennen. Sie leben in der
YAML-Config (`imp_exp_nl.yaml` / `Configuration/ImpExpNL.yaml`), **nicht** im `var/`-Runtime-
Verzeichnis, und **vereinheitlichen** das bestehende `--profile`/`ProfileService`.
Leitlinie: ImpExpNL baut **rsync/S3 nicht nach** — der Manifest ist der Vertrag.

### Fundament
- [ ] **Asset-Manifest härten**: pro Datei `identifier + sha256 + size + storage` (statt nur
      Identifier). Schaltet inkrementellen Transfer, **Integritätsprüfung** (gleicher
      Identifier / anderer Inhalt → Warnung) und **Asset-Preflight** (in `check`/`validate-config`)
      frei. Nützt allen Transportwegen gleichermaßen.

### `assetTransports` — das „Wie" (dünn, manifest-zentrisch)
- [ ] `type: external` (Default): nur Manifest + Asset-Liste erzeugen; rsync/rclone/S3 bleiben
      Operator-Sache (kein SSH/Transport im Core).
- [ ] `type: bundle`: self-contained Archiv (`tar.gz` garantiert, `tar.zst` falls zstd verfügbar);
      `includeAssets`/`includeManifest`/`includeChecksums`.
- [ ] `type: http-pull`: ziel-seitiger Download über HTTPS; `allowedHosts`, `verifyHashes`,
      `maxFileSize`, nur Manifest-Identifier (kein Path-Traversal), TLS-only, optional Auth-Token.
- [ ] *(Später/Community)* weitere Adapter (z. B. `s3`) über ein **dünnes** `TransportInterface`.

### `transferProfiles` — das „Was/Policy" (als Beispiele mitgeliefert, erweiterbar)
- [ ] Referenzieren einen `assetTransport` by name + **Guardrails**: `requireDryRun`,
      `requireCleanDryRun`, `requirePackageChecksum`, `conflictStrategy` (z. B. `abort`),
      `allowDeleteMissing` (Default `false`). Durchsetzungspunkt im Import-Flow (Gates).
- [ ] **Kontext-/Host-Bindung** (`allowedContext`): verhindert versehentliche Ausführung in der
      falschen Umgebung (z. B. `dev_to_prod` nicht auf dev) — Richtung wird technisch erzwungen.
- [ ] Auswahl per `--profile`/`--transport`; `validate-config` prüft Profile + referenzierte Transports.
- [ ] Beispielprofile mitliefern: `prod_to_dev`, `dev_to_prod_partial`, `stage_to_prod_release`.

### Zwei eigenständige Teilfeatures (NICHT als beiläufige Flags)
- [ ] **`rewriteUrls`** — absolute Domain-URLs im Content ersetzen; feldbezogen, literal/regex
      explizit, bewusst **getrennt** vom UID-basierten `t3://page`-Link-Rewriter (Footgun-Risiko).
- [ ] **Sanitizing (prod→dev)** — PII/DSGVO-Scrubbing (be_users/fe_users/Adressen anonymisieren);
      eigene, klar spezifizierte Regeln (Tabellen/Felder/Anonymisierung), kein Einzeiler-Flag.

---

## Später

- [ ] **Streamender Import** — Datei records-weise verarbeiten (konstanter Speicher
      unabhängig von der Dateigröße), statt JSON/JSONL vollständig zu parsen. Der
      DataHandler-Teil ist bereits gechunkt; offen ist der streamende Parser/Importfluss.
- [ ] **Dependabot** (`composer` + `github-actions`).
- [ ] **Multi-Source-Delta für Registry-Tabellen** — Idempotenz über `tx_impexpnl_uid_map`
      auch für registrierte Tabellen (heute nur `pages`/`tt_content`).
- [ ] **Veröffentlichung** über Packagist (`composer require themightynighty/impexpnl`) und TER.

---

## Ganz langfristig / optional (niedrigste Priorität)

- [ ] **Backend-Modul (GUI)** — bewusst ganz nach hinten gestellt. Export/Import bleiben
      CLI-first; das ist das Alleinstellungsmerkmal. Erst wenn der Bestand gehärtet und die
      Distribution rund ist, ggf. ein schlankes, berechtigungsgeschütztes Backend-Modul für
      Setups ohne Konsolenzugriff: MVP Export (Tree-Picker → Download), Import (Upload +
      dry-run/delta/conflict), Undo-Übersicht; große Importe via Scheduler statt im
      Web-Request. Dank entkoppelter Services wäre die GUI eine dünne Schicht
      (Controller + Fluid).
