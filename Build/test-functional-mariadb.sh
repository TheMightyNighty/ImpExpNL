#!/usr/bin/env bash
#
# Functional-Suite gegen echtes MariaDB ausführen (Release-Pflichtlauf).
#
# Hintergrund: Die Standard-CI läuft gegen SQLite. Einige Fehlerklassen treten
# ausschließlich auf MySQL/MariaDB auf (affected-rows-Semantik, implizite
# Commits durch TRUNCATE, Wert-Repräsentationen); siehe Prüfbericht Abschnitt 7.
# Dieser Lauf gehört deshalb vor jedes Release.
#
# Voraussetzungen: Docker; Runner-Container "impexpnl-runner"
# (impexpnl-phprunner:8.4, Repo-Kopie unter /app) und Image mariadb:11.4.
#
# Aufruf:  Build/test-functional-mariadb.sh [phpunit-Argumente]
# Beispiel: Build/test-functional-mariadb.sh --filter ImportLockTest

set -euo pipefail

RUNNER=impexpnl-runner13
DB=impexpnl-ci-mariadb13
NET=impexpnl-rwt13-net
REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"

echo "==> Netzwerk/Container vorbereiten"
docker network inspect "$NET" >/dev/null 2>&1 || docker network create "$NET"
docker start "$RUNNER" >/dev/null 2>&1 || { echo "FEHLER: Container $RUNNER fehlt."; exit 1; }
docker network connect "$NET" "$RUNNER" 2>/dev/null || true

if ! docker inspect "$DB" >/dev/null 2>&1; then
    docker run -d --name "$DB" --network "$NET" \
        -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=typo3_ci \
        mariadb:11.4 >/dev/null
else
    docker start "$DB" >/dev/null
fi

echo "==> Auf MariaDB warten"
for i in $(seq 1 60); do
    if docker exec "$DB" mariadb -uroot -proot -e 'SELECT 1' >/dev/null 2>&1; then
        break
    fi
    [ "$i" = 60 ] && { echo "FEHLER: MariaDB nicht bereit."; exit 1; }
    sleep 1
done

echo "==> Arbeitsstand in den Runner synchronisieren"
(cd "$REPO_DIR" && tar --exclude=./vendor --exclude=./.git -cf - .) \
    | docker exec -i "$RUNNER" tar -xf - -C /app

echo "==> Functional-Suite gegen MariaDB (mysqli, Host $DB)"
docker exec \
    -e typo3DatabaseDriver=mysqli \
    -e typo3DatabaseHost="$DB" \
    -e typo3DatabasePort=3306 \
    -e typo3DatabaseName=typo3_ci \
    -e typo3DatabaseUsername=root \
    -e typo3DatabasePassword=root \
    "$RUNNER" sh -c "cd /app && vendor/bin/phpunit -c phpunit.functional.xml $*"

echo "==> MariaDB-Functional-Lauf erfolgreich."
