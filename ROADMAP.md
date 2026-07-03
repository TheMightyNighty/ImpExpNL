# Roadmap

Lebendes Dokument für geplante Arbeiten an ImpExpNL. Reihenfolge = grobe Priorität.

Legende: `[x]` erledigt · `[~]` teilweise vorhanden · `[ ]` offen.

---

## Erledigt

- **Security-/Stabilitäts-Release v2.2.0** (2026-07-03) — vollständige Umsetzung des
  Sicherheits-Maßnahmenplans aus [`Prüfbericht.md`](Prüfbericht.md) (F-01–F-14: Signaturpflicht
  `import.require_signature`, Downgrade-Sperre, Bundle-Härtung mit signiertem Asset-Manifest,
  Lock-Owner-Token, HMAC-Sanitize, PID-Containment + `allowedTables`, Dateigrößen-Limit,
  Key-Rotation mit Key-ID, README-Kapitel Sicherheitsgefälle) plus acht Real-World-Funde aus dem
  E2E-Test (E-01–E-08, u. a. Delta-Idempotenz unter Realbedingungen, `RootlineCacheGuard` gegen
  TRUNCATE-implizite Commits, atomare Batch-Checkpoints). Neu als Release-Ritual:
  `Build/test-functional-mariadb.sh` und `Build/e2e/run.sh`. Details im [CHANGELOG](CHANGELOG.md).

- **Hardening-Release** — Blöcke A–E (Konfig-/Registry-Validierung, Test-Härtung inkl.
  Workspace/Sprache/FAL/Rollback, differenzierte Exit-Codes, MariaDB-CI, Performance-Baseline,
  Doku-Klarheit, contract-getestete Beispielprofile). **Veröffentlicht 2026-06-21 als
  v2.1.0 (TYPO3 v14) / v1.1.0 (TYPO3 v13.4).** Details im [CHANGELOG](CHANGELOG.md).

Kleinere bewusst offen gelassene Punkte aus der Härtung:
- [x] **Profil-Contract-Tests**: Harness (`AbstractProfileContract` mit `prepareProfileState()`-Hook)
      + Subklassen `Core`/`Redirect`/`Category`/`Fal`; **YAML-getriebener** `DeclarativeProfileContractTest`
      (profiles.yaml: Profile rein deklarativ, vier Pflichtklauseln generisch per DataProvider) inkl.
      **Ziel-Extension-Profil `redirect`** (registrierte Tabelle `sys_redirect`). `deltaIdempotent`
      pro Profil abschaltbar.
  - Echte Dritt-Extension **EXT:georgringer/news** umgesetzt: `tx_news_domain_model_news` in der
    Registry, `NewsProfileContractTest` (skip-guarded, require-dev), Registry überspringt fehlende
    Tabellen still. **Real-World-verifiziert** (2 Instanzen, news 14.0.3): News src→tgt gemappt.

---

## Meilenstein: Transfer- & Transport-Profile — weitgehend umgesetzt

> Mit v2.2.0 nach `main` gemerged; offen bleiben die unten gelisteten Punkte.

Trennung von *Was/Policy* (`transferProfiles`) und *Wie* (`assetTransports`), deklarativ in der
YAML-Config. Leitlinie: ImpExpNL baut **rsync/S3 nicht nach** — der Manifest ist der Vertrag.

**Erledigt auf dem Branch** (mit Funktionaltests + realem 2-Instanzen-Lasttest, 2026-06-30,
2000 Seiten / 2000 Assets / 391 MB, prod→dev über zwei TYPO3-v14-Container):
- [x] **Asset-Manifest** (`storage`/`identifier`/`size`/`sha256`, dedupliziert, prüfsummen-abgedeckt;
      `impexpnl_assets.json` + rsync-`impexpnl_assets.txt`).
- [x] **Integritätsprüfung** beim Import (gleicher Identifier / anderer Inhalt → Warnung).
- [x] **Asset-Preflight** im Dry-Run + **Exit-Code `ASSETS_MISSING` (7)** mit Operator-Guidance.
- [x] **`assetTransports`**: `external` (Default) und `bundle` (self-contained `.tar.gz`,
      echter Cross-Instance-Round-Trip inkl. import-seitiger Extraktion).
