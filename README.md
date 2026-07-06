# ImpExpNL — TYPO3 v14 (2.x)

ImpExpNL ist eine TYPO3-Extension für den strukturierten Export und Import von Seitenbäumen zwischen TYPO3-Instanzen. Diese Version ist für **TYPO3 v14 LTS** entwickelt (Doctrine DBAL 4). Für **TYPO3 v13.4 LTS** (und GSB 11) siehe den Branch `13.x` / die `1.x`-Releases.

Beim Export wird ein vollständiger Seitenbaum einschließlich aller Inhaltselemente, FAL-Referenzen, Systemkategorien, Redirects, Container-Layouts und IRRE-Relationen als JSON-Datei gespeichert. Beim Import werden alle internen Verknüpfungen (UIDs, Seiten-Links, Sprach-Overlays, Container-Hierarchien, Kategorie-Zuordnungen) automatisch auf die Zielstruktur umgeschrieben.

Zusätzliche Tabellen können rein deklarativ über YAML-Konfiguration registriert werden. PHP-Code ist dafür nicht erforderlich.

---

## Einsatzzweck

Die Extension adressiert den Bedarf, Inhalte kontrolliert zwischen TYPO3-Instanzen zu transferieren, beispielsweise von einer Entwicklungsumgebung über ein Referenzsystem auf das Produktivsystem. Ein direkter Datenbank-Transfer ist zwischen verschiedenen Instanzen nicht möglich, da sich die UIDs aller Records unterscheiden. ImpExpNL übernimmt das vollständige UID-Remapping, die Auflösung von Dateireferenzen über Dateipfade statt UIDs sowie die Umschreibung interner Links.

---

## Abgrenzung zu EXT:impexp

ImpExpNL ist als CLI-first-, automatisierungs- und cluster-taugliche Alternative zum TYPO3-Kernmodul **EXT:impexp** entworfen. Die wesentlichen Unterschiede:

| Aspekt | EXT:impexp (Kern) | **ImpExpNL** |
|---|---|---|
| **Transferformat** | T3D (serialisiert) / XML – opak, nicht diffbar | **JSON / JSONL / CSV** – lesbar, versionierbar, diff- & pipeline-tauglich |
| **Bedienung** | primär Backend-Modul (GUI) | **CLI-first**, headless, `--json`-Ausgabe + Exit-Codes für CI/CD & GitOps |
| **Wiederholter Import** | erzeugt Duplikate | **Delta-/Idempotenz-Modus** – erkennt bereits importierte Records über das Herkunfts-Mapping (`tx_impexpnl_uid_map`), überspringt Identische |
| **Konflikte** | keine Erkennung | **Konflikterkennung + Strategien** (overwrite / skip / ask) inkl. Feld-Diff |
| **Vorab-Prüfung** | – | **Dry-Run-Differenzanalyse** vor jedem Schreibvorgang |
| **Rückgängig machen** | kein gezieltes Undo | **Protokolliertes Rollback/Undo** jedes Imports + **automatischer Rollback bei Abbruch** (kein halber Baum) |
| **Große Bäume** | lädt Struktur komplett in den Speicher | **Batch-/Chunk-Verarbeitung** über DataHandler (10 000 Seiten / 20 000 Inhalte getestet, ~337 MB Speicher-Peak) |
| **Zusatztabellen** | via TCA-Flags / PHP-Code | **Deklarative YAML-Registry** – Redirects, Kategorien, News etc. ohne PHP |
| **Dateien (FAL)** | Binärdaten in die Datei eingebettet | **FAL über Dateipfade** – schlanke Transferdatei, Assets per `rsync` |
| **Integrität** | – | **Prüfsumme/Signatur** (optional `IMPEXPNL_SIGNING_KEY`) gegen Manipulation |
| **Nebenläufigkeit** | – | **Cluster-weiter Import-Lock** (DB-basiert) |
| **Sonstiges** | – | Workspace-Ziel-Import, Multi-Site-Slug-Regenerierung, PSR-14-Events, Kategorie-Pfad-Mapping |

EXT:impexp kann UID-Remapping, Relationen und FAL durchaus – die Tabelle hebt die Punkte hervor, die ImpExpNL zusätzlich bzw. anders löst: Automatisierung, Idempotenz, Rollback und deklarative Erweiterbarkeit.

---

## Grenzen & Nicht-Ziele

Damit klar ist, wofür ImpExpNL **nicht** gedacht ist:

- **Kein DB-Dump-Ersatz.** Übertragen werden Seiten, Inhalte und registrierte Tabellen –
  nicht der gesamte Datenbankzustand (Sessions, Caches, Backend-User, Systemkonfiguration).
  Für vollständige Klone ist ein DB-Dump das richtige Werkzeug.
- **Kein generischer Import beliebiger Tabellen.** Über `pages`/`tt_content` hinausgehende
  Tabellen müssen per YAML-Profil (Table-Registry) registriert sein. Ohne Profil werden sie
  nicht angefasst.
- **Kein Datei-Transfer.** Exportiert wird eine Asset-Liste, nicht die Binärdateien. Der
  physische Transfer des `fileadmin` bleibt Sache von `rsync`/`rclone`/S3 o. Ä.
- **Kein Merge-Tool.** Bei Konflikten wird pro Record entschieden (overwrite/skip/ask/abort),
  nicht feldweise zusammengeführt. Es gibt keine Drei-Wege-Merges.
- **Rollback ≠ Snapshot-Restore.** Der Rollback macht *einen protokollierten Import*
  rückgängig (die dabei angelegten/aktualisierten Records), er stellt keinen früheren
  Gesamtzustand der Instanz wieder her.
- **Direkte MM-Importe umgehen den DataHandler bewusst.** MM-Beziehungen (z. B. Kategorien)
  werden über Pfad-Mapping direkt geschrieben – das ist gewollt (Performance/Idempotenz),
  bedeutet aber, dass DataHandler-Hooks für diese MM-Tabellen nicht greifen.
- **Workspaces sind projektspezifisch.** Der Workspace-Ziel-Import nutzt die regulären
  TYPO3-Mechanismen; Staging-/Freigabe-Workflows (z. B. im GSB 11) bleiben Sache des Projekts.

---

## Systemvoraussetzungen

- PHP 8.2 oder höher
- TYPO3 14 LTS
- Composer-basierte TYPO3-Installation

> **Schnell ausprobieren:** Ein lauffähiges DDEV-Demo-Projekt (vanilla TYPO3 v14, Extension per Path-Repository) liegt unter [`Build/demo/`](Build/demo/README.md) — `ddev start` und loslegen.

---

## Versionen & Kompatibilität

ImpExpNL wird in zwei parallelen Linien gepflegt. Beim Composer-Install wird über die
Versions-Constraints **automatisch** die passende Linie gewählt — manuelles Pinnen ist
in der Regel nicht nötig.

