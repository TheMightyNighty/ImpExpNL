# ImpExpNL v2.0.0 (TYPO3 v14)

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

Die Extension wird in das Package-Verzeichnis des TYPO3-Projekts kopiert. Anschließend werden Autoloading, Datenbankschema und Cache aktualisiert.

```bash
cp -r imp_exp_nl/ /var/www/html/packages/imp_exp_nl/

ddev composer dump-autoload
ddev exec vendor/bin/typo3 extension:setup
ddev exec vendor/bin/typo3 database:updateschema
ddev exec vendor/bin/typo3 cache:flush
```

Durch `database:updateschema` werden die Tabellen `tx_impexpnl_import_log`, `tx_impexpnl_lock` und `tx_impexpnl_uid_map` angelegt. `tx_impexpnl_uid_map` hält das Herkunfts-Mapping (Quell-Record → Ziel-Record) zur Erkennung bereits importierter Records bei wiederholten Imports – Core-Tabellen (`pages`/`tt_content`) bleiben dabei unangetastet. Die Tabelle `tx_impexpnl_lock` realisiert den cluster-weiten Import-Lock. (Hinweis: Frühere Versionen nutzten ein Feld `tx_impexpnl_remote_uid` auf `pages`/`tt_content`; `impexpnl:migrate-legacy-schema` überführt es in die neue Tabelle.)

Die Installation wird geprüft mit:

```bash
ddev exec vendor/bin/typo3 list impexpnl
```

Es werden folgende Befehle ausgegeben: `impexpnl:export`, `impexpnl:import`, `impexpnl:undo`, `impexpnl:status`, `impexpnl:list`, `impexpnl:check`, `impexpnl:validate-config`, `impexpnl:unlock` und `impexpnl:migrate-legacy-schema`.

