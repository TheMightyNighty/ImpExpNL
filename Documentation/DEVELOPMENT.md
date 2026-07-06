# Entwicklung & Test-Betrieb

Dieses Dokument beschreibt, wie Änderungen an ImpExpNL abgesichert werden und wie
ein Release entsteht. Es beantwortet die Frage: *„Ich habe eine Änderung gemacht —
wie stelle ich sicher, dass sie nichts kaputt macht, und wie kommt sie in ein
Release?"* Feature-Dokumentation steht in der [README](../README.md), das
Sicherheitskonzept in [SECURITY.md](../SECURITY.md) und
[Prüfbericht.md](../Prüfbericht.md), Performance-Messungen in
[PERFORMANCE.md](PERFORMANCE.md).

---

## Test-Pyramide: welche Stufe wann

Die Test-Suite ist in vier Stufen organisiert. Jede Stufe macht Fehlerklassen
sichtbar, die die darunterliegende nicht erkennen kann — deshalb ist die Frage
nicht „welche Tests laufen lasse ich", sondern „welche Fehlerklasse kann meine
Änderung auslösen".

| Stufe | Wann | Dauer | Aufruf |
|---|---|---|---|
| 1. Lint + CGL + PHPStan + Unit | bei jeder Änderung, vor jedem Commit | Sekunden | `composer ci` |
| 2. Functional (SQLite) | bei Änderungen an Import-/Export-/Registry-Logik | ~1 Minute | `composer ci:test:functional` |
| 3. Functional (MariaDB) | vor jedem Release; bei DB-nahen Änderungen | Minuten | `Build/test-functional-mariadb.sh` |
| 4. Real-World-E2E (2 Instanzen) | Release-Ritual; bei Transport-/Bundle-/Asset-Änderungen | länger | `Build/e2e/run.sh` |

**Warum MariaDB eine eigene Stufe ist:** Die Standard-CI läuft gegen SQLite.
Einige Fehlerklassen treten ausschließlich auf MySQL/MariaDB auf — die
affected-rows-Semantik von `UPDATE` (0 bei unverändertem Wert, relevant für den
Lock-Heartbeat), implizite Commits durch `TRUNCATE` (Hintergrund des
`RootlineCacheGuard`) und abweichende Wert-Repräsentationen. Details im
Prüfbericht, Abschnitt 7. Änderungen an `ImportLockService`, Transaktions- oder
Checkpoint-Logik gehören daher immer auch durch Stufe 3.

**Warum der E2E eine eigene Stufe ist:** Nur er beweist den kompletten
Transfer-Lebenszyklus unter Realbedingungen — zwei echte TYPO3-Instanzen,
echte Dateien in `fileadmin`, signierter Bundle-Export mit Key-ID, Schleusen-
Transfer, Negativtest mit falschem Schlüssel (Exit-Code 4), Dry-Run-Gate,
Voll-Import mit Verifikation, Delta-No-Op, SIGKILL mitten im Import mit
undo-then-retry sowie Rollback auf die exakte Baseline. Funktionaltests stellen
einzelne dieser Eigenschaften sicher, aber nicht ihr Zusammenspiel.

Die GitHub-CI (`.github/workflows/ci.yml`) fährt Stufe 1 und 2 automatisch über
die PHP-Matrix 8.2/8.3/8.4 (Functional gegen SQLite); Rector läuft dort advisory.
Stufe 3 und 4 sind bewusst lokale Läufe und Release-Pflicht.

### Zuordnung nach Art der Änderung

| Änderung betrifft … | Pflicht | Empfohlen |
|---|---|---|
| Doku, Kommentare, CLI-Texte | Stufe 1 | — |
| Services/Domain (Logik) | Stufe 1 + 2 | — |
| Locks, Transaktionen, Checkpoints, DataHandler-Batches | Stufe 1–3 | Stufe 4 |
| Transport, Bundle, Assets/FAL, Signatur/Integrität | Stufe 1–3 | Stufe 4 (Pflicht vor Release) |
| `ext_tables.sql` (Schema) | Stufe 1–3 | Stufe 4; CHANGELOG-Hinweis (siehe unten) |

---

## Container-Testumgebung

Die Stufen 2–4 laufen in Docker-Containern. Es gibt keinen Compose-Stack; die
Container werden einzeln gepflegt und von den `Build/`-Skripten vorausgesetzt.

### Runner (Stufe 2 und 3): `impexpnl-runner`

- Image **`impexpnl-phprunner:8.4`** — gebaut aus `php:8.4-cli` plus
  `libicu-dev libzip-dev libsqlite3-dev unzip git`, PHP-Extensions
  `intl mysqli pdo_mysql pdo_sqlite zip opcache`, Composer aus dem
  `composer:2`-Image kopiert.
