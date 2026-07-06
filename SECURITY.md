# Sicherheitsrichtlinie

## Unterstützte Versionen

| Version | TYPO3 | Status |
|---------|-------|--------|
| 2.x (`main`) | v14 LTS | aktiv (Features + Sicherheits-Fixes) |
| 1.x (`13.x`) | v13.4 LTS / GSB 11 | Wartung (Sicherheits-Fixes) |

Ältere Versionen erhalten keine Fixes — bitte auf die jeweils aktuelle Linie aktualisieren.

## Sicherheitslücke melden

**Bitte keine Sicherheitslücken über öffentliche GitHub-Issues melden.**

Bevorzugter Weg: **GitHub Private Vulnerability Reporting** —
[Security Advisory erstellen](https://github.com/TheMightyNighty/ImpExpNL/security/advisories/new).
Alternativ per E-Mail an den Maintainer (siehe `composer.json`).

Eine Meldung sollte enthalten:

- betroffene Version(en) und TYPO3-/PHP-Umgebung
- Beschreibung der Lücke und der möglichen Auswirkung
- Schritte zur Reproduktion (Konfiguration, Beispiel-Importdatei, CLI-Aufrufe)
- falls vorhanden: Vorschlag zur Behebung

## Reaktion

- Eingangsbestätigung angestrebt innerhalb von **7 Tagen**.
- Bestätigte Lücken werden priorisiert behoben (beide unterstützten Linien) und über
  GitHub-Releases samt CHANGELOG-Abschnitt „Sicherheit" veröffentlicht; Details werden
  erst nach Verfügbarkeit eines Fixes offengelegt (koordinierte Offenlegung).

## Hinweise für Betreiber

Die sicherheitsrelevanten Betriebsfunktionen (HMAC-Signaturpflicht, Schlüssel-Rotation,
Wirkbereichs-Begrenzung, Transfer über ein Sicherheitsgefälle) sind in der
[README](README.md#security-features) dokumentiert. Interne Prüfungen und deren
Ergebnisse: [Prüfbericht.md](Prüfbericht.md).
