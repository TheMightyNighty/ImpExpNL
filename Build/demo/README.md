# ImpExpNL – DDEV-Demo (TYPO3 v14)

Ein lauffähiges TYPO3-v14-Projekt zum Ausprobieren von ImpExpNL – **vanilla TYPO3, kein GSB**.
Die Extension wird per Path-Repository direkt aus diesem Repo eingebunden (kein TER/Packagist nötig).

> Voraussetzung: [DDEV](https://ddev.com) + Docker. In diesem Verzeichnis (`Build/demo/`) arbeiten.

## 1. Setup

```bash
cd Build/demo
ddev start
ddev composer install

# TYPO3 nicht-interaktiv einrichten (DB-Zugang kommt von DDEV: host=db, name/user/pass=db)
ddev typo3 setup --no-interaction \
  --server-type=other \
  --driver=mysqli --host=db --port=3306 --dbname=db --username=db --password=db \
  --admin-username=admin --admin-user-password='Password.1!' --admin-email=admin@example.com \
  --project-name='ImpExpNL Demo' \
  --create-site='https://impexpnl-demo.ddev.site/'

# Schema der Extension anlegen (tx_impexpnl_uid_map, _import_log, _lock)
ddev typo3 extension:setup
```

Backend: `https://impexpnl-demo.ddev.site/typo3` — Login `admin` / `Password.1!`

## 2. Demo-Inhalte (optional)

Schnell ein paar Seiten/Inhalte erzeugen – z. B. mit dem TYPO3-Styleguide:

```bash
ddev composer require --dev friendsoftypo3/styleguide
ddev typo3 styleguide:generate
```

Alternativ einfach im Backend ein paar Seiten anlegen.

## 3. Round-Trip ausprobieren

```bash
# Status / Schema prüfen
ddev typo3 impexpnl:check

# Seitenbaum ab PID 1 exportieren
ddev typo3 impexpnl:export 1 var/demo-export.json --json

# In einen neuen Teilbaum importieren (PID 0 = neue Wurzel)
ddev typo3 impexpnl:import var/demo-export.json 0

# Idempotenz zeigen: erneuter Delta-Import legt nichts doppelt an
ddev typo3 impexpnl:import var/demo-export.json 0 --delta

# Letzten Import rückgängig machen
ddev typo3 impexpnl:undo
```

## 4. Aufräumen

```bash
ddev delete -O      # Projekt + DB entfernen (Dateien bleiben)
```

## Hinweise
- Die `typo3 setup`-Flags können je nach TYPO3-v14-Patchlevel leicht abweichen;
  bei Bedarf `ddev typo3 setup --help` aufrufen.
- `composer.lock`, `vendor/`, `public/`, `config/`, `var/` sind bewusst nicht eingecheckt.
