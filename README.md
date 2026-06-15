# Robbi Copy v4.0.0

Robbi Copy ist eine TYPO3-Extension für den strukturierten Export und Import von Seitenbäumen zwischen TYPO3-Instanzen. Die Extension wurde für den Einsatz mit TYPO3 v12 LTS, TYPO3 v13 LTS und dem Government Site Builder 11 (GSB 11) entwickelt.

Beim Export wird ein vollständiger Seitenbaum einschließlich aller Inhaltselemente, FAL-Referenzen, Systemkategorien, Redirects, Container-Layouts und IRRE-Relationen als JSON-Datei gespeichert. Beim Import werden alle internen Verknüpfungen (UIDs, Seiten-Links, Sprach-Overlays, Container-Hierarchien, Kategorie-Zuordnungen) automatisch auf die Zielstruktur umgeschrieben.

Zusätzliche Tabellen können rein deklarativ über YAML-Konfiguration registriert werden. PHP-Code ist dafür nicht erforderlich.

---

## Einsatzzweck

Die Extension adressiert den Bedarf, Inhalte kontrolliert zwischen TYPO3-Instanzen zu transferieren, beispielsweise von einer Entwicklungsumgebung über ein Referenzsystem auf das Produktivsystem. Ein direkter Datenbank-Transfer ist zwischen verschiedenen Instanzen nicht möglich, da sich die UIDs aller Records unterscheiden. Robbi Copy übernimmt das vollständige UID-Remapping, die Auflösung von Dateireferenzen über Dateipfade statt UIDs sowie die Umschreibung interner Links.

---

## Systemvoraussetzungen

- PHP 8.2 oder höher
- TYPO3 12.4 LTS oder 13.4 LTS
- Composer-basierte TYPO3-Installation

---

## Installation

Die Extension wird in das Package-Verzeichnis des TYPO3-Projekts kopiert. Anschließend werden Autoloading, Datenbankschema und Cache aktualisiert.

```bash
cp -r robbi_copy/ /var/www/html/packages/robbi_copy/

ddev composer dump-autoload
ddev exec vendor/bin/typo3 extension:setup
ddev exec vendor/bin/typo3 database:updateschema
ddev exec vendor/bin/typo3 cache:flush
```

Durch `database:updateschema` werden die Tabelle `tx_robbicopy_import_log` sowie das Feld `tx_robbicopy_remote_uid` in den Tabellen `pages` und `tt_content` angelegt. Das Feld dient der Erkennung bereits importierter Records bei wiederholten Imports.

Die Installation wird geprüft mit:

```bash
ddev exec vendor/bin/typo3 list robbicopy
```

Es werden sechs Befehle ausgegeben: `robbicopy:export`, `robbicopy:import`, `robbicopy:undo`, `robbicopy:status`, `robbicopy:list` und `robbicopy:check`.

Auf Systemen ohne DDEV wird das Präfix `ddev exec` weggelassen.

---

## Arbeitsablauf

Ein Content-Transfer folgt einem festen Ablauf:

**1. Export auf dem Quellsystem.** Der Seitenbaum wird ausgehend von einer Start-PID rekursiv eingesammelt und als JSON-Datei gespeichert. Parallel wird eine Textdatei mit allen referenzierten Dateipfaden erzeugt.

```bash
ddev exec vendor/bin/typo3 robbicopy:export 123 /var/www/html/var/export.json
```

**2. Dateitransfer.** Die physischen Bilder und Dokumente werden separat auf das Zielsystem übertragen. Die beim Export erzeugte Datei `robbicopy_assets.txt` dient als Eingabe für `rsync`:

```bash
rsync -avz --files-from=/var/www/html/var/robbicopy_assets.txt \
  /var/www/html/fileadmin/ user@zielserver:/var/www/html/fileadmin/
```

**3. Testlauf.** Vor dem eigentlichen Import wird eine Differenzanalyse durchgeführt. Es werden keine Daten geschrieben.

```bash
ddev exec vendor/bin/typo3 robbicopy:import /var/www/html/var/export.json 456 --dry-run
```