- Das Repository liegt unter **`/app` als Kopie, nicht als Mount**. Änderungen
  auf dem Host erscheinen dort **nicht** automatisch — vor jedem Testlauf
  synchronisieren:

  ```bash
  # Einzelne Dateien
  docker cp Classes/Service/Foo.php impexpnl-runner:/app/Classes/Service/Foo.php

  # Ganzer Baum (ohne vendor/.git)
  tar --exclude=./vendor --exclude=./.git -cf - . \
      | docker exec -i impexpnl-runner tar -xf - -C /app
  ```

  Das vergessene Sync ist die häufigste Fehlerquelle („Test grün, obwohl die
  Änderung kaputt ist" — der Container testete den alten Stand).
- `vendor/` existiert **nur im Container** (`composer install` dort); das
  Host-Repo hat kein `vendor/`.
- DB-Umgebung ist im Container vorgesetzt: `typo3DatabaseDriver=mysqli`,
  Host `impexpnl-mariadb`, Datenbank `typo3_ci`, root/root. Die SQLite-Suite
  braucht keine DB.
- Testläufe:

  ```bash
  docker exec impexpnl-runner sh -c 'cd /app && vendor/bin/phpunit -c phpunit.unit.xml'
  docker exec impexpnl-runner sh -c 'cd /app && vendor/bin/phpunit -c phpunit.functional.xml'
  docker exec impexpnl-runner sh -c 'cd /app && vendor/bin/phpstan analyse --no-progress --memory-limit=1G'
  ```

### MariaDB-Pflichtlauf (Stufe 3)

`Build/test-functional-mariadb.sh` startet bei Bedarf den Sidecar
`impexpnl-ci-mariadb` (Image `mariadb:11.4`, Datenbank `typo3_ci`), verbindet
ihn mit dem Runner und fährt die Functional-Suite gegen echtes MariaDB.
PHPUnit-Argumente werden durchgereicht:

```bash
Build/test-functional-mariadb.sh                       # ganze Suite
Build/test-functional-mariadb.sh --filter ImportLockTest
```

### Real-World-Instanzen (Stufe 4)

`Build/e2e/run.sh` erwartet zwei TYPO3-v14-Instanzen
**`impexpnl-src`/`impexpnl-tgt`** mit MariaDB-Sidecars
**`impexpnl-db-src`/`impexpnl-db-tgt`**; die Extension ist dort per
Path-Repository auf `/repo` (= dieser Arbeitsstand) eingebunden. Skalierung über
Umgebungsvariablen:

```bash
Build/e2e/run.sh                                       # Standard: 800 Seiten / 800 Assets
PAGES=2000 ASSETS=2000 FILESIZE=204800 Build/e2e/run.sh
```

Exit-Code 0 bedeutet: alle neun Schritte und Prüfungen erfolgreich.

---

## Testdaten & Performance

- `Build/generate-testdata.php` erzeugt synthetische Exporte beliebiger Größe
  (JSON oder JSONL), ohne dass eine Quell-Instanz nötig ist:

  ```bash
  php Build/generate-testdata.php --pages=5000 --content-per-page=8 --out=var/big.json
  php Build/generate-testdata.php --pages=20000 --content-per-page=5 --format=jsonl --out=var/big.jsonl
  ```

- Der Functional-Test `LargeTreeImportTest` nutzt denselben Generator und ist
  über `IMPEXPNL_PERF_PAGES`, `IMPEXPNL_PERF_CONTENT` und
  `IMPEXPNL_PERF_FORMAT=jsonl` skalierbar; er gibt Laufzeit und Speicher-Peak
  aus. Referenzwerte: [PERFORMANCE.md](PERFORMANCE.md).
- Die Dritt-Extension-Contract-Tests (`NewsProfileContractTest` u. a.) sind
  skip-guarded: fehlt die require-dev-Extension, werden sie still übersprungen.

---

## Release-Ritual

Reihenfolge für ein Release auf `main` (2.x / TYPO3 v14):

1. `composer ci` (Lint, CGL, PHPStan, Unit) — grün.
2. Functional-Suite im Runner (SQLite) — grün.
3. `Build/test-functional-mariadb.sh` — grün.
4. `Build/e2e/run.sh` — Exit-Code 0.
5. CHANGELOG: Abschnitt **„Unveröffentlicht"** in einen Versionsabschnitt
   `## X.Y.Z — Titel (Datum)` überführen. Immer angeben, ob ein
   **Schema-Update nötig** ist (neue Spalten/Tabellen in `ext_tables.sql`
   → Betreiber müssen das Database-Compare fahren).
6. Version anheben in `ext_emconf.php` **und** `composer.json`
   (`extra.typo3/cms.version`) — beide müssen übereinstimmen.
7. Commit, Tag `vX.Y.Z`, GitHub-Release mit den CHANGELOG-Highlights.
   Packagist zieht das Release automatisch über den GitHub-Hook.

Konventionen:

- **ROADMAP.md ist bewusst unversioniert** (gitignored) und wird nur lokal
  geführt; sie wird auch bei Releases nicht committet.
- Commit-Messages folgen Conventional Commits (`feat:`, `fix:`, `refactor:`,
  `docs:`, `chore(release):`); CHANGELOG-Sprache ist Deutsch.

---

## v13-Linie (`13.x`, 1.x) — Wartungsmodus

Die 1.x-Linie (TYPO3 v13.4) erhält **nur noch Sicherheits-Fixes**
(siehe [SECURITY.md](../SECURITY.md)) und läuft mit dem v13-Support aus.
Für den seltenen Fall eines Backports das Wichtigste:

- **Cherry-Picks funktionieren nicht.** Die Linien unterscheiden sich
  architektonisch: `main` persistiert das Quell→Ziel-Mapping in der Tabelle
  `tx_impexpnl_uid_map`, `13.x` in einer Spalte `tx_impexpnl_remote_uid` auf
  `pages`/`tt_content`. Backports werden manuell pro Datei übertragen.
- Nicht in 1.x vorhanden (bewusst): Bundle-Transport, Transfer-Profile,
  Sanitizing, Key-Rotation, Import-Checkpoints (SIGKILL-Teilimporte sind in 1.x
  nicht per `undo` rückrollbar — dokumentierte Einschränkung).
- Eigene Test-Container: Runner `impexpnl-runner13` (gleiches Sync-Muster wie
  der v14-Runner), MariaDB-Sidecar `impexpnl-ci-mariadb13`, Realwelt-Instanzen
  `impexpnl-src-13`/`impexpnl-tgt-13`. Das dort gemountete `/repo` ist **kein
  Git-Checkout**, sondern ein per rsync gepflegter Baum — immer den ganzen Baum
  syncen, nie selektiv mit `--delete`.