- [x] **Packaging produktionstauglich**: Bundle baut/entpackt über **natives `tar`**
      (streamend, O(n); ersetzt das O(n²)-`PharData::addFile` + single-threaded `Phar::compress`).
      Kompressor konfigurierbar (`gzip`-Default, `pigz`-fähig), PharData nur noch Fallback.
- [x] **`transferProfiles`** + Guardrails (`requireDryRun`, `conflictStrategy`) + **Kontext-Bindung**
      (`allowedContext`); `validate-config` prüft Profile + Transporte; Beispielprofile.
- [x] **`rewriteUrls`** (feldbezogen, literal/regex, getrennt vom `t3://`-Rewriter).
- [x] **Sanitizing (prod→dev)** PII/DSGVO-Scrubbing.
- [x] **FAL-Zähler-Nachpass** korrekt inkl. Upsert-Delete-Pfad und Workspace-Neu-Platzhalter.
- [x] **Transient-Entpack-Verzeichnis** wird nach jedem Bundle-Import zuverlässig aufgeräumt
      (`finally`; vorher blieb pro Import die volle entpackte Kopie liegen).

**Noch offen in diesem Meilenstein:**
- [ ] **`type: push` (ausgehend, von der Quelle) + Inbox-Konzept** — ersetzt das verworfene
      `http-pull`. Begründung (Schutzgefälle): Initiative/Erreichbarkeit müssen vom stärker
      geschützten zum schwächer geschützten System fließen. `http-pull` würde entweder die Quelle
      (prod) einen Download-Endpunkt exponieren lassen oder das Ziel aktiv in prod greifen — beides
      öffnet das geschützte System. **Stattdessen:**
  - Quelle stößt an: dünner, **ausschließlich ausgehender** Client legt das **signierte Bundle**
    in eine Ziel-**Inbox** (über Operator-Transport SFTP/rsync oder einen minimalen HTTPS-PUT-
    Receiver). **Kein lauschender Dienst im Core.**
  - **Rote Linie: Empfang ≠ Anwendung.** Empfangen heißt nur „Datei atomar in die Inbox legen"
    (temporär schreiben → bei Vollständigkeit umbenennen). Der Import bleibt ein **separater,
    explizit ausgelöster** Schritt auf dem Ziel — **niemals Auto-Import bei Eintreffen** (sonst
    könnte ein entfernter Akteur DB-Schreibzugriffe triggern).
  - Guardrails: TLS-only, Token-Auth, Ziel-Allowlist, signiertes+gehashtes Payload, Größen-Caps,
    nur Manifest-Identifier (kein Path-Traversal), Kontext-/Richtungs-Bindung (`allowedContext`).
    Integritätsprüfung vor dem Anwenden (Manifest/sha256, bereits vorhanden).
  - `pull` nur noch gegen einen **vertrauenswürdigen, signierten Artefakt-Store** (CI/Bucket),
    nie gegen Live-Prod.
- [ ] **Inkrementeller Transfer im `bundle`**: unveränderte Ziel-Dateien anhand sha256 überspringen
      (external/rsync ist von Natur aus inkrementell; das Bundle packt heute immer alles).

---

## Aus dem Lasttest 2026-06-30 abgeleitet

- [x] **Rollback/Abbruch-GC für platzierte Assets** — der Import protokolliert die selbst neu
      platzierten `sys_file`-UIDs (`_placed_files` im ImportLog), der Rollback entfernt sie
      referenz-geprüft (nur bei 0 Referenzen); `external`-Dateien bleiben tabu. Test `BundleRollbackGcTest`.
- [x] **SIGKILL-Robustheit** — ImportLog wird vor dem ersten und nach jedem Batch fortgeschrieben
      (`ImportLogRepository::upsert`); ein harter Crash hinterlässt einen per `undo` rückrollbaren
      Teilimport (live belegt: Log-Zeile nach SIGKILL vorhanden).
- [x] **Crash WÄHREND der Asset-Platzierung** — importId wird vor `extract()` erzeugt, die platzierten
      sys_file-UIDs per Callback inkrementell (alle 200 + am Ende) ins ImportLog geschrieben. Auch ein
      Crash mitten in der Platzierung lässt die bereits platzierten Dateien per undo/Asset-GC entfernen.