**4. Import.** Der eigentliche Import wird ausgeführt. Optional kann ein Workspace als Ziel angegeben werden.

```bash
ddev exec vendor/bin/typo3 robbicopy:import /var/www/html/var/export.json 456 --target-workspace=1
```

**5. Rollback.** Bei Bedarf wird der Import vollständig rückgängig gemacht.

```bash
ddev exec vendor/bin/typo3 robbicopy:undo
```

---

## Export

```bash
ddev exec vendor/bin/typo3 robbicopy:export <Start-PID> <Zielpfad> [Optionen]
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
| `--csv` | Erzeugung zusätzlicher CSV-Dateien (`robbicopy_pages.csv`, `robbicopy_tt_content.csv`). Diese Dateien können in Tabellenkalkulationsprogrammen geöffnet werden, um die Exportdaten vor dem Import tabellarisch zu prüfen oder mit anderen Datenquellen abzugleichen. |

### Erzeugte Dateien

Jeder Export erzeugt neben der JSON-Datei zwei Hilfsdateien:

**`robbicopy_assets.txt`** enthält eine Liste aller referenzierten Dateipfade (ein Pfad pro Zeile). Die Datei ist für die direkte Verwendung mit `rsync --files-from` formatiert. So werden nur die tatsächlich benötigten Dateien übertragen, nicht der gesamte `fileadmin`-Ordner.

**`robbicopy_broken_links.txt`** listet alle internen `t3://page?uid=`-Links auf, deren Zielseite nicht im Export enthalten ist. Diese Informationen ermöglichen eine Prüfung vor dem Import, welche Links auf dem Zielsystem ohne Ziel wären.

### Integritätsprüfung

Die JSON-Datei enthält einen `_meta`-Block mit Exportdatum, Quellsystem, TYPO3-Version und einer SHA256-Prüfsumme. Beim Import wird die Prüfsumme verifiziert. Falls die Datei nach dem Export verändert wurde, wird der Import abgebrochen.

### Multi-Site

Die Site-Konfiguration des Quellsystems (Identifier, Basis-URL, Sprachen) wird im `_meta`-Block der JSON-Datei mitexportiert. Diese Information wird beim Import für die automatische Slug-Regenerierung verwendet.

---

## Import

```bash
ddev exec vendor/bin/typo3 robbicopy:import <Datei> <Ziel-PID> [Optionen]
```

### Optionen

| Option | Beschreibung |
|---|---|
| `--dry-run` / `-d` | Differenzanalyse ohne Datenänderung. Es wird kein Lock gesetzt und keine Datenbankoperation ausgeführt. |
| `--delta` | Delta-Import. Jeder Record wird Feld-für-Feld mit dem bestehenden Record auf dem Zielsystem verglichen. Identische Records werden übersprungen, geänderte per DataHandler aktualisiert, neue angelegt. |
| `--conflict=X` | Konflikt-Strategie für den Delta-Import (siehe unten). |
| `--verbose` / `-v` | Erweiterte Ausgabe bei Änderungen. Für jeden geänderten Record werden die konkreten Feldunterschiede angezeigt (alter Wert, neuer Wert). |
| `--target-workspace=N` | Import in einen TYPO3-Workspace. Bei `0` (Standard) werden die Inhalte direkt im Live-System angelegt. Bei `1` oder höher werden sie in einen Entwurfs-Workspace importiert und können dort geprüft und freigegeben werden. |
| `--profile=name` | Laden eines Import-Profils. Alle anderen Argumente und Optionen werden aus dem Profil übernommen. |

### Differenzanalyse (Dry-Run)

Der Dry-Run vergleicht die Importdaten mit dem Bestand auf dem Zielsystem. Für jede Seite und jeden Inhalt wird ausgegeben, ob der Record neu ist, sich geändert hat oder identisch ist. Bei Verwendung von `--verbose` werden zusätzlich die konkreten Feldunterschiede aufgelistet.

