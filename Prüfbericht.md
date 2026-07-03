# Prüfbericht: ImpExpNL 2.1.0 (TYPO3 v14)

**Prüfgegenstand:** Extension `themightynighty/impexpnl`, Branch `feature/asset-manifest` (Stand caa555f)
**Prüfdatum:** 2026-07-03
**Prüfperspektive:** Betrieb in containerisierter Umgebung (Kubernetes/Behördenbetrieb), Datentransfer System A → entferntes System B unter Einhaltung eines Sicherheitsgefälles; zusätzlich Softwarearchitektur, Sicherheitslücken, logische Fehler.
**Methode:** Manuelle Code-Review aller Klassen unter `Classes/` (Schwerpunkt Integrität, Transport, Locking, Pfad-Handling), README/Dokumentation, Konfiguration.

---

## 1. Zusammenfassung

Die Extension ist handwerklich überdurchschnittlich: klare Service-Schichtung mit DI, CLI-first mit differenzierten Exit-Codes, cluster-fähiger DB-Lock, Auto-Rollback, PSR-3-Logging mit ELK-Anschluss, umfangreiche Tests. Für den Einsatzzweck „kontrollierter Content-Transfer über eine Datei" ist die Grundarchitektur richtig gewählt — es gibt bewusst **keine Netzwerkkomponente**, was den Betrieb über Schleusen/Datendioden erst möglich macht.

**Für den Einsatz über ein Sicherheitsgefälle ist die Extension in der aktuellen Form jedoch nicht freigabefähig.** Die zentrale Schutzfunktion (HMAC-Signatur) ist auf der Import-Seite umgehbar: Die Signaturprüfung ist optional, downgradebar und deckt das Asset-Manifest nicht ab. Zusätzlich kann der Bundle-Import über ein manipuliertes Archiv bzw. Manifest Dateien außerhalb des vorgesehenen Bereichs lesen und in FAL-Storages platzieren. Diese Punkte (F-01 bis F-03) müssen vor einem Einsatz High-Side behoben werden; die Behebung ist mit überschaubarem Aufwand möglich.

**Gesamturteil: Bedingt einsatzbereit.** Freigabe für Transfers zwischen gleich vertrauenswürdigen Zonen (Dev→Ref→Prod im selben Sicherheitsbereich) vertretbar; für Importe aus niedriger eingestuften Zonen erst nach Behebung von F-01–F-03.

---

## 2. Feststellungsübersicht

