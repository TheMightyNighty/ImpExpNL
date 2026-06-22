# Roadmap

Lebendes Dokument für geplante Arbeiten an ImpExpNL. Reihenfolge = grobe Priorität.

Legende: `[x]` erledigt · `[~]` teilweise vorhanden · `[ ]` offen.

---

## Erledigt

- **Hardening-Release** — Blöcke A–E (Konfig-/Registry-Validierung, Test-Härtung inkl.
  Workspace/Sprache/FAL/Rollback, differenzierte Exit-Codes, MariaDB-CI, Performance-Baseline,
  Doku-Klarheit, contract-getestete Beispielprofile). **Veröffentlicht 2026-06-21 als
  v2.1.0 (TYPO3 v14) / v1.1.0 (TYPO3 v13.4).** Details im [CHANGELOG](CHANGELOG.md).

Kleinere bewusst offen gelassene Punkte aus der Härtung:
- [~] **Profil-Contract-Tests**: Harness + `CoreProfileContractTest`/`RedirectProfileContractTest`/
      `CategoryProfileContractTest` stehen. Offen: YAML-getriebene Profile statt PHP-Subklassen,
      FAL-Profil, Profil pro Ziel-Extension.

---

## Aktuell in Arbeit — Meilenstein: Transfer- & Transport-Profile

> Branch: `feature/asset-manifest` (merge nach `main`, sobald rund).

Deklarative, **mitgelieferte und zugleich user-/extension-erweiterbare** Profile, die
*Was/Policy* (`transferProfiles`) von *Wie* (`assetTransports`) trennen. Sie leben in der
YAML-Config (`imp_exp_nl.yaml` / `Configuration/ImpExpNL.yaml`), **nicht** im `var/`-Runtime-
Verzeichnis, und **vereinheitlichen** das bestehende `--profile`/`ProfileService`.
Leitlinie: ImpExpNL baut **rsync/S3 nicht nach** — der Manifest ist der Vertrag.

### Fundament
- [~] **Asset-Manifest härten**: Export erzeugt einen `assets`-Block mit pro eindeutiger Datei
      `storage` + `identifier` + `size` + `sha256` (dedupliziert, von der Prüfsumme abgedeckt) plus
      `impexpnl_assets.json` neben der rsync-`impexpnl_assets.txt` (`AssetManifestService`,
      `AssetManifestExportTest`). **Offen:** import-seitige **Integritätsprüfung** (gleicher
      Identifier / anderer Inhalt → Warnung), **Asset-Preflight** (in `check`/`validate-config`:
      fehlt eine Datei auf dem Ziel?) und inkrementeller Transfer (unveränderte Dateien überspringen).

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
- [ ] **Veröffentlichung** über TER (Packagist ist über die Releases bereits bedient).

---

## Ganz langfristig / optional (niedrigste Priorität)

- [ ] **Backend-Modul (GUI)** — bewusst ganz nach hinten gestellt. Export/Import bleiben
      CLI-first; das ist das Alleinstellungsmerkmal. Erst wenn der Bestand gehärtet und die
      Distribution rund ist, ggf. ein schlankes, berechtigungsgeschütztes Backend-Modul für
      Setups ohne Konsolenzugriff: MVP Export (Tree-Picker → Download), Import (Upload +
      dry-run/delta/conflict), Undo-Übersicht; große Importe via Scheduler statt im
      Web-Request. Dank entkoppelter Services wäre die GUI eine dünne Schicht
      (Controller + Fluid).