```bash
ddev exec vendor/bin/typo3 robbicopy:import var/export.json 456 --dry-run --verbose
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

### Workspace-Import

Der Import in einen Workspace (z.B. `--target-workspace=1`) entspricht dem Freigabeprozess des GSB 11. Die importierten Inhalte erscheinen nicht sofort auf der Live-Website, sondern werden in einem Entwurfs-Workspace abgelegt. Die Veröffentlichung erfolgt über die regulären TYPO3-Workspace-Funktionen.

### Chunked Verarbeitung

Bei großen Seitenbäumen (mehrere tausend Records) werden die Daten automatisch in Batches à 500 Records verarbeitet. Seiten werden vor den Inhalten verarbeitet, damit die PID-Zuordnung beim Import der Inhalte bereits aufgelöst ist. Eine Fortschrittsanzeige gibt Rückmeldung über den Verarbeitungsstand.

### Slug-Regenerierung

Nach dem Import werden die `slug`-Felder aller importierten Seiten automatisch über den TYPO3 SlugHelper regeneriert. Damit wird sichergestellt, dass die Slugs zur Site-Konfiguration des Zielsystems passen, insbesondere beim Transfer zwischen verschiedenen Sites.

---

## Import-Profile

Wiederkehrende Import-Konfigurationen können als YAML-Dateien unter `var/robbicopy_profiles/` gespeichert werden.

```yaml
# var/robbicopy_profiles/dev_to_referenz.yaml
source_file: /var/www/html/var/export_dev.json
target_pid: 456
workspace: 1
delta: true
conflict: skip
```

Der Aufruf erfolgt ohne weitere Argumente:

```bash
ddev exec vendor/bin/typo3 robbicopy:import --profile=dev_to_referenz
```

Alle Parameter des Profils überschreiben die Kommandozeilen-Argumente. Verfügbare Felder: `source_file`, `target_pid`, `workspace`, `delta`, `conflict`, `depth`.

---

## Rollback

Jeder Import wird in der Datenbanktabelle `tx_robbicopy_import_log` protokolliert. Das Protokoll enthält die vollständige UID-Zuordnung zwischen Quell- und Ziel-UIDs.

```bash
# Letzten Import rückgängig machen
ddev exec vendor/bin/typo3 robbicopy:undo

# Bestimmten Import rückgängig machen
ddev exec vendor/bin/typo3 robbicopy:undo 20260329_142348
```

Der Rollback entfernt in fester Reihenfolge:

1. FAL-Referenzen (`sys_file_reference`) der importierten Records
2. Alle über die Table-Registry importierten Daten (Kategorie-Zuordnungen, Redirects, etc.)
3. Inhaltselemente (`tt_content`)
4. Seiten (`pages`), in umgekehrter Reihenfolge (Kindseiten vor Elternseiten)

Das Import-Protokoll wird nach erfolgreichem Rollback aus der Datenbank entfernt. Der Rollback wird im Transaktionslog dokumentiert.

---

## Status und Historie

```bash
# Aktueller Status: Lock, offene Imports, letzter Import
ddev exec vendor/bin/typo3 robbicopy:status

# Import-Historie als Tabelle
ddev exec vendor/bin/typo3 robbicopy:list
ddev exec vendor/bin/typo3 robbicopy:list --limit=50
```

Der Befehl `robbicopy:status` zeigt den Lock-Status, die Anzahl rollback-fähiger Imports und die Details des letzten Imports. Der Befehl `robbicopy:list` gibt die gesamte Import-Historie tabellarisch aus (Import-ID, Datum, Modus, Workspace, Anzahl Records, Quelldatei).

---

## Table-Registry

Die Table-Registry ermöglicht die Einbeziehung beliebiger Datenbanktabellen in den Export/Import-Prozess. Die Konfiguration erfolgt deklarativ über YAML-Dateien.

### Konfigurationsquellen

Robbi Copy liest Tabellenkonfigurationen aus zwei Quellen:

1. Die eigene `robbi_copy.yaml` im Extension-Ordner
2. Alle geladenen TYPO3-Extensions, die eine Datei `Configuration/RobbiCopy.yaml` enthalten

Durch die zweite Quelle können fremde Extensions eigene Tabellen registrieren, ohne Änderungen an Robbi Copy vorzunehmen.

### Tabellentyp: record

Record-Tabellen sind reguläre TYPO3-Tabellen mit `uid` und `pid`. Sie werden über den DataHandler importiert. UIDs werden gemapped, Links in konfigurierten Feldern umgeschrieben, und beim Rollback werden die Records gelöscht.

```yaml
robbicopy:
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
robbicopy:
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
robbicopy:
  tables:
    sys_category_record_mm:
      type: mm
      match_field: uid_foreign
      match_tablenames_field: tablenames
      match_tables: [pages, tt_content]
      category_match: path