- [~] **Asset-Platzierung beschleunigen** — Mikro-Fix umgesetzt: Verschieben statt Kopieren
      (`addFile(removeOriginal=true)`). **Befund:** bringt kaum etwas — die Importzeit (~5 min bei
      2000 Assets) ist NICHT vom Kopieren dominiert, sondern vom DataHandler (8002 Records) und vom
      per-Datei-FAL-Overhead (mime/sha1/Indexierung/Metadaten-Extraktion wie `getimagesize`).
      Echter Hebel nur über **Bulk-FAL-Indizierung** (eigene, riskante Baustelle: Dateien per Driver
      verschieben + `sys_file` sammeln + Metadaten gebündelt/verzögert) oder den streamenden
      Record-Import (s. o.). Bis dahin bewusst offen gelassen.

---

## Später

- [ ] **Streamender Import** — Datei records-weise verarbeiten (konstanter Speicher
      unabhängig von der Dateigröße), statt JSON/JSONL vollständig zu parsen. Der
      DataHandler-Teil ist bereits gechunkt; offen ist der streamende Parser/Importfluss.
      Befund Lasttest: der **Dry-Run-Diff** lädt alle Bestands-Records + die ganze Export-JSON in
      den Speicher (bei 2000 Seiten OOM unter dem PHP-Default 128 MB; mit 1 GB problemlos, Peak
      ~110–200 MB). Asset-*Handling* ist nachweislich flach — der Speicher skaliert mit der
      **Record-Zahl**, nicht mit der Asset-Größe. Streamender Diff würde auch das entschärfen.
- [x] **Dependabot** (`composer` + `github-actions`) — wöchentlich, gruppiert; vor Aktivierung
      up2date geprüft (actions/checkout v4→v7; composer ohne Major-Findings).
- [x] **Kategorie-MM für registrierte Record-Tabellen** — Export/Import zweiphasig, MM matcht
      generisch gegen alle registrierten Record-UIDs; Zählfelder werden beim Record-Import per TCA
      ausgeblendet. Getestet mit EXT:news + EXT:tt-address (real-world). Auch nach v13 gebackportet (1.2.0).
- [ ] **Multi-Source-Delta für Registry-Tabellen** — Idempotenz über `tx_impexpnl_uid_map`
      auch für registrierte Tabellen (heute nur `pages`/`tt_content`).
- [ ] **Veröffentlichung** über TER (Packagist ist über die Releases bereits bedient).

---

## Ganz langfristig / optional (niedrigste Priorität)

- [ ] **Bundle-Import: Inhalts-Abgleich beim Platzieren** — `BundleImportService::placeAsset`
      überspringt aktuell vorhandene Ziel-Dateien (`hasFile` → skip), ohne Inhalt zu prüfen.
      Anders als der `external`-Pfad warnt der Bundle-Import bei abweichendem Inhalt nicht.
      Optional: sha256 gegen das mitgepackte Manifest prüfen und bei Mismatch warnen
      (oder hinter Flag überschreiben). Bewusste Sicherheits-Default-Frage, daher niedrige Prio.

- [ ] **Sanitize-`hash`: HMAC statt blankem sha1** — `SanitizeService` pseudonymisiert per
      ungesalzenem `sha1`; für PII (z. B. E-Mail) per Rainbow-Table trivial rückführbar.
      Auf HMAC mit konfigurierbarem Key/Salt umstellen (deterministisch *innerhalb* eines
      Transfers, aber nicht global umkehrbar). Reine Härtung der bestehenden Strategie.

- [x] **CLI-Ausgabe-Upgrade ("Rich Terminal")** — Dry-Run-Vorschau mit Header, OK-Block,
      DIFF PREVIEW (farbige Balken) + `NEW`/`UPD`/`ASSET`-Labels; strikt hinter
      `$output->isDecorated()` (Plain-Tabellen-Fallback bei `--no-ansi`/Pipe/CI/`--json`).



- [ ] **Backend-Modul (GUI)** — bewusst ganz nach hinten gestellt. Export/Import bleiben
      CLI-first; das ist das Alleinstellungsmerkmal. Erst wenn der Bestand gehärtet und die
      Distribution rund ist, ggf. ein schlankes, berechtigungsgeschütztes Backend-Modul für
      Setups ohne Konsolenzugriff: MVP Export (Tree-Picker → Download), Import (Upload +
      dry-run/delta/conflict), Undo-Übersicht; große Importe via Scheduler statt im
      Web-Request. Dank entkoppelter Services wäre die GUI eine dünne Schicht
      (Controller + Fluid).
