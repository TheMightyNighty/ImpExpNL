# Performance-Baseline

Diese Baseline dient als **Regressionsschutz**, nicht als Marketing-Zahl. Sie hält fest,
in welcher Größenordnung sich Export, Import und Rollback bewegen, damit eine spätere
Änderung, die das Verhalten deutlich verschlechtert, auffällt.

## Messung reproduzieren

Gemessen wird über den Functional-Test `PerformanceBaselineTest`. Er importiert einen
generierten Baum (Querverweise für Link-Rewriting), exportiert ihn anschließend wieder
und rollt ihn zurück – je mit Zeit- und Speicher-Peak-Messung.

```bash
# Standard (CI): kleine Klasse
vendor/bin/phpunit -c phpunit.functional.xml --filter PerformanceBaselineTest

# Größere Klassen / Format (lokal)
IMPEXPNL_PERF_SIZE=medium  vendor/bin/phpunit -c phpunit.functional.xml --filter PerformanceBaselineTest
IMPEXPNL_PERF_SIZE=large   IMPEXPNL_PERF_FORMAT=jsonl vendor/bin/phpunit -c phpunit.functional.xml --filter PerformanceBaselineTest
```

`IMPEXPNL_PERF_SIZE` = `small` (Default) | `medium` | `large`,
`IMPEXPNL_PERF_FORMAT` = `json` (Default) | `jsonl`.

Größenklassen:

| Klasse | Seiten | Inhalte (je Seite) |
|--------|-------:|-------------------:|
| small  |    100 |     500 (5) |
| medium |  1.000 |   5.000 (5) |
| large  | 10.000 |  20.000 (2) |

## Referenzwerte

> Indikativ, **Einzellauf** auf einer Entwicklermaschine (Docker, PHP 8.4, SQLite).
> Absolutwerte schwanken je nach Hardware/Last; entscheidend ist die **Größenordnung**
> und das Verhältnis der Operationen zueinander. Pro Release neu messen und hier
> aktualisieren (Umgebung dazuschreiben).

Stand: 2026-06 (TYPO3 v14, PHP 8.4, SQLite, In-Memory-Import)

| Klasse | Import (JSON) | Import (JSONL) | Export | Rollback | Peak-Speicher |
|--------|--------------:|---------------:|-------:|---------:|--------------:|
| small  |       ~2,9 s  |       ~2,9 s   | ~0,03 s |  ~2,6 s |       ~115 MB |
| medium |      ~30 s    |      ~36 s     |  ~0,3 s |  ~29 s  |       ~203 MB |
| large  |     ~166 s    |        –       |  ~1,8 s | ~170 s  |       ~687 MB |

## Beobachtungen

- **Rollback ≈ Import.** Der Rollback bündelt seine DataHandler-Deletes seit dieser
  Baseline in einer DB-Transaktion. Davor lief jede Anweisung im Autocommit; der Rollback
  der medium-Klasse dauerte **~135 s statt ~29 s** (small: ~13 s → ~2,6 s). Geht dieses
  Verhältnis (~1:1 zum Import) wieder auseinander, ist das ein Regressionssignal.
- **Export ist günstig** (lesend, eine Query-Kaskade) und spielt zeitlich keine Rolle.
- **Speicher wächst linear mit der Recordzahl** (In-Memory-Parsing/Datamap). Die
  large-Klasse benötigt mehr als die im Test gesetzten 512 MB (Peak ~687 MB). Für sehr
  große Bäume ist der *streamende Import* (Roadmap → „Später") der nächste Hebel.
- **JSON vs. JSONL** unterscheiden sich beim Import kaum; JSONL ist primär beim **Export**
  speicherschonend (zeilenweises Schreiben statt eines großen `json_encode`).