| Nr. | Schwere | Kategorie | Feststellung |
|---|---|---|---|
| F-01 | **Hoch** | Sicherheit | Signaturprüfung optional: Datei ohne `_meta.checksum` wird ungeprüft importiert — **behoben am 2026-07-03** (`import.require_signature`) |
| F-02 | **Hoch** | Sicherheit | Downgrade-Angriff: `sha256:`/Legacy-Prüfsumme wird auch bei konfiguriertem Signing-Key akzeptiert — **behoben am 2026-07-03** |
| F-03 | **Hoch** | Sicherheit | Bundle-Import: Archiv-Extraktion und Asset-Manifest ohne Pfad-/Inhaltsvalidierung (Path Traversal, Manifest nicht signiert) — **behoben am 2026-07-03** |
| F-04 | Mittel | Sicherheit/Betrieb | DB-Import-Lock ohne Inhaber-Token — Fremd-Freigabe möglich, paralleler Import denkbar — **behoben am 2026-07-03** |
| F-05 | Mittel | Sicherheit/DSGVO | PII-Pseudonymisierung per ungesalzenem `sha1()` — trivial per Wörterbuch umkehrbar — **behoben am 2026-07-03** (HMAC-SHA256 mit `hash_key`) |
| F-06 | Mittel | Sicherheit | `compressor` aus Transport-Config wird als Programmname an `proc_open` übergeben (keine Allowlist) — **behoben am 2026-07-03** |
| F-07 | Mittel | Sicherheit | Pfad-Präfix-Prüfungen ohne abschließenden `/` — Nachbarverzeichnisse mit gleichem Präfix passieren — **behoben am 2026-07-03** |
| F-08 | Mittel | Betrieb | Import-/Export-Prozess läuft vollständig als `_cli_`-Admin; keine Mandanten-/Rechte-Eingrenzung — **weitgehend behoben am 2026-07-03** (PID-Containment + `allowedTables`; Admin-Kontext selbst bleibt) |
| F-09 | Mittel | Betrieb | Import parst Datei komplett in den Speicher — OOM-/DoS-Risiko bei großen oder böswilligen Dateien — **gemildert am 2026-07-03** (`import.max_file_size_mb`, Default 512; Streaming-Import bleibt Roadmap) |
| F-10 | Niedrig | Sicherheit | Export-/Log-Dateien mit Standard-umask; Exporte können sensible Inhalte tragen — **behoben am 2026-07-03** (Artefakte `0600`) |
| F-11 | Niedrig | Betrieb | Datei-Lock degradiert stillschweigend, wenn `fopen` fehlschlägt — **behoben am 2026-07-03** (Warnung im Log) |
| F-12 | Niedrig | Doku | README („Import-Quelldatei muss im Projektverzeichnis liegen") vs. Code-Kommentar in `ImportCommand` widersprechen sich — **behoben am 2026-07-03** (Kommentar korrigiert) |
| F-13 | Niedrig | Architektur | `ImportService` als God-Class (1 272 Zeilen); Legacy-Prüfsummenformat ohne Auslauffrist |
| F-14 | Niedrig | Sicherheit | Signing-Key ohne Key-ID/Rotation; Profil-Konfiguration inkl. Pfaden landet im INFO-Log |

---

## 3. Feststellungen im Detail

### F-01 (Hoch) — Integritätsprüfung ist abwählbar durch Weglassen

`Classes/Service/ImportService.php:901`

```php
$checksum = $manifest->getChecksum();
if ($checksum !== null && !$this->integrityService->verify(...))
```

Fehlt `_meta.checksum` in der Importdatei, findet **keinerlei** Integritäts- oder Signaturprüfung statt — der Import läuft durch. Ein Angreifer, der die Datei auf dem Transportweg (Schleuse, Austauschverzeichnis, S3-Bucket) verändern kann, entfernt schlicht das Feld. Damit ist der HMAC-Schutz wirkungslos, genau in dem Szenario (Sicherheitsgefälle), für das er gedacht ist.

**Empfehlung:** Konfigurationsschalter `import.require_signature: true` (im Behördenbetrieb Default), der Importe ohne `hmac-sha256:`-Signatur mit Exit-Code 4 ablehnt. `impexpnl:check` sollte warnen, wenn ein Signing-Key gesetzt ist, der Schalter aber fehlt.

### F-02 (Hoch) — Signatur-Downgrade auf einfache Prüfsumme

`Classes/Service/IntegrityService.php:74-80`

`verify()` akzeptiert bei konfiguriertem Signing-Key weiterhin `sha256:`-Prüfsummen **und** das Legacy-Format (roher SHA256 nur über `pages`+`tt_content`). Ein Angreifer manipuliert die Daten, berechnet die einfache Prüfsumme neu und ersetzt das `hmac-sha256:`-Präfix durch `sha256:` — die Prüfung ist grün. Das Legacy-Format ist zusätzlich schwächer, weil Registry-Tabellen (z. B. `sys_redirect` → Redirect-Ziele!) gar nicht vom Hash abgedeckt sind.

**Empfehlung:** Ist ein Signing-Key konfiguriert (bzw. `require_signature` aktiv), dürfen ausschließlich `hmac-sha256:`-Signaturen akzeptiert werden. Legacy-Format nur noch mit explizitem Opt-in und Deprecation-Datum.

### F-03 (Hoch) — Bundle-Import: fehlende Härtung gegen manipulierte Archive

`Classes/Service/BundleImportService.php`

Drei zusammenhängende Probleme beim Import eines `.tar.gz`-Bundles:

1. **Extraktion ungehärtet** (`extractWithTar()`:176, `extractWithPharData()`:204): Es findet keine Validierung der Archiv-Mitglieder statt. GNU tar entschärft führende `/` und `..` zwar teilweise selbst, aber Symlink-Member gefolgt von Schreibzugriffen durch den Symlink sind ein klassischer Angriffsvektor; das Verhalten hängt zudem von der tar-Implementierung im Container-Image ab (busybox-tar verhält sich anders). Der PharData-Fallback hat eine eigene CVE-Historie zu `extractTo`.
2. **Path Traversal über das Manifest** (`placeAsset()`:242): `$localFile = $bundleDir . '/files/' . ltrim($identifier, '/')` — ein `identifier` wie `../../../../etc/passwd` bzw. relativ zum Entpackverzeichnis liegende Systempfade wird ungeprüft zusammengesetzt. Damit lassen sich **beliebige lesbare Dateien des Zielsystems in einen FAL-Storage kopieren** — bei `fileadmin` also potenziell ins öffentlich erreichbare Web-Root (Exfiltration von `settings.php`, Secrets etc.).
3. **Manifest nicht signiert:** `impexpnl_assets.json` liegt außerhalb des signierten Datenblocks. Selbst mit korrektem HMAC über die Exportdaten kann das Asset-Manifest (Pfade, Prüfsummen, Storage-IDs) frei manipuliert werden — ebenso die Binärdateien unter `files/` selbst.

**Empfehlung:** (a) `identifier` kanonisieren und gegen `..`/absolute Pfade ablehnen, zusätzlich `realpath`-Containment-Check auf `$bundleDir/files/`; (b) beim Extrahieren `tar --no-same-owner --no-same-permissions` plus nachgelagerten Symlink-Scan im Zielverzeichnis (oder Extraktion in PHP mit Member-Whitelist); (c) Asset-Manifest (inkl. Datei-SHA256) in den signierten `_meta`-Block aufnehmen und `placeAsset` die Datei-Prüfsumme vor Übernahme verifizieren lassen. Bis dahin gilt betrieblich: **Bundles nur aus gleich oder höher eingestuften Zonen importieren.**

### F-04 (Mittel) — Import-Lock ohne Inhaber-Bindung

`Classes/Service/ImportLockService.php:132-141, 92-103`

`releaseDbLock()` und `refresh()` arbeiten nur über `lock_id='import'`, ohne Inhaber-Token. Szenario im Cluster: Pod A hängt (kein Heartbeat), der Stale-Reaper von Pod B löscht den Lock und B beginnt einen Import; A wacht auf — sein Shutdown-Handler oder reguläres `release()` löscht nun **B's** Lock, ein dritter Import kann starten, während B noch schreibt. Ebenso frischt `refresh()` von A einen fremden Lock auf. Gleiches Prinzip bei `forceReleaseDbLock()` (unlock-Kommando), das ist aber ein bewusster Ops-Eingriff.

**Empfehlung:** Beim Acquire ein zufälliges Owner-Token in die Lock-Zeile schreiben; `refresh`/`release` nur mit `WHERE lock_id=... AND owner=<token>` ausführen. Wenn `refresh()` 0 Zeilen trifft → Import abbrechen (Lock verloren).

### F-05 (Mittel) — Schwache Pseudonymisierung im DSGVO-Scrubbing

`Classes/Service/SanitizeService.php:77`

Strategie `hash` verwendet ungesalzenes `sha1($value)`. E-Mail-Adressen und Benutzernamen sind damit per Wörterbuch-/Rainbow-Angriff in Sekunden re-identifizierbar — im Behördenkontext ist das keine Pseudonymisierung im Sinne von Art. 4 Nr. 5 DSGVO, vermittelt aber genau diese Erwartung („personenbezogene Daten verlassen Produktion gar nicht erst").

**Empfehlung:** `hash_hmac('sha256', $value, <secret>)` mit eigenem, nicht mitexportiertem Salt/Key; alternativ die Strategie in `pseudonymize` umbenennen und die Schwäche dokumentieren. Zusätzlich Doku-Hinweis, dass Sanitize-Regeln aktiv gepflegt werden müssen (neue Felder ⇒ neue Regeln).

### F-06 (Mittel) — Kompressor-Programm aus Konfiguration ohne Allowlist

`Classes/Transport/BundleTransport.php:75, 134`

`$config['compressor']` (aus `transportConfig`/Transfer-Profil-YAML) wird als `--use-compress-program=<compressor>` an `proc_open` übergeben. Die Array-Form von `proc_open` verhindert Shell-Injection der Argumente, aber der Wert bestimmt das **auszuführende Programm**. Da Registry-/Profil-YAML auch aus Dritt-Extensions eingesammelt wird, ist die Konfigurationsquelle breiter als „der eine Operator".

**Empfehlung:** Allowlist (`gzip`, `pigz`, `zstd`), alles andere ablehnen.

### F-07 (Mittel) — Präfix-Checks ohne Verzeichnistrenner

`Classes/Service/ImportService.php:890`, `Classes/Command/ExportCommand.php:113`, `Classes/Service/BundleImportService.php:148`, `Classes/Service/ProfileService.php:62`

Alle vier Containment-Checks nutzen `str_starts_with($real, $base)` ohne abschließenden `/`. Ein Pfad `/var/www/html-anderes/...` passiert den Check für Projektpfad `/var/www/html`. Praktisch ausnutzbar nur bei entsprechend benachbarten Verzeichnissen, aber ein klassischer, leicht behebbarer Fehler.

**Empfehlung:** Vergleich gegen `rtrim($base,'/').'/'` (bzw. Gleichheit für den Basispfad selbst); einmal zentral als Helper.

### F-08 (Mittel) — Vollständiger Admin-Kontext, keine Eingrenzung des Wirkbereichs

`Classes/Service/BootstrapService.php:49`

Der Import authentifiziert den `_cli_`-User (Admin) und schreibt per DataHandler ohne jede Eingrenzung auf den Ziel-Teilbaum. Eine manipulierte oder falsch adressierte Importdatei kann damit prinzipiell überall im System schreiben (inkl. Registry-Tabellen wie `sys_redirect`, die Weiterleitungen der gesamten Site steuern). Für ein CLI-Werkzeug ist Admin üblich — im Behördenbetrieb sollte der Wirkbereich dennoch technisch begrenzt werden.

**Empfehlung:** Mindestens: Validierung, dass alle importierten `pid`-Ziele unterhalb der angegebenen Ziel-PID liegen; optional konfigurierbare Tabellen-Allowlist pro Transfer-Profil (Registry-Tabellen explizit freischalten). Betrieblich: Import-Job als eigener K8s-Job mit eigenem DB-Account, nicht im Web-Pod.

### F-09 (Mittel) — Speicherverhalten des Imports (Verfügbarkeit)

`ImportService::parseJsonFile/parseJsonlFile` laden die gesamte Datei in den Speicher (in README/ROADMAP als bekannt dokumentiert). In Kubernetes heißt das: OOM-Kill → SIGKILL → Shutdown-Handler läuft nicht → Lock bleibt bis Stale-Timeout (Default 1 h) stehen. Eine übergroße/böswillige Datei ist damit ein einfacher DoS auf die Import-Pipeline. Das dokumentierte „undo-then-retry"-Muster mildert, ersetzt aber keine Limits.

**Empfehlung:** Konfigurierbares Dateigrößen-Limit vor dem Parsen (Fail fast mit klarem Exit-Code); Streaming-Import wie geplant priorisieren; Betriebsdoku: Memory-Limit des Jobs an getestete Baumgrößen koppeln (~337 MB Peak bei 10k/20k laut README).

### F-10 (Niedrig) — Dateirechte der Export-Artefakte

`Classes/Service/ExportWriter.php` schreibt Export-JSON, CSV, Asset-Listen mit Prozess-umask. Exporte können personenbezogene/interne Inhalte tragen und liegen im Projektverzeichnis (ggf. von anderen System-Usern lesbar). Empfehlung: `chmod 0600` nach dem Schreiben bzw. dediziertes Exportverzeichnis mit restriktiven Rechten dokumentieren.

### F-11 (Niedrig) — Stiller Verzicht auf den Datei-Lock

`Classes/Service/ImportLockService.php:58-70`: Schlägt `fopen` fehl, wird ohne Datei-Lock fortgefahren (`file => null`), ohne Warnung. Der DB-Lock fängt das ab, aber ein WARN-Log wäre angemessen (read-only-Filesystem im Container fiele sonst nie auf).

### F-12 (Niedrig) — Doku-/Code-Widerspruch beim Import-Pfad

README („Sicherheitsfunktionen": Quelldatei muss im Projektverzeichnis liegen) und der Kommentar in `ImportCommand.php:101-104` („absolute Pfade außerhalb bleiben unverändert nutzbar") widersprechen sich. Faktisch erzwingt `ImportService:890` das Projektverzeichnis — der Kommentar ist irreführend und sollte korrigiert werden, bevor sich jemand beim Ändern darauf verlässt.

### F-13 (Niedrig) — Architektur-Hinweise

- `ImportService` (1 272 Zeilen) bündelt Parsen, Validierung, Diff, Konflikte, DataHandler-Orchestrierung, Slugs, FAL und Logging — Kandidat für Zerlegung (Parser/Validator, Planner/Diff, Writer). Die übrigen Services sind angemessen geschnitten; Transport-Interface + Factory, Domain-Objekte (`ExportManifest`, `TransferProfile`, `ConflictStrategy` als Enum) und PSR-14-Events sind sauber.
- `IntegrityService::FORMAT_VERSION = 2`, aber Format 1 wird unbefristet akzeptiert (siehe F-02). Versionierung braucht eine Auslaufregel.
- Positiv: kein `exec`/Shell-String-Bau (nur `proc_open` mit Array), SQL durchgängig über QueryBuilder/`quoteIdentifier`, Doctrine-Transaktionen mit sauberem rollBack, CSV-Injection-Schutz vorhanden und korrekt (inkl. `-`, TAB, CR).

### F-14 (Niedrig) — Schlüssel- und Log-Hygiene

- Signing-Key ohne Key-ID: Rotation erzwingt einen „Big Bang" auf allen Systemen gleichzeitig. Empfehlung: `hmac-sha256:<key-id>:<hash>` und Mehrschlüssel-Verifikation.
- Key-Bezug per Env/`TYPO3_CONF_VARS` ist containertauglich (K8s-Secret als Env oder Mount); dokumentieren, dass der Key **nicht** in `additional.php` im Image eingebrannt werden darf.
- `ProfileService:102` loggt die komplette Profil-Konfiguration auf INFO — unkritisch, solange Profile keine Secrets tragen; sollte als Invariante dokumentiert sein.

---

## 4. Bewertung Betrieb / Container (Kubernetes, Behördenumfeld)

**Positiv:**

- CLI-first mit `--json` und differenzierten Exit-Codes (0–7) — sauber für Jobs/Pipelines und Monitoring; Exit-Guidance im Fehlerfall ist vorbildlich.
- Cluster-weiter DB-Lock mit Heartbeat + Stale-Timeout, Zustand (Lock, Import-Log, UID-Map) vollständig in der DB → überlebt Pod-Neustarts; Pod-Kill-Szenario (SIGKILL umgeht Shutdown-Handler) ist ehrlich dokumentiert inkl. undo-then-retry.
- Logging auf stdout/Syslog umkonfigurierbar; Namespace-Filterung für ELK/Graylog dokumentiert.
- Schreibzugriffe nur unter `var/` (transient, Logs, Locks, Profile) → read-only-Root-FS mit beschreibbarem `var/`-Volume ist machbar.
- Dry-Run als CI-Gate (setzt keinen Lock, schreibt nichts), Asset-Preflight mit eigenem Exit-Code 7.

**Lücken (neben F-04, F-09, F-11):**

- `var/log/impexpnl_transactions.log` wächst unrotiert; im Container besser ganz abschaltbar machen statt auf logrotate zu verweisen (Pods haben selten logrotate).
- Kein Metrics-/Healthcheck-Endpunkt nötig, aber ein `impexpnl:status --json`-basiertes Liveness-Beispiel (Lock-Age-Alarm) würde der Betriebsdoku guttun.
- Sicherheitsgefälle-Betriebsmodell fehlt als Dokumentationskapitel: Wer signiert, wo liegt der Key, wie läuft die Schleuse, was wird High-Side vor dem Import geprüft. Der Code kann es (nach F-01–F-03) — die Verfahrensbeschreibung für den IT-Sicherheitsbeauftragten fehlt.

## 5. Bewertung Übertragungsszenario A → B mit Sicherheitsgefälle

Das Grundmodell — Export als Datei, Transfer durch den Operator (rsync/Schleuse/Datendiode), Import mit Integritätsprüfung, Dry-Run-Gate, protokollierter Rollback — ist für gerichtete Übertragungen richtig. Kein ausgehender Netzwerkverkehr aus der Extension, keine eingebetteten Credentials. Die Kette ist aber nur so stark wie die Import-seitige Verifikation, und dort liegen die drei Hoch-Findings: Signatur optional (F-01), downgradebar (F-02), Assets/Manifest unsigniert (F-03). **Low→High-Importe erst nach deren Behebung**; High→Low-Exporte zusätzlich nur mit geprüften Sanitize-Regeln (F-05).

## 6. Maßnahmenplan (priorisiert)

1. **Vor Produktivsetzung über Sicherheitsgefälle:** F-01, F-02, F-03 (erzwingbare HMAC-Pflicht, kein Downgrade, Bundle-/Manifest-Härtung inkl. signierter Asset-Prüfsummen). — **Umgesetzt am 2026-07-03:** `import.require_signature` (Ablehnung ohne HMAC, Exit-Code 4, `check`-Warnung), Downgrade-Sperre in `IntegrityService::verify()`, Bundle-Härtung (Platzierung nur aus dem signierten `assets`-Block nach Verifikation, sha256-Prüfung jeder Datei, Identifier-/Containment-Validierung, Symlink-Ablehnung, `tar --no-same-owner --no-same-permissions`).
2. **Kurzfristig (nächstes Minor-Release):** F-04 (Lock-Owner-Token), F-05 (HMAC-Pseudonymisierung), F-06 (Kompressor-Allowlist), F-07 (Präfix-Checks). — **Umgesetzt am 2026-07-03:** Lock-Zeile trägt ein zufälliges Owner-Token (`tx_impexpnl_lock.owner`, Schema-Update nötig); Heartbeat/Release wirken nur auf den eigenen Lock, verlorener Lock bricht den Import mit `LockException` (Exit-Code 3) ab, `unlock` löscht weiterhin erzwungen. Sanitize-`hash`-Strategie nutzt HMAC-SHA256 mit `export.sanitize.hash_key`/`IMPEXPNL_SANITIZE_KEY` (ohne Schlüssel SHA256 + Log-Warnung). Kompressor-Allowlist (`gzip`, `pigz`) in `BundleTransport`. Alle vier Containment-Checks vergleichen jetzt mit Verzeichnistrenner.
3. **Mittelfristig:** F-08 (PID-Containment/Tabellen-Allowlist), F-09 (Größenlimit, Streaming-Import), F-10–F-12. — **F-08 umgesetzt am 2026-07-03:** Registry-/IRRE-Import ohne Quell-PID-Fallback (Records ohne gemappte Ziel-Seite werden übersprungen) + `allowedTables`-Allowlist pro Transfer-Profil; der `_cli_`-Admin-Kontext selbst bleibt (TYPO3-üblich für CLI), betriebliche Härtung via eigenem K8s-Job/DB-Account weiterhin empfohlen. — **F-09 gemildert am 2026-07-03:** Dateigrößen-Limit `import.max_file_size_mb` (Default 512 MB, 0 = aus) bricht übergroße Dateien vor dem Parsen sauber ab; der vollständig streamende Import (konstanter Speicher) bleibt als Roadmap-Punkt offen. — **F-10–F-12 umgesetzt am 2026-07-03:** Export-Artefakte inkl. Bundle-Archiv auf `0600` beschränkt, Datei-Lock-Degradierung wird als Warnung geloggt, irreführender Pfad-Kommentar in `ImportCommand` korrigiert. F-08/F-09 offen.
4. **Begleitend:** Betriebs-/Sicherheitskonzept-Kapitel „Einsatz über Sicherheitsgefälle" (Key-Management, Schleusenprozess, Rollen), Key-Rotation (F-14).

---

*Erstellt durch automatisierte Code-Review (Claude Code). Die Feststellungen basieren auf statischer Durchsicht; F-03 (tar-Verhalten) sollte zusätzlich dynamisch gegen das konkrete Container-Image verifiziert werden.*