```

### Beispiel: Extension-spezifische Tabellen registrieren

Die folgende Konfiguration wird in einer beliebigen Extension unter `Configuration/RobbiCopy.yaml` abgelegt. Sie registriert News-Beiträge, Tags und deren Kategorien:

```yaml
# packages/my_sitepackage/Configuration/RobbiCopy.yaml
robbicopy:
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

---

## Konfiguration

Die Datei `robbi_copy.yaml` im Extension-Ordner enthält die Standardkonfiguration:

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
      - tx_gsb_accordion_text

robbicopy:
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

**Integritätsprüfung.** Jede JSON-Datei enthält eine SHA256-Prüfsumme über die Seiten- und Inhaltsdaten. Beim Import wird die Prüfsumme verifiziert. Bei Abweichung wird der Import abgebrochen.

**Concurrency Lock.** Während eines Imports wird eine Lock-Datei gesetzt. Ein paralleler Import-Versuch wird mit einer Fehlermeldung abgewiesen. Der Dry-Run setzt keinen Lock.

**Konflikterkennung.** Im Delta-Modus werden Records mit lokal neuerem Zeitstempel als Konflikte erkannt. Die Behandlung ist über die Option `--conflict` konfigurierbar.

**DataHandler-Fehlerprotokollierung.** Fehler des TYPO3 DataHandler werden einzeln über den PSR-3 Logger protokolliert.

---

## GSB 11

**Container-Layouts.** Die `tx_container_parent`-Verknüpfungen des b13/container-Pakets werden beim Import auf die neuen UIDs umgeschrieben. Grid-Layouts, Akkordeons und Tabs bleiben nach dem Import intakt.

**FAL.** Dateireferenzen werden über den Dateipfad (`identifier`) aufgelöst, nicht über die `sys_file.uid`. Das ermöglicht den Transfer zwischen Instanzen mit unterschiedlicher FAL-Indexierung.

**Workspaces.** Der Import in einen Entwurfs-Workspace entspricht dem Freigabeprozess der Bundesverwaltung.

**Redirects.** `sys_redirect`-Einträge werden über die Table-Registry exportiert, importiert und beim Rollback entfernt. Links im `target`-Feld werden automatisch umgeschrieben.

**Kategorien.** Pfad-basiertes Matching ermöglicht die korrekte Zuordnung über verschiedene Instanzen hinweg, unabhängig von der lokalen Kategorie-UID.

**Multi-Site.** Slugs werden nach dem Import für die Ziel-Site regeneriert.

**Kubernetes/Air-Gap.** Das Import-Protokoll wird in der Datenbank gespeichert und überlebt Container-Neustarts.

---

## Protokollierung

Alle Services loggen über den PSR-3 Logger unter dem Namespace `Robbi\RobbiCopy`. Die Extension konfiguriert in der `ext_localconf.php` einen eigenen FileWriter, der alle Meldungen ab Level INFO in eine separate Logdatei schreibt.

| Ziel | Inhalt |
|---|---|
| `var/log/typo3_robbicopy_<hash>.log` | Alle Robbi-Copy-Meldungen (eigener Logger-Channel) |
| `var/log/robbicopy_transactions.log` | Menschenlesbare Import-/Rollback-Historie mit UID-Mappings |
| `tx_robbicopy_import_log` (Datenbanktabelle) | UID-Map für den Rollback, persistent über Container-Neustarts |

### Logger-Konfiguration anpassen

