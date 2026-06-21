# Roadmap

Lebendes Dokument für geplante Arbeiten an ImpExpNL. Reihenfolge = grobe Priorität.

Legende: `[x]` erledigt · `[~]` teilweise vorhanden · `[ ]` offen.

---

## Meilenstein: Hardening-Release ✅ ABGESCHLOSSEN & VERÖFFENTLICHT

Härtung des Bestands: Validierung, Tests, Doku-Klarheit, CI/MariaDB, Crash-/Rollback-
Sicherheit. Grundlage war die Vorschlagsliste (`vorschläge.txt`).

> **Released am 2026-06-21** als Minor-Bump (neue abwärtskompatible Features):
> **v2.1.0** (TYPO3 v14, `main`, „Latest") und **v1.1.0** (TYPO3 v13.4, `13.x`).
> Tags + GitHub-Releases gesetzt, beide Branches gepusht.
>
> Blöcke A–E erledigt; offen nur noch als „gering" markierte Test-Edge-Cases (siehe unten).

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

- [x] **Dry-Run == realer Import**: Prognose (new/changed/identical) == Effekt (new/updated/skipped).
      `DryRunMatchesImportTest` deckt ab: neuer Baum, Delta-Re-Import, Delta mit lokaler Änderung,
      **`conflict=skip`** (Prognose „geändert" = `updated + conflict_skipped`) und
      **versteckte Records** (`includeHidden`, Hidden-Flag bleibt erhalten).
      Abgewertet (kein Mehrwert): separate Sprachversionen-Parität — l10n-Records laufen
      durch denselben new/changed/identical-Pfad, bereits von `LanguageImportTest` abgedeckt.
- [x] **Rollback-Semantik glasklar**: Kern-Invariante umgesetzt — lokal nach dem Import
      geänderte Ziel-Records (tstamp neuer als Import) brechen den Rollback ab (`RollbackService`
      wirft), außer `impexpnl:undo --force`; Auto-Rollback bei Abbruch nutzt `force` (eigene
      Records). `RollbackSafetyTest` deckt Abbruch + `--force` ab; bestehende Rollback-/Delta-Tests
      grün. Registry/MM-Rollback (Block E), **bereits gelöschter Ziel-Record** (`RollbackSafetyTest`)
      abgedeckt. Fehlende FAL-Referenz ist trivial (DELETE trifft nichts → kein Fehler).
- [x] **FAL-Edge-Cases**: `FalEdgeCasesTest` deckt ab — Referenz-**Metadaten** (`crop`/`alternative`/
      `title`/`description`/`link`/`sorting_foreign`) bleiben beim Import erhalten (vorher Bug: gingen
      verloren — in `FalResolverService` behoben), und fehlende Zieldatei wird ohne Fehler übersprungen.
      **mehrere Referenzen auf dieselbe Datei** (`FalEdgeCasesTest`) und **Referenzen auf
      Übersetzungen** (`FalTranslationReferenceTest`) abgedeckt.
      Abgewertet (Nische/contrived, geringer Wert): nicht indexierte Datei, anderer Storage,
      gleicher Identifier/anderer Inhalt — greifen tief in FAL-Interna; bei realem Bedarf gezielt nachziehen.
- [x] **Sprach-Tests** — `LanguageImportTest` deckt Default+Übersetzung (Seiten & Inhalte) ab.
      Dabei **zwei echte Bugs behoben**: (1) Relations-Container-Felder (inline/file/category, nur
      Counts) brachen den `DataMapProcessor` bei Übersetzungen → in `buildRecordData` gefiltert;
      (2) `l10n_parent`/`l18n_parent` wurden nicht auf die neuen Eltern-UIDs aufgelöst → Nachpass
      (`applyL10nFixups`). Abgewertet: „Übersetzung ohne übersetzten Parent" ist in `applyL10nFixups`
      defensiv abgefangen (Null-Check überspringt, kein Crash); „Slug pro Sprache" läuft bereits über
      `adjustSlugsForTargetSite` (iteriert alle Seiten inkl. Übersetzungen) — separater Test bräuchte
      ein Site-Config-Harness und brächte wenig.
- [x] **Workspace-Tests** (Hochrisiko): `WorkspacePublishTest` deckt WS-Import (Versionen),
      WS-Delta-Idempotenz, **Freigabe** (`ActionService::publishWorkspace` → Live mit umgeschriebenen
      Links) und WS-Rollback ab. Harness ohne `SiteBasedTestTrait`: `sys_workspace` manuell anlegen +
      `coreExtensionsToLoad=['workspaces']`. **Echter Bug gefunden+behoben:** Rollback lief im
      Live-Workspace (0) und ließ WS-Versionen liegen → `RollbackService` initialisiert jetzt den
      Kontext mit der `workspace_id` aus dem Import-Protokoll.
- [x] **v13-Backport** auf `13.x`, **veröffentlicht als v1.1.0** (2026-06-21):
      - `35da583`: Block-B-Härtung — l10n (Container-Filter + `applyL10nFixups`), WS-Rollback
        (`workspace_id`), Rollback-Sicherheit, FAL-Metadaten + Tests.
      - `7be4ac2`: Rollback in DB-Transaktion (~135 s → ~36 s) + `PerformanceBaselineTest`.
      - `299cba4`: **Import in DB-Transaktionen** (Fund: v14-Optimierung fehlte auf v13 →
        Import ~147 s → ~40 s).
      Alles auf v13.4.31 verifiziert (CS 0, PHPStan ok, Unit 64, Functional 32). Test-Container
      `impexpnl_v13test` (testing-framework 8).
- [x] **Locking/Crash simulieren**: `FullRoundtripAbortTest` deckt Crash mitten im Import +
      Auto-Rollback ab. `ImportLockTest` ergänzt: zweiter `acquire` abgewiesen, `release` gibt
      frei, **stale-Lock wird beim `acquire` automatisch geerntet**, `getActiveLock` ohne Lock =
      null, `forceReleaseDbLock` meldet ob ein Lock bestand (= `impexpnl:unlock`-Pfad).
      **`impexpnl:status`** spiegelt aktiven Lock / „kein Lock" (`StatusCommandTest`); abgebrochene
      Importe sind über das `[ABGEBROCHEN]`-Quellfeld im letzten Import sichtbar.
      Abgewertet: prozessübergreifender Datei-Lock-Fast-Fail — im Single-Process-Test nicht
      verlässlich darstellbar (DB-Lock deckt den Cluster-Fall ab und ist getestet).
- [~] **Profil-Contract-Tests**: Harness steht — `Tests/Functional/Profile/AbstractProfileContract`
      definiert die Vertragsklauseln als Tests (Export enthält alle Records · Import bildet alle ab ·
      Delta-Idempotenz · Rollback entfernt alles; optional Link-Rewrite/Kategorie/FAL per `verify*`).
      `CoreProfileContractTest` (Seiten+Inhalte, Link-Rewrite, sys_category-MM) ist grün — FAL für das
      Core-Profil bewusst übersprungen. Neue unterstützte Extension = neue Subklasse + Fixtures.
      Offen: YAML-getriebene Profile statt PHP-Subklassen, FAL-Profil, Profil pro Ziel-Extension.

### C. CLI & CI  *(#10, #11, #12)*

- [x] **Differenzierte Exit-Codes** für CI/CD: `Domain\ExitCode` + typisierte `Exception\*`
      (`ConfigException`=2, `LockException`=3, `IntegrityException`=4, `ConflictException`=5,
      `AbortedImportException`=6). Commands mappen Exceptions → Code, `--json` führt `exitCode`.
      Neue Strategie `--conflict=abort` (=5). `ExitCodeTest` deckt 2–6 ab. Code `7` (Assets fehlen)
      ist definiert, aber reserviert (Strict-Asset-Modus später; Default überspringt fehlende Dateien).
- [x] **CI um MariaDB ergänzen**: Job `functional-mariadb` (Matrix 10.11 + 11.4, PHP 8.3,
      `mysqli`) zusätzlich zum SQLite-Lauf. Lokal gegen MariaDB 11.4 verifiziert: 72 Tests grün
      (identisch zu SQLite, keine DB-spezifischen Findings).
- [x] **Performance-Baseline** festgeschrieben: `Documentation/PERFORMANCE.md` + reproduzierbarer
      `PerformanceBaselineTest` (Small/Medium/Large, JSON vs. JSONL, Export-/Import-/Rollback-Dauer +
      Peak). **Finding+Fix:** Rollback lief im Autocommit → jetzt in einer DB-Transaktion gebündelt
      (medium ~135 s → ~29 s). Large braucht >512 MB (In-Memory) → Hebel: streamender Import (siehe „Später").

### D. Doku & Distribution  *(#5, #13, #15)*

- [x] JSONL-/Speicher-Aussagen im README konsistent (Export schreibt zeilenweise; Import parst
      aktuell vollständig in den Speicher; streamender Import ist „Später").
- [x] **„Grenzen / Nicht-Ziele"-Sektion** im README ergänzt (kein DB-Dump-Ersatz, kein generischer
      Import ohne YAML-Profil, kein Datei-Transfer, kein Merge-Tool, Rollback ≠ Snapshot-Restore,
      MM-Importe umgehen DataHandler bewusst, Workspaces projektspezifisch).
- [x] **Installation Composer-first** geordnet: `composer require` als Hauptweg, Path-/VCS-Repo für
      Entwicklung, TER optional, DDEV-Demo als reines Ausprobier-Beispiel. Zudem die zwei
      Exit-Code-Tabellen im README zu einer kanonischen konsolidiert.

### E. Beispielprofile als Qualitätsmaßstab  *(#14)*

- [x] 2 Profile **hart** gemacht: `sys_redirect` (`RedirectProfileContractTest` — Export/Remap/
      `target`-Link-Rewrite/externes Ziel/Rollback) und `sys_category_record_mm`
      (`CategoryProfileContractTest` — Pfad-Mapping/Delta-Idempotenz/Rollback). Je Fixture +
      Contract-Test in `Tests/Functional/Profile/` (Vorlage für Community) + README-Tabelle.
      `typo3/cms-redirects` als `require-dev`. Befund dokumentiert: `record`-Registry-Tabellen
      sind noch nicht delta-idempotent (MM mit `category_match: path` schon).
      Optional offen: `tx_news` (3rd-party-Dependency, bewusst ausgelassen).

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