Auf Systemen ohne DDEV wird das Präfix `ddev exec` weggelassen.

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
| `--jsonl` | Zeilenweise geschriebenes JSONL-Format (ein JSON-Objekt pro Zeile) — kompakt und speicherschonend beim Export (kein einzelnes `json_encode` über den gesamten Block). Die Zieldatei sollte auf `.jsonl` enden; der Import erkennt das Format automatisch. Das Standard-JSON-Format bleibt unverändert nutzbar. (Hinweis: Der Import parst beide Formate aktuell vollständig in den Speicher — siehe Abschnitt „Chunked Verarbeitung".) |

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
| `--conflict=X` | Konflikt-Strategie für den Delta-Import (siehe unten). |
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

### Workspace-Import

Der Import in einen Workspace (z.B. `--target-workspace=1`) nutzt den regulären TYPO3-Workspace-Freigabeprozess (im GSB 11 der Redaktions-Workflow). Die importierten Inhalte erscheinen nicht sofort auf der Live-Website, sondern werden in einem Entwurfs-Workspace abgelegt. Die Veröffentlichung erfolgt über die regulären TYPO3-Workspace-Funktionen.

### Chunked Verarbeitung

Bei großen Seitenbäumen (mehrere tausend Records) werden die Daten automatisch in Batches à 500 Records verarbeitet. Seiten werden vor den Inhalten verarbeitet, damit die PID-Zuordnung beim Import der Inhalte bereits aufgelöst ist. Eine Fortschrittsanzeige gibt Rückmeldung über den Verarbeitungsstand.

Die Prüfsummenberechnung erfolgt inkrementell pro Record. Beim **Export** schreibt das JSONL-Format (`--jsonl`) zeilenweise und vermeidet so ein einzelnes, sehr großes `json_encode` über den gesamten Datenblock.

Beim **Import** werden derzeit beide Formate (JSON wie JSONL) zunächst vollständig in den Speicher geparst; erst die anschließende DataHandler-Verarbeitung läuft gechunkt (Batches à 500 Records). JSONL ist hier kompakter und zeilenweise lesbar, senkt den Spitzenbedarf des Datei-Parsens aber nur begrenzt. Ein **vollständig streamender Import** (konstanter Speicher unabhängig von der Dateigröße, für Bäume deutlich über 50.000 Records) ist als künftige Ausbaustufe vorgesehen — siehe [ROADMAP.md](ROADMAP.md).

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

Vor dem Löschen zeigt `undo` eine Vorschau (betroffene Anzahl, Quelldatei) und warnt, falls importierte Records nach dem Import lokal bearbeitet wurden – deren Änderungen gingen sonst unbemerkt verloren. Ohne `--force` wird interaktiv bestätigt; `--dry-run` zeigt nur die Vorschau.

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

Wird eine signierte Datei auf einem System ohne (passenden) Schlüssel importiert, wird der Import abgewiesen.

**Cluster-weiter Concurrency-Lock.** Während eines Imports wird ein Lock in der Datenbanktabelle `tx_impexpnl_lock` gesetzt (wirkt über alle Pods/Knoten hinweg) und zusätzlich ein lokaler Datei-Lock. Ein paralleler Import-Versuch wird abgewiesen. Lang laufende Importe halten den Lock per Heartbeat frisch; bei einem Crash oder fataler Fehler wird der Lock über einen Shutdown-Handler bzw. nach einem konfigurierbaren Timeout automatisch freigegeben (Standard: 3600 s, einstellbar über `import.lock_stale_seconds` in der `imp_exp_nl.yaml`). Der Dry-Run setzt keinen Lock.

**Abbruchsicheres Rollback-Protokoll.** Bricht ein Import nach dem Anlegen erster Records ab (Fehler, Timeout), wird automatisch ein Notfall-Protokoll mit der bis dahin angelegten UID-Zuordnung geschrieben. Die Teil-Daten lassen sich damit per `impexpnl:undo <Import-ID>` vollständig entfernen – es entstehen keine nicht-rückrollbaren Geisterdaten.

**Pfad-Begrenzung.** Sowohl der Export-Zielpfad als auch die Import-Quelldatei müssen innerhalb des Projektverzeichnisses liegen. Profilnamen werden gegen Path-Traversal abgesichert.

**CSV-Injection-Schutz.** Beim CSV-Export werden Werte mit führenden Formel-Triggern (`= + - @`) entschärft, sodass sie in Tabellenkalkulationen nicht als Formel ausgeführt werden.

**Konflikterkennung.** Im Delta-Modus werden Records mit lokal neuerem Zeitstempel als Konflikte erkannt. Die Behandlung ist über die Option `--conflict` konfigurierbar.

**DataHandler-Fehlerprotokollierung.** Fehler des TYPO3 DataHandler werden einzeln über den PSR-3 Logger protokolliert.

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
| `var/log/typo3_impexpnl_<hash>.log` | Alle Robbi-Copy-Meldungen (eigener Logger-Channel) |
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

Der Namespace `Robbi\ImpExpNL` wird vom TYPO3-Logging-Framework automatisch in den Pfad `Robbi.ImpExpNL` übersetzt. In ELK/Graylog kann nach diesem Pfad gefiltert werden, um ausschließlich Robbi-Copy-Meldungen anzuzeigen.

---

## Systemprüfung

Der Befehl `impexpnl:check` prüft ob alle Voraussetzungen für den Betrieb erfüllt sind. Die Prüfung ist vor dem ersten Einsatz auf einem neuen System und nach einem TYPO3-Update vorgesehen.

```bash
ddev exec vendor/bin/typo3 impexpnl:check
```

Geprüft werden:

- **Datenbankschema:** Existenz der Tabellen `tx_impexpnl_import_log`, `tx_impexpnl_lock` und `tx_impexpnl_uid_map`.
- **Dateisystem:** Schreibrechte auf `var/` und `var/log/`. Existenz des Profil-Verzeichnisses `var/impexpnl_profiles/`.
- **YAML-Konfiguration:** Syntaxprüfung der `imp_exp_nl.yaml`. Auflistung der registrierten Tabellen und Link-Rewrite-Felder.
- **Extension-Scan:** Erkennung aller geladenen Extensions, die eine eigene `Configuration/ImpExpNL.yaml` bereitstellen.

Bei Fehlern gibt der Befehl Exit-Code 1 zurück. Bei Warnungen ohne Fehler wird Exit-Code 0 zurückgegeben. Das ermöglicht die Einbindung in Deployment-Skripte:

```bash
vendor/bin/typo3 impexpnl:check || echo "ImpExpNL: Systemprüfung fehlgeschlagen"
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
imp_exp_nl/
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
├── imp_exp_nl.yaml
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