Die Standard-Konfiguration schreibt in eine Datei. Für den Betrieb in Kubernetes oder mit einem zentralen Log-Aggregator (ELK, Graylog) kann der Writer in `config/system/additional.php` überschrieben werden:

```php
$GLOBALS['TYPO3_CONF_VARS']['LOG']['Robbi']['RobbiCopy']['writerConfiguration'] = [
    \TYPO3\CMS\Core\Log\LogLevel::INFO => [
        \TYPO3\CMS\Core\Log\Writer\SyslogWriter::class => [],
    ],
];
```

In Kubernetes-Umgebungen, in denen Logs über stdout/stderr gesammelt werden, kann stattdessen der `PhpErrorLogWriter` verwendet werden:

```php
$GLOBALS['TYPO3_CONF_VARS']['LOG']['Robbi']['RobbiCopy']['writerConfiguration'] = [
    \TYPO3\CMS\Core\Log\LogLevel::INFO => [
        \TYPO3\CMS\Core\Log\Writer\PhpErrorLogWriter::class => [],
    ],
];
```

### Log-Filterung

Der Namespace `Robbi\RobbiCopy` wird vom TYPO3-Logging-Framework automatisch in den Pfad `Robbi.RobbiCopy` übersetzt. In ELK/Graylog kann nach diesem Pfad gefiltert werden, um ausschließlich Robbi-Copy-Meldungen anzuzeigen.

---

## Systemprüfung

Der Befehl `robbicopy:check` prüft ob alle Voraussetzungen für den Betrieb erfüllt sind. Die Prüfung ist vor dem ersten Einsatz auf einem neuen System und nach einem TYPO3-Update vorgesehen.

```bash
ddev exec vendor/bin/typo3 robbicopy:check
```

Geprüft werden:

- **Datenbankschema:** Existenz der Tabelle `tx_robbicopy_import_log` und des Feldes `tx_robbicopy_remote_uid` in `pages` und `tt_content`.
- **Dateisystem:** Schreibrechte auf `var/` und `var/log/`. Existenz des Profil-Verzeichnisses `var/robbicopy_profiles/`.
- **YAML-Konfiguration:** Syntaxprüfung der `robbi_copy.yaml`. Auflistung der registrierten Tabellen und Link-Rewrite-Felder.
- **Extension-Scan:** Erkennung aller geladenen Extensions, die eine eigene `Configuration/RobbiCopy.yaml` bereitstellen.

Bei Fehlern gibt der Befehl Exit-Code 1 zurück. Bei Warnungen ohne Fehler wird Exit-Code 0 zurückgegeben. Das ermöglicht die Einbindung in Deployment-Skripte:

```bash
vendor/bin/typo3 robbicopy:check || echo "Robbi Copy: Systemprüfung fehlgeschlagen"
```

---

## Exit-Codes

Alle CLI-Befehle geben standardisierte Exit-Codes zurück:

| Code | Bedeutung |
|---|---|
| `0` | Erfolg. Der Befehl wurde ohne Fehler ausgeführt. |
| `1` | Fehler. Der Befehl ist fehlgeschlagen (ungültige JSON, Datenbank-Fehler, Lock aktiv, etc.). |

Die Exit-Codes ermöglichen die Einbindung in automatisierte Workflows und Monitoring:

```bash
# In einem Deployment-Skript
vendor/bin/typo3 robbicopy:import --profile=dev_to_live \
  && echo "Import erfolgreich" \
  || { echo "Import fehlgeschlagen"; exit 1; }
```

```bash
# Periodische Ausführung über cron mit Fehlerbenachrichtigung
0 6 * * 1 /var/www/html/vendor/bin/typo3 robbicopy:import --profile=weekly_sync 2>&1 | mail -s "Robbi Copy Sync" ops@example.com
```

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

```bash
ddev composer require --dev typo3/testing-framework
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
ddev exec typo3DatabaseDriver=pdo_sqlite vendor/bin/phpunit -c packages/robbi_copy/phpunit.functional.xml
```