| Extension | TYPO3 | PHP | Branch | Pflege |
|-----------|-------|-----|--------|--------|
| **2.x**   | v14 LTS    | 8.2 – 8.4 | [`main`](https://github.com/TheMightyNighty/ImpExpNL/tree/main) | aktiv (Features + Fixes) |
| **1.x**   | v13.4 LTS (+ GSB 11) | 8.2 – 8.3 | [`13.x`](https://github.com/TheMightyNighty/ImpExpNL/tree/13.x) | Wartung (Fixes) |

- **`main`** ist die aktuelle v14-Entwicklungslinie (Releases `2.x`).
- **`13.x`** pflegt die v13.4-Linie weiter (Releases `1.x`) — z. B. für GSB 11.
- Fixes werden im ältesten betroffenen Branch entwickelt und nach `main` vorgemerged.

Beispiel für gezieltes Pinnen (nur falls nötig):

```bash
composer require themightynighty/impexpnl:"^2.0"   # TYPO3 v14
composer require themightynighty/impexpnl:"^1.0"   # TYPO3 v13.4
```

---

## Installation

ImpExpNL ist eine Composer-Extension. **Empfohlener Weg ist Composer** – die passende
Linie (v14 = `^2.0`, v13.4 = `^1.0`) wird über die Versions-Constraints automatisch gewählt.

```bash
composer require themightynighty/impexpnl

vendor/bin/typo3 extension:setup
vendor/bin/typo3 database:updateschema
vendor/bin/typo3 cache:flush
```

(In einem DDEV-Projekt den Befehlen `ddev composer …` bzw. `ddev exec vendor/bin/typo3 …`
voranstellen.)

Durch `database:updateschema` werden die Tabellen `tx_impexpnl_import_log`, `tx_impexpnl_lock` und `tx_impexpnl_uid_map` angelegt. `tx_impexpnl_uid_map` hält das Herkunfts-Mapping (Quell-Record → Ziel-Record) zur Erkennung bereits importierter Records bei wiederholten Imports – Core-Tabellen (`pages`/`tt_content`) bleiben dabei unangetastet. Die Tabelle `tx_impexpnl_lock` realisiert den cluster-weiten Import-Lock. (Hinweis: Frühere Versionen nutzten ein Feld `tx_impexpnl_remote_uid` auf `pages`/`tt_content`; `impexpnl:migrate-legacy-schema` überführt es in die neue Tabelle.)

Die Installation wird geprüft mit:

```bash
vendor/bin/typo3 list impexpnl
```

Es werden folgende Befehle ausgegeben: `impexpnl:export`, `impexpnl:import`, `impexpnl:undo`, `impexpnl:status`, `impexpnl:list`, `impexpnl:check`, `impexpnl:validate-config`, `impexpnl:unlock` und `impexpnl:migrate-legacy-schema`.

### Weitere Bezugswege

- **Entwicklung / noch nicht auf Packagist:** als Path- oder VCS-Repository einbinden.
  Dazu in der `composer.json` des Projekts ein `repositories`-Eintrag auf das lokale
  Verzeichnis bzw. das Git-Repo, dann `composer require themightynighty/impexpnl:@dev`.
- **TER** (TYPO3 Extension Repository): optional, für Setups ohne Composer-Workflow.
- **DDEV-Demo:** ein lauffähiges Beispielprojekt (vanilla TYPO3 v14, Extension per
  Path-Repository) liegt unter [`Build/demo/`](Build/demo/README.md) – `ddev start` genügt.
  Es dient zum Ausprobieren, **nicht** als Installationsweg für Produktivprojekte.

---

## Arbeitsablauf

Ein Content-Transfer folgt einem festen Ablauf:

**1. Export auf dem Quellsystem.** Der Seitenbaum wird ausgehend von einer Start-PID rekursiv eingesammelt und als JSON-Datei gespeichert. Parallel wird eine Textdatei mit allen referenzierten Dateipfaden erzeugt.

```bash
ddev exec vendor/bin/typo3 impexpnl:export 123 /var/www/html/var/export.json
```

**2. Dateitransfer.** Die physischen Bilder und Dokumente werden separat auf das Zielsystem übertragen. Die beim Export erzeugte Datei `impexpnl_assets.txt` dient als Eingabe für `rsync`:

```bash
rsync -avz --files-from=/var/www/html/var/impexpnl_assets.txt \
  /var/www/html/fileadmin/ user@zielserver:/var/www/html/fileadmin/
```

**3. Testlauf.** Vor dem eigentlichen Import wird eine Differenzanalyse durchgeführt. Es werden keine Daten geschrieben.

```bash
ddev exec vendor/bin/typo3 impexpnl:import /var/www/html/var/export.json 456 --dry-run
```

**4. Import.** Der eigentliche Import wird ausgeführt. Optional kann ein Workspace als Ziel angegeben werden.

```bash
ddev exec vendor/bin/typo3 impexpnl:import /var/www/html/var/export.json 456 --target-workspace=1
```

**5. Rollback.** Bei Bedarf wird der Import vollständig rückgängig gemacht.

```bash
ddev exec vendor/bin/typo3 impexpnl:undo
```

---

## Export

```bash
ddev exec vendor/bin/typo3 impexpnl:export <Start-PID> <Zielpfad> [Optionen]
```

### Optionen

| Option | Beschreibung |
|---|---|
| `--depth=N` | Begrenzung der Rekursionstiefe. Bei `0` (Standard) wird der gesamte Baum exportiert. Bei `--depth=1` werden nur die Startseite und deren direkte Kindseiten erfasst. |
| `--include-hidden` | Einbeziehung versteckter und deaktivierter Records. Standardmäßig werden nur aktive Records exportiert. |
| `--pages=1,2,3` | Export einzelner Seiten ohne Rekursion. Die angegebenen PIDs werden direkt exportiert, Kindseiten werden nicht einbezogen. |
| `--exclude-pages=99,100` | Ausschluss bestimmter PIDs aus dem Export. Die Seiten und deren Inhalte werden übersprungen. |
| `--since=2026-01-01` | Zeitbasierter Filter. Es werden nur Records exportiert, deren `tstamp` nach dem angegebenen Datum liegt. Damit wird die Datenmenge bei Delta-Transfers erheblich reduziert. |
| `--content-types=text,textmedia` | Einschränkung auf bestimmte CTypes. Alle anderen Inhaltselemente werden nicht exportiert. |
| `--csv` | Erzeugung zusätzlicher CSV-Dateien (`impexpnl_pages.csv`, `impexpnl_tt_content.csv`). Diese Dateien können in Tabellenkalkulationsprogrammen geöffnet werden, um die Exportdaten vor dem Import tabellarisch zu prüfen oder mit anderen Datenquellen abzugleichen. |
| `--bundle-baseline=<manifest>` | Inkrementelles Bundle (nur mit `--transport=bundle`): Pfad zum Asset-Manifest des **Ziels** (`impexpnl_assets.json`, z. B. vorab per Schleuse geholt). Dateien, die dort bereits inhaltsgleich (sha256) gelistet sind, werden nicht eingepackt — das Bundle enthält nur neue/geänderte Assets. Fehlt eine übersprungene Datei auf dem Ziel wider Erwarten, meldet der Import das über den Asset-Preflight (Exit-Code `7`); es entsteht kein stiller Verlust. |
| `--jsonl` | Zeilenweise geschriebenes JSONL-Format (ein JSON-Objekt pro Zeile) — kompakt und speicherschonend beim Export (kein einzelnes `json_encode` über den gesamten Block). Die Zieldatei sollte auf `.jsonl` enden; der Import erkennt das Format automatisch. Das Standard-JSON-Format bleibt unverändert nutzbar. **Empfehlung: Für große Exporte (ab einigen tausend Records) JSONL verwenden** — nur dieses Format wird beim Import und Dry-Run-Diff records-weise gestreamt (konstanter Speicher); `.json` wird vollständig in den Speicher geparst. Siehe Abschnitt „Chunked Verarbeitung". |

### Erzeugte Dateien

Jeder Export erzeugt neben der JSON-Datei zwei Hilfsdateien:

**`impexpnl_assets.txt`** enthält eine Liste aller referenzierten Dateipfade (ein Pfad pro Zeile). Die Datei ist für die direkte Verwendung mit `rsync --files-from` formatiert. So werden nur die tatsächlich benötigten Dateien übertragen, nicht der gesamte `fileadmin`-Ordner.

**`impexpnl_broken_links.txt`** listet alle internen `t3://page?uid=`-Links auf, deren Zielseite nicht im Export enthalten ist. Diese Informationen ermöglichen eine Prüfung vor dem Import, welche Links auf dem Zielsystem ohne Ziel wären.

### Integritätsprüfung

Die JSON-Datei enthält einen `_meta`-Block mit Exportdatum, Quellsystem, TYPO3-Version und einer Prüfsumme über den gesamten Datenblock. Beim Import wird die Prüfsumme verifiziert. Falls die Datei nach dem Export beschädigt wurde, wird der Import abgebrochen. Mit konfiguriertem `IMPEXPNL_SIGNING_KEY` wird daraus ein HMAC-Signaturschutz (siehe Abschnitt „Sicherheitsfunktionen").

### Multi-Site

Die Site-Konfiguration des Quellsystems (Identifier, Basis-URL, Sprachen) wird im `_meta`-Block der JSON-Datei mitexportiert. Diese Information wird beim Import für die automatische Slug-Regenerierung verwendet.

---

## Import

```bash
ddev exec vendor/bin/typo3 impexpnl:import <Datei> <Ziel-PID> [Optionen]
```

### Optionen

| Option | Beschreibung |
|---|---|
| `--dry-run` / `-d` | Differenzanalyse ohne Datenänderung. Es wird kein Lock gesetzt und keine Datenbankoperation ausgeführt. |
| `--delta` | Delta-Import. Jeder Record wird Feld-für-Feld mit dem bestehenden Record auf dem Zielsystem verglichen. Identische Records werden übersprungen, geänderte per DataHandler aktualisiert, neue angelegt. |
| `--conflict=X` | Konflikt-Strategie für den Delta-Import: `overwrite` (Standard), `skip`, `ask`, `abort` (siehe unten). |
| `--verbose` / `-v` | Erweiterte Ausgabe bei Änderungen. Für jeden geänderten Record werden die konkreten Feldunterschiede angezeigt (alter Wert, neuer Wert). |
| `--target-workspace=N` | Import in einen TYPO3-Workspace. Bei `0` (Standard) werden die Inhalte direkt im Live-System angelegt. Bei `1` oder höher werden sie in einen Entwurfs-Workspace importiert und können dort geprüft und freigegeben werden. |
| `--profile=name` | Laden eines Import-Profils. Alle anderen Argumente und Optionen werden aus dem Profil übernommen. |

### Differenzanalyse (Dry-Run)

Der Dry-Run vergleicht die Importdaten mit dem Bestand auf dem Zielsystem. Für jede Seite und jeden Inhalt wird ausgegeben, ob der Record neu ist, sich geändert hat oder identisch ist. Bei Verwendung von `--verbose` werden zusätzlich die konkreten Feldunterschiede aufgelistet.

```bash
ddev exec vendor/bin/typo3 impexpnl:import var/export.json 456 --dry-run --verbose
```

### Delta-Import

Beim wiederholten Import derselben oder einer aktualisierten JSON-Datei werden mit `--delta` nur die tatsächlichen Änderungen verarbeitet. Identische Records werden anhand eines Feld-für-Feld-Vergleichs erkannt und übersprungen. System-Felder (`uid`, `pid`, `tstamp`, `crdate`, `sorting`) werden bei diesem Vergleich nicht berücksichtigt.

### Konflikterkennung und -behandlung

Ein Konflikt liegt vor, wenn ein Record auf dem Zielsystem einen neueren Zeitstempel hat als der entsprechende Record in der Export-Datei. Dies deutet darauf hin, dass der Record lokal nach dem Export bearbeitet wurde.

| Strategie | Verhalten |
|---|---|
| `--conflict=overwrite` | Der Export überschreibt die lokale Änderung. Dies ist das Standardverhalten. |
| `--conflict=skip` | Records mit Konflikten werden übersprungen. Alle anderen Records werden normal importiert. |
| `--conflict=ask` | Für jeden Konflikt wird interaktiv auf der Konsole abgefragt, ob überschrieben werden soll. |
| `--conflict=abort` | Beim ersten Konflikt wird der gesamte Import abgebrochen (Exit-Code `5`). Bereits geschriebene Teil-Records werden – wie bei jedem Abbruch – automatisch zurückgerollt. Gedacht für unbeaufsichtigte CI/CD-Läufe, in denen ein Konflikt eine bewusste Entscheidung erzwingen soll. |

### Exit-Codes (CI/CD)

Konflikte mit `--conflict=abort` brechen den Import ab (Exit-Code `5`); ein unbeaufsichtigter
Lauf kann so auf eine bewusste Entscheidung bestehen. Die vollständige Liste der
differenzierten Exit-Codes steht im Abschnitt [Exit-Codes](#exit-codes).

### Workspace-Import

Der Import in einen Workspace (z.B. `--target-workspace=1`) nutzt den regulären TYPO3-Workspace-Freigabeprozess (im GSB 11 der Redaktions-Workflow). Die importierten Inhalte erscheinen nicht sofort auf der Live-Website, sondern werden als Workspace-Versionen abgelegt und über die regulären TYPO3-Workspace-Funktionen freigegeben. Beim Freigeben (Publish) werden die Versionen mit korrekt umgeschriebenen Links nach Live überführt. Ein noch nicht freigegebener Workspace-Import lässt sich mit `impexpnl:undo` rückstandsfrei zurücknehmen (siehe Abschnitt „Rollback").

### Chunked Verarbeitung

Bei großen Seitenbäumen (mehrere tausend Records) werden die Daten automatisch in Batches à 500 Records verarbeitet. Seiten werden vor den Inhalten verarbeitet, damit die PID-Zuordnung beim Import der Inhalte bereits aufgelöst ist. Eine Fortschrittsanzeige gibt Rückmeldung über den Verarbeitungsstand.

Die Prüfsummenberechnung erfolgt inkrementell pro Record. Beim **Export** schreibt das JSONL-Format (`--jsonl`) zeilenweise und vermeidet so ein einzelnes, sehr großes `json_encode` über den gesamten Datenblock.

Beim **Import** begrenzt `import.max_file_size_mb` (Standard: 512, `0` = unbegrenzt) die Dateigröße: Übergroße Dateien werden **vor** dem Parsen mit klarer Fehlermeldung abgewiesen, statt den Prozess in ein OOM zu laufen (im Container: Pod-Kill, hängender Lock bis zum Stale-Timeout).

Der Speicherbedarf beim Import hängt vom Dateiformat ab:

- **JSONL** (`.jsonl`): `pages` und `tt_content` werden records-weise **gestreamt** — Import wie Dry-Run-Diff arbeiten batch-weise mit konstantem Speicher, unabhängig von der Dateigröße (gemessen: 180.000 Records / 43 MB, Peak 276 MB → 4 MB). **Für große Exporte ist JSONL daher das empfohlene Format.**
- **JSON** (`.json`): Die Datei wird vollständig in den Speicher geparst; der Spitzenbedarf skaliert mit der Record-Zahl. Für kleine bis mittlere Transfers unproblematisch — bei großen Beständen JSONL verwenden statt das PHP-`memory_limit` hochzudrehen.

**Hinweis zu registrierten Record-Tabellen (Auxiliary-Daten):** Auch im JSONL-Pfad werden die *nicht* gestreamten Bereiche — registrierte Record-Tabellen (z.B. `sys_redirect`, `tx_news_domain_model_news`), `sys_file_reference`, IRRE-Relationen und das Asset-Manifest — einmalig vollständig materialisiert, weil sie relational verflochten und typischerweise klein sind. Der Speicher skaliert hier mit der Record-Zahl dieser Tabellen. Enthält eine registrierte Tabelle sehr viele Datensätze (Größenordnung ab ~100.000), stehen heute zwei Stellschrauben zur Verfügung: das PHP-`memory_limit` des CLI-Aufrufs erhöhen (grobe Faustregel: ~1 KB pro Record zzgl. Grundbedarf) oder den Transfer aufteilen — die große Tabelle in einem separaten Lauf mit eigenem, eingeschränktem Export übertragen (z.B. per `--since` zeitlich partitioniert). Ein streamender Pfad für Registry-Tabellen ist bei Bedarf eine mögliche Ausbaustufe.

### Slug-Regenerierung

Nach dem Import werden die `slug`-Felder aller importierten Seiten automatisch über den TYPO3 SlugHelper regeneriert. Damit wird sichergestellt, dass die Slugs zur Site-Konfiguration des Zielsystems passen, insbesondere beim Transfer zwischen verschiedenen Sites.

---

## Import-Profile

Wiederkehrende Import-Konfigurationen können als YAML-Dateien unter `var/impexpnl_profiles/` gespeichert werden.

```yaml
# var/impexpnl_profiles/dev_to_referenz.yaml
source_file: /var/www/html/var/export_dev.json
target_pid: 456
workspace: 1
delta: true
conflict: skip
```

Der Aufruf erfolgt ohne weitere Argumente:

```bash
ddev exec vendor/bin/typo3 impexpnl:import --profile=dev_to_referenz
```

Das Profil liefert die Import-Konfiguration. Verfügbare Felder: `source_file`, `target_pid`, `workspace`, `delta`, `conflict`. Diese Profilwerte haben Vorrang vor den entsprechenden Kommandozeilen-Argumenten/-Optionen.

Die Laufzeit-Flags `--dry-run` und `--verbose` werden weiterhin über die Kommandozeile gesteuert und mit dem Profil kombiniert. So lässt sich ein Profil gefahrlos zuerst im Dry-Run prüfen:

```bash
ddev exec vendor/bin/typo3 impexpnl:import --profile=dev_to_referenz --dry-run
```

---

## Transfer-Profile (Policy-Guardrails)

Neben den Import-Profilen (Argument-Vorbelegung) gibt es **Transfer-Profile** in der YAML-Config (`transferProfiles`), die eine Umgebungs-Bindung und Policy-Leitplanken durchsetzen. Auswahl beim Import über `--transfer-profile=<name>`.

| Feld | Wirkung |
|---|---|
| `transport` | Referenz auf einen `assetTransports`-Eintrag (`external`/`bundle`) |
| `conflictStrategy` | Erzwungene Konflikt-Strategie (`overwrite`/`skip`/`ask`/`abort`) |
| `allowedContext` | Erlaubte TYPO3-Application-Contexts — verhindert die Ausführung in der falschen Umgebung (z. B. Prod-Paket auf Dev) |
| `requireDryRun` | `true` = Profil erlaubt nur Dry-Run-Läufe |
| `allowedTables` | **Wirkbereichs-Allowlist** für den Registry-Import: nicht gesetzt = alle registrierten Tabellen, gesetzt = nur die genannten (leere Liste = keine). `pages`/`tt_content`/FAL sind davon unberührt. |

```yaml
transferProfiles:
  stage_to_prod_release:
    transport: bundle_full
    conflictStrategy: abort
    allowedContext: [Production]
    allowedTables: [sys_redirect, sys_category_record_mm]
```

**PID-Containment.** Unabhängig vom Profil schreibt der Import Registry-Records und IRRE-Relationen nur noch auf Seiten, die dieser Import selbst angelegt bzw. gemappt hat. Records, deren Ziel-Seite nicht auflösbar ist, werden mit Warnung übersprungen — ein (manipulierter) Export kann damit keine Records an beliebige Stellen des Zielsystems schreiben.

---

## Rollback

Jeder Import wird in der Datenbanktabelle `tx_impexpnl_import_log` protokolliert. Das Protokoll enthält die vollständige UID-Zuordnung zwischen Quell- und Ziel-UIDs.

```bash
# Letzten Import rückgängig machen (mit Vorschau und Sicherheitsabfrage)
ddev exec vendor/bin/typo3 impexpnl:undo

# Nur Vorschau, ohne zu löschen
ddev exec vendor/bin/typo3 impexpnl:undo --dry-run

# Bestimmten Import ohne Rückfrage rückgängig machen
ddev exec vendor/bin/typo3 impexpnl:undo 20260329_142348_a1b2c3 --force
```

Vor dem Löschen zeigt `undo` eine Vorschau (betroffene Anzahl, Quelldatei). Wurden importierte Records nach dem Import lokal bearbeitet (neuerer `tstamp` als der Import), **bricht der Rollback ab** und listet die betroffenen Records auf – damit lokale Änderungen nicht unbemerkt verloren gehen. Mit `--force` wird trotzdem gelöscht (der Auto-Rollback nach einem Importabbruch nutzt diesen Pfad intern für seine eigenen Records). Ohne `--force` wird zusätzlich interaktiv bestätigt; `--dry-run` zeigt nur die Vorschau.

Der Rollback entfernt in fester Reihenfolge:

1. FAL-Referenzen (`sys_file_reference`) der importierten Records
2. Alle über die Table-Registry importierten Daten (Kategorie-Zuordnungen, Redirects, etc.)
3. Inhaltselemente (`tt_content`)
4. Seiten (`pages`), in umgekehrter Reihenfolge (Kindseiten vor Elternseiten)

Das Import-Protokoll wird nach erfolgreichem Rollback aus der Datenbank entfernt. Der Rollback wird im Transaktionslog dokumentiert. Die Lösch-Operationen werden – wie der Import – in einer Datenbank-Transaktion gebündelt, sodass der Rollback in der Größenordnung des Imports bleibt (siehe [`Documentation/PERFORMANCE.md`](Documentation/PERFORMANCE.md)).

Der Rollback läuft im selben Workspace wie der ursprüngliche Import (die `workspace_id` steht im Protokoll). Ein Workspace-Import wird damit korrekt rückgängig gemacht – die im Workspace angelegten Versionen werden entfernt, das Live-System bleibt unberührt.

---

## Status und Historie

```bash
# Aktueller Status: Lock, offene Imports, letzter Import
ddev exec vendor/bin/typo3 impexpnl:status
ddev exec vendor/bin/typo3 impexpnl:status --json

# Import-Historie als Tabelle
ddev exec vendor/bin/typo3 impexpnl:list
ddev exec vendor/bin/typo3 impexpnl:list --limit=50 --json

# Hängenden Import-Lock lösen
ddev exec vendor/bin/typo3 impexpnl:unlock
```

Der Befehl `impexpnl:status` zeigt den DB-Lock (Inhaber, Alter, Stale-Status), die Anzahl rollback-fähiger Imports und die Details des letzten Imports. `impexpnl:list` gibt die Import-Historie tabellarisch aus (Import-ID, Datum, Modus, Workspace, Anzahl Records, Quelldatei). `impexpnl:unlock` löst einen hängenden Import-Lock (mit Bestätigung, `--force` ohne Rückfrage). Alle drei unterstützen `--json` für die maschinenlesbare Ausgabe.

---

## Table-Registry

Die Table-Registry ermöglicht die Einbeziehung beliebiger Datenbanktabellen in den Export/Import-Prozess. Die Konfiguration erfolgt deklarativ über YAML-Dateien.

### Konfigurationsquellen

ImpExpNL liest Tabellenkonfigurationen aus zwei Quellen:

1. Die eigene `imp_exp_nl.yaml` im Extension-Ordner
2. Alle geladenen TYPO3-Extensions, die eine Datei `Configuration/ImpExpNL.yaml` enthalten

Durch die zweite Quelle können fremde Extensions eigene Tabellen registrieren, ohne Änderungen an ImpExpNL vorzunehmen.

### Tabellentyp: record

Record-Tabellen sind reguläre TYPO3-Tabellen mit `uid` und `pid`. Sie werden über den DataHandler importiert. UIDs werden gemapped, Links in konfigurierten Feldern umgeschrieben, und beim Rollback werden die Records gelöscht.

```yaml
impexpnl:
  tables:
    sys_redirect:
      type: record
      pid_field: pid          # Feld für die Seitenzuordnung
      uid_remap: true         # UID-Mapping aktivieren (erforderlich für Rollback)
      rewrite_links:          # Felder mit t3://page-Links
        - target
```

### Tabellentyp: mm

MM-Tabellen speichern Many-to-Many-Beziehungen ohne eigene UID. Der Import erfolgt über direktes Einfügen/Löschen in der Tabelle.

```yaml
impexpnl:
  tables:
    sys_category_record_mm:
      type: mm
      match_field: uid_foreign
      match_tablenames_field: tablenames
      match_tables: [pages, tt_content]
```

### Kategorie-Pfad-Mapping

Systemkategorien haben auf verschiedenen TYPO3-Instanzen unterschiedliche UIDs. Ein UID-basiertes Mapping führt daher zu falschen Zuordnungen.

Mit der Option `category_match: path` werden Kategorien beim Export über ihren vollständigen Pfad identifiziert (z.B. „Themen > Digitalisierung > E-Government"). Beim Import wird dieser Pfad auf dem Zielsystem Segment für Segment aufgelöst. Falls eine Kategorie auf dem Zielsystem nicht existiert, wird sie automatisch angelegt.

```yaml
impexpnl:
  tables:
    sys_category_record_mm:
      type: mm
      match_field: uid_foreign
      match_tablenames_field: tablenames
      match_tables: [pages, tt_content]
      category_match: path
```

### Beispiel: Extension-spezifische Tabellen registrieren

Die folgende Konfiguration wird in einer beliebigen Extension unter `Configuration/ImpExpNL.yaml` abgelegt. Sie registriert News-Beiträge, Tags und deren Kategorien:

```yaml
# packages/my_sitepackage/Configuration/ImpExpNL.yaml
impexpnl:
  tables:
    tx_news_domain_model_news:
      type: record
      pid_field: pid
      uid_remap: true
      rewrite_links: [bodytext]

    tx_news_domain_model_tag:
      type: record
      pid_field: pid
      uid_remap: true

    sys_category_record_mm:
      type: mm
      match_field: uid_foreign
      match_tablenames_field: tablenames
      match_tables:
        - pages
        - tt_content
        - tx_news_domain_model_news
      category_match: path
```

### Mitgelieferte, contract-getestete Profile

Zwei Profile liefert ImpExpNL bereits in der `imp_exp_nl.yaml` mit – sie gelten als
*unterstützt*, weil sie einen vollständigen Functional-Contract bestehen (Export → Import
→ Rollback, dazu profilspezifisch Link-Rewrite bzw. Delta-Idempotenz):

| Profil | Typ | Contract-Test | Geprüft |
|--------|-----|---------------|---------|
| `sys_redirect` | record | `RedirectProfileContractTest` | Export, UID-Remapping, `t3://`-Rewrite im `target`, externes Ziel bleibt, Rollback |
| `sys_category_record_mm` | mm | `CategoryProfileContractTest` | Export mit Pfaden, Pfad-Mapping auf neuen Inhalt, **Delta ohne Dublette**, Rollback |

Diese Tests (`Tests/Functional/Profile/`) sind zugleich die **Vorlage für eigene Profile**:
Wer eine weitere Tabelle „hart" machen will, kopiert einen dieser Tests, tauscht Fixture +
Tabelle und stellt sicher, dass der Contract grün bleibt.

> Hinweis: Registry-Tabellen vom Typ `record` (mit `uid_remap: true`) sind im
> Delta-Modus **idempotent**: Bereits importierte Records werden über das
> Herkunfts-Mapping (`tx_impexpnl_uid_map`, Multi-Source-fähig über `source_id`)
> aufgelöst und bei Gleichheit übersprungen bzw. aktualisiert statt dupliziert.
> MM-Tabellen mit `category_match: path` sind ebenfalls idempotent. Ohne
> `uid_remap` gibt es kein persistiertes Mapping und damit kein Delta.

---

## Konfiguration

Die Datei `imp_exp_nl.yaml` im Extension-Ordner enthält die Standardkonfiguration:

```yaml
export:
  include:
    file_references: true       # FAL-Referenzen mit Dateipfad exportieren

import:
  include:
    file_references: true       # FAL beim Import über Dateipfad auflösen
  container_support: true       # b13/container-Layouts remappen
  link_rewrite:
    fields:                     # Felder mit t3://page-Links
      - bodytext
      - pi_flexform
      # eigene/Extension-Felder ergänzen, z.B. bei GSB 11: tx_gsb_accordion_text

impexpnl:
  tables:
    sys_category_record_mm:
      type: mm
      match_field: uid_foreign
      match_tablenames_field: tablenames
      match_tables: [pages, tt_content]
      category_match: path

    sys_redirect:
      type: record
      pid_field: pid
      uid_remap: true
      rewrite_links: [target]
```

---

## Sicherheitsfunktionen

**Korruptionsschutz (Standard).** Jede JSON-Datei enthält eine SHA256-Prüfsumme über den **gesamten Datenblock** (alle Tabellen: `pages`, `tt_content`, `sys_file_reference`, IRRE-Relationen und alle Registry-Tabellen – nicht nur Seiten und Inhalte). Beim Import wird die Prüfsumme verifiziert; bei Abweichung wird der Import abgebrochen. Dies erkennt **versehentliche Beschädigung**. Es ist kein Schutz gegen gezielte Manipulation, da die reine Prüfsumme von jedem neu berechnet werden kann.

**Manipulationsschutz (optional).** Ist ein geheimer Schlüssel konfiguriert, wird statt der SHA256-Prüfsumme ein **HMAC-SHA256** gebildet. Ohne Kenntnis des Schlüssels lässt sich die Signatur nach einer Veränderung nicht neu erzeugen – der Import einer manipulierten Datei schlägt fehl. Der Schlüssel wird auf Quell- und Zielsystem identisch gesetzt, entweder per Umgebungsvariable `IMPEXPNL_SIGNING_KEY` oder in `config/system/additional.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKey'] = 'ein-langes-geheimnis';
```

Wird eine signierte Datei auf einem System ohne (passenden) Schlüssel importiert, wird der Import abgewiesen. Ist auf dem Zielsystem ein Schlüssel konfiguriert, werden **nur noch HMAC-Signaturen akzeptiert** – ein Downgrade auf eine bloße `sha256:`-Prüfsumme (die jeder neu berechnen kann) wird abgelehnt.

**Signaturpflicht (`import.require_signature`).** Für Importe über ein Sicherheitsgefälle (z. B. aus einer niedriger eingestuften Zone) kann die Signatur verpflichtend gemacht werden:

```yaml
# imp_exp_nl.yaml
import:
  require_signature: true
```

Damit werden auch Dateien **ohne** `_meta.checksum` abgewiesen (Exit-Code `4`) – das Weglassen der Prüfsumme ist dann kein Umgehungsweg mehr. `impexpnl:check` warnt, wenn ein Signing-Key gesetzt ist, die Signaturpflicht aber fehlt.

**Bundle-Härtung.** Beim Import eines `.tar.gz`-Bundles werden die Asset-Dateien erst **nach** erfolgreicher Prüfsummen-/Signaturprüfung der Export-Datei platziert. Quelle ist ausschließlich das von der Signatur abgedeckte Asset-Manifest (`assets`-Block der Export-Datei); jede Datei wird vor der Übernahme gegen ihre signierte SHA256-Prüfsumme verifiziert, Identifier werden gegen Path-Traversal geprüft, und Bundles mit Symlink-Einträgen werden abgelehnt.

**Cluster-weiter Concurrency-Lock.** Während eines Imports wird ein Lock in der Datenbanktabelle `tx_impexpnl_lock` gesetzt (wirkt über alle Pods/Knoten hinweg) und zusätzlich ein lokaler Datei-Lock. Ein paralleler Import-Versuch wird abgewiesen. Lang laufende Importe halten den Lock per Heartbeat frisch; bei einem Crash oder fataler Fehler wird der Lock über einen Shutdown-Handler bzw. nach einem konfigurierbaren Timeout automatisch freigegeben (Standard: 3600 s, einstellbar über `import.lock_stale_seconds` in der `imp_exp_nl.yaml`). Der Lock ist an ein zufälliges **Inhaber-Token** gebunden: Heartbeat und Freigabe wirken nur auf den eigenen Lock — wurde ein hängender Lock zwischenzeitlich freigeräumt und von einem anderen Import neu erworben, kann der alte Prozess diesen weder auffrischen noch löschen; er bricht stattdessen ab (Exit-Code 3). Der Dry-Run setzt keinen Lock.

**Abbruchsicheres Rollback-Protokoll.** Bricht ein Import nach dem Anlegen erster Records ab (Fehler, Timeout), wird automatisch ein Notfall-Protokoll mit der bis dahin angelegten UID-Zuordnung geschrieben. Die Teil-Daten lassen sich damit per `impexpnl:undo <Import-ID>` vollständig entfernen – es entstehen keine nicht-rückrollbaren Geisterdaten.

**Pfad-Begrenzung.** Sowohl der Export-Zielpfad als auch die Import-Quelldatei müssen innerhalb des Projektverzeichnisses liegen. Profilnamen werden gegen Path-Traversal abgesichert.

**CSV-Injection-Schutz.** Beim CSV-Export werden Werte mit führenden Formel-Triggern (`= + - @`) entschärft, sodass sie in Tabellenkalkulationen nicht als Formel ausgeführt werden.

**Konflikterkennung.** Im Delta-Modus werden Records mit lokal neuerem Zeitstempel als Konflikte erkannt. Die Behandlung ist über die Option `--conflict` konfigurierbar.

**DataHandler-Fehlerprotokollierung.** Fehler des TYPO3 DataHandler werden einzeln über den PSR-3 Logger protokolliert.

### Schlüssel-Rotation (Key-ID)

Mit einer optionalen **Key-ID** wird die Signatur als `hmac-sha256:<key-id>:<hash>` geschrieben — der Schlüssel lässt sich damit wechseln, ohne dass Pakete aus der Übergangszeit ungültig werden:

```php
// Aktiver Schlüssel + Kennung (alternativ Env IMPEXPNL_SIGNING_KEY / IMPEXPNL_SIGNING_KEY_ID)
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKey'] = 'neuer-schluessel';
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKeyId'] = 'k2026';

// Alt-Schlüssel für die Verifikation während der Übergangszeit
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKeys'] = [
    'k2025' => 'alter-schluessel',
];
```

Beim Import wird der Schlüssel über die Key-ID aufgelöst; Signaturen ohne Key-ID werden gegen den aktiven und alle hinterlegten Alt-Schlüssel geprüft. Nach Ablauf der Übergangszeit den Alt-Schlüssel aus `signingKeys` entfernen. Der Downgrade-Schutz gilt unverändert: Sobald irgendein Schlüssel konfiguriert ist, werden nur HMAC-Signaturen akzeptiert.

> **Veraltet:** Das Legacy-Prüfsummenformat aus ImpExpNL 1.x (Export-Format 1, roher SHA256 nur über `pages`+`tt_content`) wird beim Import noch akzeptiert (mit Log-Warnung), aber **mit Version 3.0 entfernt**. Alte Exporte rechtzeitig neu erzeugen.

---

## Einsatz über ein Sicherheitsgefälle

Für den Transfer aus einer niedriger in eine höher eingestufte Zone (Behördenbetrieb, Zonenmodell) ist ImpExpNL bewusst datei-basiert: Es gibt keine Netzwerkverbindung zwischen den Instanzen — der Transportweg (Schleuse, Datendiode, geprüfter Austauschbereich) bleibt vollständig unter Kontrolle des Betriebs. Empfohlene Mindest-Konfiguration und Ablauf:

**Konfiguration (beide Seiten):**

1. **Signing-Key setzen** — auf Quell- und Zielsystem identisch, als Secret bereitgestellt (Kubernetes-Secret als Env `IMPEXPNL_SIGNING_KEY` oder Mount). Den Schlüssel niemals ins Image oder Repository einbrennen; Key-ID (`IMPEXPNL_SIGNING_KEY_ID`) für spätere Rotation von Anfang an vergeben.
2. **Signaturpflicht auf dem Zielsystem:** `import.require_signature: true` — unsignierte oder auf bloße Prüfsummen heruntergestufte Dateien werden abgewiesen (Exit-Code `4`).
3. **Wirkbereich begrenzen:** Transfer-Profil mit `allowedContext` (nur die vorgesehene Umgebung), `allowedTables` (nur benötigte Registry-Tabellen) und ggf. `requireDryRun` für Prüf-Pipelines.
4. **Limits:** `import.max_file_size_mb` an die getesteten Baumgrößen und das Pod-Memory anpassen.

**Ablauf pro Transfer (High-Side):**

1. `impexpnl:check --json` — Voraussetzungen inkl. Signatur-Modus prüfen.
2. Paket durch die Schleuse übernehmen; bei `bundle` genügt eine Datei, die enthaltenen Assets sind über das signierte Manifest SHA256-geprüft.
3. `impexpnl:import <paket> <pid> --transfer-profile=<profil> --dry-run --json` — Gate: Diff und Asset-Preflight reviewen (bei Bedarf Vier-Augen-Prinzip auf dem Dry-Run-Ergebnis).
4. Import ausführen; Exit-Code auswerten (`4` = Integrität, `5` = Konflikt, `6` = zurückgerollter Teilimport).
5. Nachweis: Import-ID, `impexpnl:list --json` und `var/log/impexpnl_transactions.log` als Protokoll für die Revision; Rücknahme jederzeit per `impexpnl:undo <import-id>`.

**Rollen:** Der Export (Low-Side) signiert; die Schleuse transportiert nur; das Zielsystem verifiziert und entscheidet (Dry-Run-Gate, Konflikt-Policy). Für High→Low-Exporte (z. B. Prod→Dev) zusätzlich `export.sanitize` mit `hash_key` aktivieren, damit personenbezogene Daten die höhere Zone nicht verlassen.

### Ausgehender Transfer (`push`) und Inbox

Der Transport `push` legt das signierte Bundle **atomar** in ein konfiguriertes Outbox-Verzeichnis (z. B. eine gemountete Schleuse) ab — geschrieben wird zunächst nach `<outbox>/.incoming/<name>.part`, dann per `rename()` auf `<outbox>/<name>.tar.gz`. Ein Schleusenprozess sieht dadurch nie eine unvollständige Datei. ImpExpNL öffnet dabei **keinen** Netzwerk- oder Empfangskanal; der eigentliche Transfer über die Zonengrenze bleibt Sache des Betriebs (rsync/SFTP/Datendiode).

```bash
# Quelle: signiertes Bundle in die Outbox schieben
vendor/bin/typo3 impexpnl:export 123 var/export.jsonl --transport=push
# (Transport-Config: assetTransports.push_outbox.outbox in imp_exp_nl.yaml)
```

Auf der Zielseite listet `impexpnl:inbox` eingegangene Pakete — **read-only, ohne Auto-Import**:

```bash
vendor/bin/typo3 impexpnl:inbox --json        # transfer.inbox_path aus der Config
vendor/bin/typo3 impexpnl:inbox /pfad/inbox   # oder explizites Verzeichnis
```

**Empfang ≠ Anwendung:** Ein Paket in der Inbox löst nie selbsttätig einen Import aus. Der Import bleibt der bestehende explizite Schritt (`impexpnl:import <inbox>/<paket> <pid>`) mit voller Signatur-/Dry-Run-Prüfung — ein entfernter Akteur, der eine Datei in die Inbox legt, kann damit keine DB-Schreibzugriffe auslösen.

---

## Praxis-Szenarien

ImpExpNL ist eine generische TYPO3-Extension und benötigt kein bestimmtes Sitepackage. Die folgenden Szenarien funktionieren auf normalem TYPO3 v14 ebenso wie auf **GSB 11** (Government Site Builder, TYPO3-13-basiert; dort über den `13.x`-Branch) — die GSB-Kompatibilität ist ein Bonus, keine Voraussetzung.

**Container-Layouts.** Die `tx_container_parent`-Verknüpfungen des b13/container-Pakets werden beim Import auf die neuen UIDs umgeschrieben. Grid-Layouts, Akkordeons und Tabs bleiben nach dem Import intakt.

**FAL.** Dateireferenzen werden über den Dateipfad (`identifier`) aufgelöst, nicht über die `sys_file.uid`. Das ermöglicht den Transfer zwischen Instanzen mit unterschiedlicher FAL-Indexierung.

**Workspaces.** Der Import in einen Entwurfs-Workspace nutzt den regulären TYPO3-Workspace-Freigabeprozess (z. B. den Redaktions-Workflow im GSB 11).

**Redirects.** `sys_redirect`-Einträge werden über die Table-Registry exportiert, importiert und beim Rollback entfernt. Links im `target`-Feld werden automatisch umgeschrieben.

**Kategorien.** Pfad-basiertes Matching ermöglicht die korrekte Zuordnung über verschiedene Instanzen hinweg, unabhängig von der lokalen Kategorie-UID.

**Multi-Site.** Slugs werden nach dem Import für die Ziel-Site regeneriert.

**Kubernetes/Air-Gap.** Das Import-Protokoll wird in der Datenbank gespeichert und überlebt Container-Neustarts.

---

## Protokollierung

Alle Services loggen über den PSR-3 Logger unter dem Namespace `Robbi\ImpExpNL`. Die Extension konfiguriert in der `ext_localconf.php` einen eigenen FileWriter, der alle Meldungen ab Level INFO in eine separate Logdatei schreibt.

| Ziel | Inhalt |
|---|---|
| `var/log/typo3_impexpnl_<hash>.log` | Alle ImpExpNL-Meldungen (eigener Logger-Channel) |
| `var/log/impexpnl_transactions.log` | Menschenlesbare Import-/Rollback-Historie mit UID-Mappings |
| `tx_impexpnl_import_log` (Datenbanktabelle) | UID-Map für den Rollback, persistent über Container-Neustarts |

### Log-Rotation

Die Datei `var/log/impexpnl_transactions.log` wird fortlaufend ergänzt (Append) und nicht automatisch rotiert. Im Dauerbetrieb sollte sie über das System-`logrotate` einbezogen werden:

```
/var/www/html/var/log/impexpnl_transactions.log {
    weekly
    rotate 12
    compress
    missingok
    notifempty
    copytruncate
}
```

Der Logger-Channel (`typo3_impexpnl_<hash>.log`) wird über das TYPO3-Logging-Framework geschrieben; alternativ kann dort ein rotierender bzw. zentraler Writer konfiguriert werden (siehe unten). In Kubernetes-Umgebungen empfiehlt sich ohnehin ein stdout-/Syslog-Writer ohne lokale Dateien.

### Logger-Konfiguration anpassen

Die Standard-Konfiguration schreibt in eine Datei. Für den Betrieb in Kubernetes oder mit einem zentralen Log-Aggregator (ELK, Graylog) kann der Writer in `config/system/additional.php` überschrieben werden:

```php
$GLOBALS['TYPO3_CONF_VARS']['LOG']['Robbi']['ImpExpNL']['writerConfiguration'] = [
    \TYPO3\CMS\Core\Log\LogLevel::INFO => [
        \TYPO3\CMS\Core\Log\Writer\SyslogWriter::class => [],
    ],
];
```

In Kubernetes-Umgebungen, in denen Logs über stdout/stderr gesammelt werden, kann stattdessen der `PhpErrorLogWriter` verwendet werden:

```php
$GLOBALS['TYPO3_CONF_VARS']['LOG']['Robbi']['ImpExpNL']['writerConfiguration'] = [
    \TYPO3\CMS\Core\Log\LogLevel::INFO => [
        \TYPO3\CMS\Core\Log\Writer\PhpErrorLogWriter::class => [],
    ],
];
```

### Log-Filterung

Der Namespace `Robbi\ImpExpNL` wird vom TYPO3-Logging-Framework automatisch in den Pfad `Robbi.ImpExpNL` übersetzt. In ELK/Graylog kann nach diesem Pfad gefiltert werden, um ausschließlich ImpExpNL-Meldungen anzuzeigen.

---

## Systemprüfung

Der Befehl `impexpnl:check` prüft ob alle Voraussetzungen für den Betrieb erfüllt sind. Die Prüfung ist vor dem ersten Einsatz auf einem neuen System und nach einem TYPO3-Update vorgesehen.

```bash
ddev exec vendor/bin/typo3 impexpnl:check
```

Geprüft werden:

- **Datenbankschema:** Existenz der Tabellen `tx_impexpnl_import_log`, `tx_impexpnl_lock` und `tx_impexpnl_uid_map`.
- **Dateisystem:** Schreibrechte auf `var/` und `var/log/`. Existenz des Profil-Verzeichnisses `var/impexpnl_profiles/`.
- **Registry-Validierung (Preflight):** `check` führt dieselbe Prüfung wie `impexpnl:validate-config` aus — registrierte Tabellen existieren, `pid_field`/`rewrite_links`/MM-Felder und `match_tables` referenzieren bekannte TCA-Tabellen, `type` gültig (inkl. Herkunfts-Angabe und „Did you mean?"-Vorschlägen bei Tippfehlern).
- **Laufzeit-Checks:** FAL-Default-Storage erreichbar, Import-Lock-Status, Signing-Key-Modus (HMAC aktiv/inaktiv), Parsing der Profile inkl. Existenz des Ziel-Workspaces.
- **Extension-Scan:** Erkennung aller geladenen Extensions, die eine eigene `Configuration/ImpExpNL.yaml` bereitstellen.

Für die reine Konfigurationsprüfung ohne Laufzeit-Checks gibt es zusätzlich `impexpnl:validate-config`. Beide Befehle unterstützen `--json`.

Bei einem Fehler geben `check`/`validate-config` Exit-Code **`2`** (ungültige Konfiguration) zurück, sonst `0`; Warnungen allein führen nicht zum Fehlschlag. Die vollständige Liste der Exit-Codes steht im Abschnitt [Exit-Codes](#exit-codes). Das ermöglicht die Einbindung in Deployment-Skripte:

```bash
vendor/bin/typo3 impexpnl:check || echo "ImpExpNL: Systemprüfung fehlgeschlagen (siehe Exit-Code)"
```

---

## Exit-Codes

Alle CLI-Befehle geben differenzierte Exit-Codes zurück, damit Pipelines gezielt
reagieren können (z. B. Lock → erneut versuchen, Konflikt → Review erzwingen). Mit
`--json` steht der Code zusätzlich im Feld `exitCode`.

| Code | Bedeutung |
|---|---|
| `0` | Erfolg |
| `1` | Generischer/unerwarteter Fehler |
| `2` | Ungültige Konfiguration oder Profil (`validate-config`/`check`, Profil-Parsing) |
| `3` | Ein anderer Import hält den Lock (DB- oder Datei-Lock) |
| `4` | Prüfsummen-/Signaturprüfung der Importdatei fehlgeschlagen |
| `5` | Konflikte erkannt und `--conflict=abort` gesetzt |
| `6` | Import mitten drin abgebrochen; Teilimport wurde (auto-)zurückgerollt |
| `7` | Referenzierte Dateien/Assets fehlen *(reserviert für künftigen Strict-Asset-Modus; Standard überspringt fehlende Dateien)* |

Die Exit-Codes ermöglichen die Einbindung in automatisierte Workflows und Monitoring:

```bash
# In einem Deployment-Skript
vendor/bin/typo3 impexpnl:import --profile=dev_to_live \
  && echo "Import erfolgreich" \
  || { echo "Import fehlgeschlagen"; exit 1; }
```

```bash
# Periodische Ausführung über cron mit Fehlerbenachrichtigung
0 6 * * 1 /var/www/html/vendor/bin/typo3 impexpnl:import --profile=weekly_sync 2>&1 | mail -s "ImpExpNL Sync" ops@example.com
```

---

## CI/CD & Pipeline-Betrieb

ImpExpNL ist CLI-first und für unbeaufsichtigte GitOps-/Kubernetes-Pipelines ausgelegt.

### Maschinenlesbare Ausgabe (`--json`)

`import`, `export`, `check`, `status`, `list` und `undo` unterstützen `--json`. Die Ausgabe ist
ein einzelnes JSON-Objekt mit `success` (bool) und befehlsspezifischen Feldern; Fortschrittsbalken
und Titel werden unterdrückt.

```bash
vendor/bin/typo3 impexpnl:import var/export.jsonl 42 --delta --json
# {"success":true,"dryRun":false,"importId":"...","stats":{"new":12,"updated":3,"skipped":480,
#  "conflict_skipped":0,"errors":0},"durationMs":1840}
```

Für Dashboards relevante Metriken: `stats.{new,updated,skipped,errors}` und `durationMs` (Import),
`bytes`/`durationMs` (Export). Exit-Code `1` zeigt zusätzlich einen Fehlschlag an (auch bei
`stats.errors > 0`).

### Idempotenz & Reproduzierbarkeit

- **Delta-Modus** (`--delta`): identische Records werden übersprungen — wiederholte Läufe desselben
  Pakets sind ein No-Op (sichere Retries).
- **Stabiles UID-Mapping** über die Tabelle `tx_impexpnl_uid_map` (Quellsystem + Quell-UID → Ziel-UID):
  ein Quell-Record landet bei jedem Lauf auf demselben Ziel-Record; mehrere Quellsysteme bleiben
  über `source_id` unterscheidbar.
- **Deterministischer Export** mit Integritäts-Prüfsumme (`sha256:`/`hmac-sha256:`) — das Paket ist
  versionier- und review-bar (Content-as-Code im Git).

### Pipeline-Muster (Kubernetes / GitOps)

Empfohlene Job-Sequenz pro Ziel (Namespace mit eigener DB):

```bash
set -euo pipefail

# 1) Preflight: Voraussetzungen + Lock prüfen
vendor/bin/typo3 impexpnl:check --json
vendor/bin/typo3 impexpnl:status --json   # lock.active=true → ggf. abbrechen/warten

# 2) Gate: Dry-Run-Diff vor der echten Änderung (z. B. dev→prod)
vendor/bin/typo3 impexpnl:import "$PKG" "$PID" --delta --dry-run --json

# 3) Import; bei Fehler automatisch zurückrollen
if ! vendor/bin/typo3 impexpnl:import "$PKG" "$PID" --delta --json; then
    vendor/bin/typo3 impexpnl:undo --force --json   # letzten (Teil-)Import entfernen
    exit 1
fi
```

**Pod-Kill-Resilienz (wichtig in Kubernetes):** Ein harter SIGKILL (OOM, Eviction, Timeout) umgeht
den eingebauten Auto-Rollback. Der nächste Lauf erkennt den stehengebliebenen Lock
(`impexpnl:status`) und das als „abgebrochen" markierte Import-Protokoll; dann gilt **undo-then-retry**
statt blindem Neustart. Pod-Memory ausreichend dimensionieren (große Bäume) oder JSONL nutzen.

### Migration aus älteren Versionen

`impexpnl:migrate-legacy-schema` überführt bestehende Installationen nach dem
Schema-Update. Der Befehl erkennt beide Altstände automatisch:

- **ImpExpNL 1.x → 2.0:** das Herkunfts-Feld `tx_impexpnl_remote_uid` auf
  `pages`/`tt_content` wird in die neue Tabelle `tx_impexpnl_uid_map` überführt.
- **Vorgänger-Extension „robbi_copy":** Daten aus `tx_robbicopy_*` werden übernommen.

```bash
vendor/bin/typo3 extension:setup                        # neues Schema (tx_impexpnl_uid_map etc.) anlegen
vendor/bin/typo3 impexpnl:migrate-legacy-schema         # Herkunfts-Mapping/Altdaten übernehmen
vendor/bin/typo3 impexpnl:migrate-legacy-schema --drop-legacy   # optional: Alt-Spalten/-Tabellen entfernen
```

> **Reihenfolge wichtig:** erst migrieren, dann `--drop-legacy` – sonst geht das
> Herkunfts-Mapping verloren. Der Befehl ist idempotent und pipeline-tauglich.

---

## PSR-14 Events

Für Anforderungen, die über die deklarative Table-Registry hinausgehen, stehen zwei PSR-14 Events zur Verfügung:

- `ModifyExportDataEvent` — wird nach dem Sammeln aller Daten und vor dem Schreiben der JSON-Datei gefeuert
- `ModifyImportDataEvent` — wird nach dem Import gefeuert; die vollständige UID-Map steht zur Verfügung

---

## Automatisierte Tests

### Überblick

Die Extension enthält eine Test-Suite aus Unit-Tests und Functional-Tests. Unit-Tests prüfen isolierte Logik-Bausteine ohne Datenbank. Functional-Tests führen vollständige Export/Import/Rollback-Zyklen mit einer dedizierten Testdatenbank durch.

Die Testdatenbank wird pro Testlauf automatisch erstellt und nach Abschluss gelöscht. Die Produktivdatenbank wird nicht berührt.

### Voraussetzungen

Das `typo3/testing-framework` (`^9.3` für TYPO3 v14) ist bereits als `require-dev` in der `composer.json` eingetragen und wird durch `composer install` mitinstalliert. Bei Bedarf manuell:

```bash
ddev composer require --dev typo3/testing-framework:^9.3
```

### Datenbank-Konfiguration

Die Functional-Tests benötigen eine Datenbankverbindung. Standardmäßig ist in der `phpunit.functional.xml` die MariaDB-Verbindung der DDEV-Umgebung konfiguriert:

```xml
<env name="typo3DatabaseDriver" value="mysqli"/>
<env name="typo3DatabaseHost" value="db"/>
<env name="typo3DatabasePort" value="3306"/>
<env name="typo3DatabaseUsername" value="root"/>
<env name="typo3DatabasePassword" value="root"/>
```

Das Testing Framework erstellt für jeden Test eine eigene Datenbank, führt den Test aus und löscht die Datenbank danach.

Alternativ kann SQLite als Datenbank verwendet werden. Dabei wird kein Server benötigt, die Daten werden in einer temporären Datei gespeichert:

```bash
ddev exec typo3DatabaseDriver=pdo_sqlite vendor/bin/phpunit -c packages/imp_exp_nl/phpunit.functional.xml
```

Alle Werte sind über Umgebungsvariablen überschreibbar. Die Werte in der `phpunit.functional.xml` dienen als Defaults.

### Tests ausführen

Unit-Tests und Functional-Tests werden über separate Konfigurationsdateien gestartet. Die Trennung ist erforderlich, da beide einen unterschiedlichen Bootstrap benötigen.

```bash
# Unit-Tests (kein Datenbankzugriff, schnell)
ddev exec vendor/bin/phpunit -c packages/imp_exp_nl/phpunit.unit.xml

# Functional-Tests (mit Datenbank)
ddev exec vendor/bin/phpunit -c packages/imp_exp_nl/phpunit.functional.xml

# Einzelner Test
ddev exec vendor/bin/phpunit -c packages/imp_exp_nl/phpunit.unit.xml --filter testDryRunDoesNotWriteToDatabase
```

### Release-Pflichtläufe: MariaDB & Real-World-E2E

Die Functional-Suite läuft in der Standard-CI häufig gegen SQLite. Der Real-World-Test (siehe `Prüfbericht.md`, Abschnitt 7) hat gezeigt, dass MySQL/MariaDB-spezifisches Verhalten dort unsichtbar bleibt (affected-rows-Semantik, implizite Commits durch `TRUNCATE`, Wert-Repräsentationen). **Vor jedem Release** sind deshalb zwei zusätzliche Läufe verpflichtend:

```bash
# 1) Functional-Suite gegen echtes MariaDB 11.4 (Docker; erstellt den DB-Container selbst)
Build/test-functional-mariadb.sh

# 2) Kompletter Transfer-Lebenszyklus über zwei echte TYPO3-Instanzen
Build/e2e/run.sh
# skalierbar: PAGES=2000 ASSETS=2000 FILESIZE=204800 Build/e2e/run.sh
```

Das E2E-Skript (`Build/e2e/run.sh`) erwartet die Instanz-Container `impexpnl-src`/`impexpnl-tgt` (TYPO3 mit Extension als Path-Repository auf das Arbeitsverzeichnis) samt MariaDB-Sidecars `impexpnl-db-src`/`impexpnl-db-tgt` und prüft automatisiert: signierten Bundle-Export mit Key-ID, Schleusen-Transfer, Abweisung bei falschem Schlüssel (Exit 4), Dry-Run-Gate, Voll-Import mit Asset-Hash-Verifikation, Delta-No-Op-Idempotenz, SIGKILL mitten im Import mit undo-then-retry sowie Rollback auf die exakte Baseline. Exit-Code 0 = alle Prüfungen bestanden. Der Seed-Generator (`Build/e2e/seed_generate.php`) erzeugt dafür echte Bilddateien in konfigurierbarer Menge/Größe.

### Testdaten generieren

Für Last- und Entwicklungstests ohne große Quell-Instanz erzeugt ein Dev-Werkzeug synthetische Importdateien beliebiger Größe (mit gültiger Prüfsumme):

```bash
# JSON
php Build/generate-testdata.php --pages=5000 --content-per-page=8 --out=var/big.json
# JSONL (kompakter, speicherschonend)
php Build/generate-testdata.php --pages=20000 --content-per-page=5 --format=jsonl --out=var/big.jsonl
```

Die erzeugte Datei wird wie ein regulärer Export importiert:

```bash
vendor/bin/typo3 impexpnl:import var/big.json <ziel-pid>
```

Der Functional-Test `LargeTreeImportTest` nutzt denselben Generator und ist über Umgebungsvariablen skalierbar (`IMPEXPNL_PERF_PAGES`, `IMPEXPNL_PERF_CONTENT`, `IMPEXPNL_PERF_FORMAT=jsonl`); er gibt Laufzeit und Speicher-Peak aus.

### Unit-Tests

Die Unit-Tests prüfen die interne Logik der Service- und Domain-Klassen (Auswahl):

| Klasse | Schwerpunkt |
|---|---|
| `IntegrityServiceTest` | Prüfsumme/HMAC, Downgrade-Sperre, Key-Rotation (Key-ID, `signingKeys`), Legacy-Format |
| `BundleImportServiceTest` | Bundle-Härtung: Path-Traversal-Schutz, signierte Asset-Prüfsummen |
| `ConflictResolverTest` / `ImportServiceTest` | Feld-Vergleich (inkl. Leer-Äquivalenz), Konflikt-Zeitstempel, `buildRecordData` |
| `ConfigurationServiceTest` | Defaults & Grenzen (Lock-Timeout, Dateigrößen-Limit, Signaturpflicht) |
| `ExportServiceTest` | `parseSince`, Checksum, Asset-Extraktion, Broken-Link-Erkennung, Metadaten |
| `TableRegistryServiceTest` / `ProfileServiceTest` | Link-Rewriting, Kategorie-Pfade; Profil-Validierung |
| `Domain/*` | `ConflictStrategy`, `ExportManifest`, `PageLinkRewriter`, `UidMap` |

### Functional-Tests

Die Functional-Tests arbeiten mit einer echten Datenbank und prüfen den Gesamtablauf (Auswahl der ~40 Klassen):

| Bereich | Szenarien |
|---|---|
| `ExportImportTest`, `JsonlRoundtripTest` | Export/Import-Roundtrips (JSON + JSONL), Hidden-Filter, Tiefenbegrenzung, Link-Rewriting, Sortierung |
| `DeltaImportTest`, `DryRunMatchesImportTest` | Delta-Idempotenz, Konflikt-Strategien, Dry-Run schreibt nichts und stimmt mit echtem Import überein |
| `RollbackTest`, `RollbackSafetyTest`, `FullRoundtripAbortTest` | Rollback vollständig & gezielt, Schutz lokal geänderter Records, Auto-Rollback nach Abbruch |
| `ImportLockTest`, `ExitCodeTest` | Cluster-Lock inkl. Owner-Token/Stale-Reaping, differenzierte Exit-Codes |
| `Profile/*ContractTest` | Contract je Registry-Profil: Export → Import → Rollback (Core, FAL, Redirects, Kategorien, News, tt_address) |
| `Transport/Bundle*` | Bundle-Roundtrip inkl. Asset-Platzierung und Rollback-GC |
| `RegistryContainmentTest`, `TransferProfileTest`, `SanitizeTest` | PID-Containment, `allowedTables`/Kontext-Bindung, PII-Scrubbing (HMAC) |
| `Workspace*`, `Language*`, `Fal*` | Workspace-Import/-Publish, Übersetzungen, FAL-Randfälle |
| `LargeTreeImportTest`, `PerformanceBaselineTest` | Skalierung und Performance-Regressionsschutz |

### Fixture-Daten

Die Testdaten werden als CSV-Dateien unter `Tests/Functional/Fixtures/` bereitgestellt. Jede CSV-Datei entspricht einer Datenbanktabelle. Die erste Zeile enthält die Spaltennamen, jede weitere Zeile einen Record.

Mitgelieferte Fixtures:

| Datei | Inhalt |
|---|---|
| `pages.csv` | 6 Testseiten (Baumstruktur, versteckte Seite, Sprachversion) |
| `tt_content.csv` | 5 Inhaltselemente (Text, Textmedia, interner Link, Sprachversion) |
| `sys_category.csv` | 4 Kategorien (hierarchische Struktur) |
| `sys_category_record_mm.csv` | 2 Kategorie-Zuordnungen |

### Eigene Tests hinzufügen

**Unit-Test.** Eine bestehende Testmethode wird kopiert und mit geänderten Eingabedaten versehen:

```php
#[Test]
public function customFieldIsPreservedInBuildRecordData(): void
{
    $source = ['uid' => 1, 'title' => 'Test', 'tx_myext_field' => 'Wert'];
    $result = $this->callBuildRecordData($source);

    self::assertArrayHasKey('tx_myext_field', $result);
    self::assertEquals('Wert', $result['tx_myext_field']);
}
```

**Functional-Test.** Eine Fixture-CSV wird erstellt, im Test geladen und das Ergebnis geprüft:

```php
#[Test]
public function gsbAccordionContentIsExported(): void
{
    $this->importCSVDataSet(__DIR__ . '/../Fixtures/my_gsb_content.csv');

    $json = GeneralUtility::makeInstance(ExportService::class)->exportTree(1);
    $data = json_decode($json, true);

    $accordions = array_filter($data['tt_content'], fn($c) => $c['CType'] === 'accordion');
    self::assertNotEmpty($accordions);
}
```

**Fixture-CSV.** Die Spaltennamen entsprechen den Datenbankfeldern:

```csv
"uid","pid","CType","header","tx_gsb_accordion_text","sorting","hidden","deleted"
100,2,"accordion","Titel","<p>Inhalt</p>",256,0,0
```

### Teststruktur

```
Tests/
├── Unit/
│   ├── ConfigurationSchemaTest.php        # YAML-Config gegen JSON-Schema
│   ├── Domain/                            # ConflictStrategy, ExportManifest,
│   │                                      # ManifestReader (In-Memory/Streaming),
│   │                                      # PageLinkRewriter, UidMap
│   └── Service/                           # BundleImport (Härtung), Configuration,
│                                          # ConflictResolver, Export, Import,
│                                          # Integrity (inkl. Key-Rotation),
│                                          # Profile, TableRegistry
├── Functional/
│   ├── Command/                           # CLI-Smoke- und Status-Tests
│   ├── Profile/                           # Contract-Tests der Registry-Profile
│   │                                      # (Core, FAL, Redirects, Kategorien,
│   │                                      # News, tt_address, deklarativ)
│   ├── Service/                           # Export/Import/Delta/Rollback, Locks,
│   │                                      # Workspaces, Sprachen, FAL, Assets,
│   │                                      # Sanitize, Transfer-Profile,
│   │                                      # Registry-Containment, Performance
│   ├── Transport/                         # Bundle: Roundtrip, Transport, Asset-GC
│   └── Fixtures/                          # CSV-Fixtures je Tabelle
└── Build/                                 # Test-Bootstraps
```

Ergänzend unter `Build/`: `test-functional-mariadb.sh` (Functional-Suite gegen MariaDB), `e2e/run.sh` + `e2e/seed_generate.php` (Real-World-E2E über zwei Instanzen), `generate-testdata.php` (synthetische Importdateien).

---

## Verzeichnisstruktur

```
imp_exp_nl/
├── Classes/
│   ├── Command/          # check, export, import, list, migrate-legacy-schema,
│   │                     # status, undo, unlock, validate-config
│   ├── Domain/           # ConflictStrategy, ExitCode, ExportManifest,
│   │                     # PageLinkRewriter, SystemFields, TransferProfile, UidMap
│   ├── Event/            # ModifyExportDataEvent, ModifyImportDataEvent (PSR-14)
│   ├── Exception/        # typisierte Fehler mit Exit-Codes (Lock, Integrity, …)
│   ├── Service/          # Export/Import/Rollback, ManifestLoader + JsonlStreamReader
│   │                     # (streamender JSONL-Import), Integrity (HMAC/Key-Rotation),
│   │                     # Locks, TableRegistry, AssetManifest, Sanitize, Configuration,
│   │                     # RootlineCacheGuard, Repositories (ImportLog, UidMap)
│   └── Transport/        # external/bundle/push-Transport + Factory
├── Configuration/
│   ├── Services.yaml
│   └── Schema/ImpExpNL.schema.json    # JSON-Schema für YAML-Autocomplete/CI
├── Build/
│   ├── test-functional-mariadb.sh     # Release-Pflichtlauf gegen MariaDB
│   ├── e2e/                           # Real-World-E2E (run.sh, seed_generate.php)
│   ├── generate-testdata.php
│   └── demo/                          # DDEV-Demo-Projekt
├── Documentation/PERFORMANCE.md
├── Tests/                             # siehe Teststruktur oben
├── composer.json
├── ext_emconf.php
├── ext_localconf.php
├── ext_tables.sql
├── phpunit.unit.xml
├── phpunit.functional.xml
├── imp_exp_nl.yaml
├── Prüfbericht.md                     # Sicherheits-Review + Real-World-Verifikation
├── CHANGELOG.md
├── LICENSE
└── README.md
```

## Lizenz

Copyright © 2026 Robert Schleiermacher

Diese Extension ist freie Software und wird unter der GNU General Public
License in der Version 2 oder einer späteren Version (`GPL-2.0-or-later`)
veröffentlicht. Der vollständige Lizenztext befindet sich in der Datei
[`LICENSE`](LICENSE).

Die Lizenzwahl ergibt sich aus den Vorgaben von TYPO3: Der Core steht unter
`GPL-2.0-or-later`, und eine Extension ist ein davon abgeleitetes Werk. Die
GPL gewährt das Recht, die Software zu nutzen, zu verändern und weiterzugeben,
sofern abgeleitete Werke unter denselben Bedingungen bereitgestellt werden.

Die Veröffentlichung erfolgt in der Hoffnung, dass die Software nützlich ist,
jedoch ohne jegliche Gewährleistung; auch ohne die implizite Gewährleistung
der Marktreife oder der Eignung für einen bestimmten Zweck. Einzelheiten regelt
die GNU General Public License.

### Weiterpflege durch Dritte

Die `GPL-2.0-or-later` deckt sämtliche zur Nutzung und Weiterentwicklung
erforderlichen Rechte ab; eine gesonderte Rechteübertragung ist für die
Übernahme der Pflege nicht erforderlich. Wird die Urheberschaft dauerhaft auf
eine andere Stelle übertragen, sind lediglich die Copyright-Vermerke in den
Dateiköpfen (`Classes/**/*.php`) und in diesem Abschnitt anzupassen; der Inhalt
der Datei `LICENSE` bleibt unverändert.