Alle Werte sind über Umgebungsvariablen überschreibbar. Die Werte in der `phpunit.functional.xml` dienen als Defaults.

### Tests ausführen

Unit-Tests und Functional-Tests werden über separate Konfigurationsdateien gestartet. Die Trennung ist erforderlich, da beide einen unterschiedlichen Bootstrap benötigen.

```bash
# Unit-Tests (kein Datenbankzugriff, schnell)
ddev exec vendor/bin/phpunit -c packages/robbi_copy/phpunit.unit.xml

# Functional-Tests (mit Datenbank)
ddev exec vendor/bin/phpunit -c packages/robbi_copy/phpunit.functional.xml

# Einzelner Test
ddev exec vendor/bin/phpunit -c packages/robbi_copy/phpunit.unit.xml --filter testDryRunDoesNotWriteToDatabase
```

### Unit-Tests

Die Unit-Tests prüfen die interne Logik der Service-Klassen:

| Klasse | Geprüfte Methoden |
|---|---|
| `ImportServiceTest` | `isRecordIdentical`, `buildRecordData`, `checkSingleConflict` — Feld-Vergleich, excludedFields, Typkonvertierung, Konflikt-Zeitstempel |
| `ExportServiceTest` | `parseSince`, Checksum-Berechnung, Asset-Extraktion, Broken-Link-Erkennung, Metadaten-Struktur |
| `TableRegistryServiceTest` | Link-Rewriting (`t3://page?uid=`), Kategorie-Pfad-Splitting |
| `ProfileServiceTest` | Profil-Validierung, Default-Werte, fehlende Pflichtfelder |

### Functional-Tests

Die Functional-Tests arbeiten mit einer echten Datenbank und prüfen den Gesamtablauf:

| Klasse | Szenarien |
|---|---|
| `ExportImportTest` | Export erzeugt valides JSON; Hidden-Filter; Tiefenbegrenzung; Import legt Records an; Links werden umgeschrieben; Sortierung wird erhalten |
| `DeltaImportTest` | Delta überspringt identische Records; Delta aktualisiert geänderte Records; Conflict-Skip bewahrt lokale Daten; Dry-Run schreibt nichts |
| `RollbackTest` | Rollback entfernt alle importierten Records; Import-Log wird bereinigt; Bei mehreren Imports wird nur der angegebene zurückgerollt |

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
│   └── Service/
│       ├── ImportServiceTest.php
│       ├── ExportServiceTest.php
│       ├── ProfileServiceTest.php
│       └── TableRegistryServiceTest.php
├── Functional/
│   ├── Service/
│   │   ├── ExportImportTest.php
│   │   ├── DeltaImportTest.php
│   │   └── RollbackTest.php
│   └── Fixtures/
│       ├── pages.csv
│       ├── tt_content.csv
│       ├── sys_category.csv
│       └── sys_category_record_mm.csv
└── Build/
    └── UnitTestsBootstrap.php
```

---

## Verzeichnisstruktur

```
robbi_copy/
├── Classes/
│   ├── Command/
│   │   ├── CheckCommand.php
│   │   ├── ExportCommand.php
│   │   ├── ImportCommand.php
│   │   ├── ListCommand.php
│   │   ├── StatusCommand.php
│   │   └── UndoCommand.php
│   ├── Event/
│   │   ├── ModifyExportDataEvent.php
│   │   └── ModifyImportDataEvent.php
│   └── Service/
│       ├── BootstrapService.php
│       ├── ExportService.php
│       ├── FalResolverService.php
│       ├── ImportService.php
│       ├── LinkRewriterService.php
│       ├── ProfileService.php
│       ├── RollbackService.php
│       └── TableRegistryService.php
├── Configuration/
│   ├── Services.yaml
│   └── TCA/Overrides/
│       ├── pages.php
│       └── tt_content.php
├── Tests/
│   ├── Unit/
│   ├── Functional/
│   └── Build/
├── composer.json
├── ext_emconf.php
├── ext_localconf.php
├── ext_tables.sql
├── phpunit.unit.xml
├── phpunit.functional.xml
├── robbi_copy.yaml
└── README.md
```
