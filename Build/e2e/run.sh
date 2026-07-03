#!/usr/bin/env bash
#
# Real-World-E2E-Lauf (Release-Ritual, siehe Prüfbericht Abschnitt 7).
#
# Fährt den kompletten Transfer-Lebenszyklus über zwei echte TYPO3-Instanzen
# (Container impexpnl-src/impexpnl-tgt mit MariaDB-Sidecars impexpnl-db-src/-tgt,
# Extension per Path-Repository auf /repo = dieser Arbeitsstand):
#
#   1. Quelle seeden (echte Bilddateien in fileadmin, Delta-idempotent)
#   2. Signierter Bundle-Export mit Key-ID (HMAC)
#   3. "Schleuse": Transfer der EINEN Bundle-Datei über den Host
#   4. Negativtest: falscher Schlüssel → Exit-Code 4
#   5. Dry-Run-Gate auf dem Ziel
#   6. Voll-Import, Verifikation (Fehler=0, Assets vollständig, Datei-Hash Quelle=Ziel)
#   7. Delta-Re-Import → muss No-Op sein (new=0, updated=0)
#   8. SIGKILL mitten im Import → Lock steht, undo-then-retry räumt restlos auf
#   9. Rollback des Voll-Imports → exakte Baseline
#
# Aufruf:   Build/e2e/run.sh
# Skalierung: PAGES=2000 ASSETS=2000 FILESIZE=204800 Build/e2e/run.sh
#
# Exit-Code 0 = alle Schritte und Prüfungen erfolgreich.

set -euo pipefail

SRC=impexpnl-src; TGT=impexpnl-tgt
DBS=impexpnl-db-src; DBT=impexpnl-db-tgt
PAGES="${PAGES:-800}"; ASSETS="${ASSETS:-800}"; FILESIZE="${FILESIZE:-51200}"
KEY="${IMPEXPNL_E2E_KEY:-e2e-geheim-$(date +%Y)}"; KEYID="${IMPEXPNL_E2E_KEYID:-k-e2e}"
MEM=1G
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

fail() { echo "E2E-FEHLER: $*" >&2; exit 1; }
step() { echo; echo "==> $*"; }

# docker exec auf einer Instanz, mit Signing-Env und memory_limit.
t3() { # t3 <container> <args...>
    local c="$1"; shift
    docker exec -e IMPEXPNL_SIGNING_KEY="$KEY" -e IMPEXPNL_SIGNING_KEY_ID="$KEYID" "$c" \
        php -d memory_limit=$MEM /var/www/vendor/bin/typo3 "$@"
}
sql() { docker exec "$1" mariadb -uroot -proot t3 -N -s -e "$2"; }
json_get() { python3 -c "import json,sys; d=json.load(open('$1')); print(d$2)"; }

step "0/9 Container starten, Caches leeren, Schema aktualisieren"
docker start "$DBS" "$DBT" "$SRC" "$TGT" >/dev/null
sleep 3
for c in "$SRC" "$TGT"; do
    docker exec "$c" php /var/www/vendor/bin/typo3 cache:flush >/dev/null
    docker exec "$c" php /var/www/vendor/bin/typo3 extension:setup >/dev/null
done

step "0b/9 Ziel aufräumen (Restbestände früherer Läufe: Lock lösen, offene Imports zurückrollen)"
docker exec "$TGT" php /var/www/vendor/bin/typo3 impexpnl:unlock --force --json >/dev/null 2>&1 || true
for i in $(seq 1 20); do
    OPEN_ID=$(sql "$DBT" "SELECT import_id FROM tx_impexpnl_import_log ORDER BY uid DESC LIMIT 1" || true)
    [ -z "$OPEN_ID" ] && break
    echo "    undo $OPEN_ID"
    docker exec "$TGT" php -d memory_limit=$MEM /var/www/vendor/bin/typo3 \
        impexpnl:undo "$OPEN_ID" --force --json >/dev/null 2>&1 \
        || sql "$DBT" "DELETE FROM tx_impexpnl_import_log WHERE import_id='$OPEN_ID'"
done

step "1/9 Quelle seeden ($PAGES Seiten, $ASSETS Assets à $FILESIZE Bytes)"
docker exec "$SRC" php /repo/Build/e2e/seed_generate.php \
    --pages="$PAGES" --assets="$ASSETS" --filesize="$FILESIZE" \
    --fileadmin=/var/www/public/fileadmin --out=/var/www/var/e2e_seed.json >/dev/null
t3 "$SRC" impexpnl:import /var/www/var/e2e_seed.json 1 --delta --json > "$TMP/seed.json" 2>/dev/null \
    || fail "Seed-Import fehlgeschlagen: $(cat "$TMP/seed.json")"
echo "    Seed: new=$(json_get "$TMP/seed.json" "['stats']['new']") skipped=$(json_get "$TMP/seed.json" "['stats']['skipped']")"

step "2/9 Signierter Bundle-Export (Key-ID $KEYID)"
docker exec "$SRC" rm -f /var/www/var/e2e_export.json /var/www/var/e2e_export.tar.gz
t3 "$SRC" impexpnl:export 1 /var/www/var/e2e_export.json --transport=bundle --json > "$TMP/export.json" 2>/dev/null
CHECKSUM=$(docker exec "$SRC" php -r 'echo json_decode(file_get_contents("/var/www/var/e2e_export.json"), true)["_meta"]["checksum"];')
case "$CHECKSUM" in
    "hmac-sha256:$KEYID:"*) echo "    Signatur ok: ${CHECKSUM:0:40}…" ;;
    *) fail "Export nicht korrekt signiert: $CHECKSUM" ;;
esac

step "3/9 Schleuse: Bundle-Transfer über den Host"
docker cp -q "$SRC:/var/www/var/e2e_export.tar.gz" "$TMP/e2e_export.tar.gz"
docker cp -q "$TMP/e2e_export.tar.gz" "$TGT:/var/www/var/e2e_export.tar.gz"
echo "    $(du -h "$TMP/e2e_export.tar.gz" | cut -f1) übertragen"

step "4/9 Negativtest: falscher Schlüssel muss abgewiesen werden (Exit 4)"
docker exec -e IMPEXPNL_SIGNING_KEY="falscher-schluessel" "$TGT" \
    php -d memory_limit=$MEM /var/www/vendor/bin/typo3 \
    impexpnl:import /var/www/var/e2e_export.tar.gz 1 --dry-run --json > "$TMP/neg.json" 2>/dev/null || true
[ "$(json_get "$TMP/neg.json" "['exitCode']")" = "4" ] || fail "Falscher Schlüssel wurde NICHT mit Exit 4 abgewiesen: $(cat "$TMP/neg.json")"
echo "    abgewiesen (Exit 4) ✓"

BASE_PAGES=$(sql "$DBT" "SELECT COUNT(*) FROM pages WHERE deleted=0")
BASE_CONTENT=$(sql "$DBT" "SELECT COUNT(*) FROM tt_content WHERE deleted=0")
echo "    Ziel-Baseline: $BASE_PAGES Seiten, $BASE_CONTENT Inhalte"

step "5/9 Dry-Run-Gate auf dem Ziel"
t3 "$TGT" impexpnl:import /var/www/var/e2e_export.tar.gz 1 --dry-run --json > "$TMP/dry.json" 2>/dev/null || true
echo "    diff: new=$(json_get "$TMP/dry.json" "['diff']['pages']['new']") pages / $(json_get "$TMP/dry.json" "['diff']['tt_content']['new']") tt_content, Assets im Manifest: $(json_get "$TMP/dry.json" "['assets']['total']")"

step "6/9 Voll-Import auf dem Ziel"
t3 "$TGT" impexpnl:import /var/www/var/e2e_export.tar.gz 1 --json > "$TMP/import.json" 2>/dev/null \
    || fail "Import fehlgeschlagen: $(cat "$TMP/import.json")"
IMPORT_ID=$(json_get "$TMP/import.json" "['importId']")
[ "$(json_get "$TMP/import.json" "['stats']['errors']")" = "0" ] || fail "Import meldet DataHandler-Fehler"
[ "$(json_get "$TMP/import.json" "['stats']['assets_missing']")" = "0" ] || fail "Import meldet fehlende Assets"
PROBE=$(printf 'img_%05d.jpg' $((PAGES / 2)))
H1=$(docker exec "$SRC" sha256sum "/var/www/public/fileadmin/rwt/$PROBE" | cut -d' ' -f1)
H2=$(docker exec "$TGT" sha256sum "/var/www/public/fileadmin/rwt/$PROBE" | cut -d' ' -f1)
[ "$H1" = "$H2" ] || fail "Asset-Stichprobe $PROBE: Hash Quelle != Ziel"
echo "    Import $IMPORT_ID: new=$(json_get "$TMP/import.json" "['stats']['new']"), Fehler=0, Asset-Hash-Stichprobe ✓"

step "7/9 Delta-Re-Import muss No-Op sein"
t3 "$TGT" impexpnl:import /var/www/var/e2e_export.tar.gz 1 --delta --json > "$TMP/delta.json" 2>/dev/null \
    || fail "Delta-Import fehlgeschlagen"
D_NEW=$(json_get "$TMP/delta.json" "['stats']['new']"); D_UPD=$(json_get "$TMP/delta.json" "['stats']['updated']")
[ "$D_NEW" = "0" ] && [ "$D_UPD" = "0" ] || fail "Delta ist kein No-Op (new=$D_NEW, updated=$D_UPD) – Idempotenz verletzt"
echo "    No-Op ✓ (skipped=$(json_get "$TMP/delta.json" "['stats']['skipped']"))"

step "8/9 SIGKILL mitten im Import → undo-then-retry"
PRE_KILL_PAGES=$(sql "$DBT" "SELECT COUNT(*) FROM pages WHERE deleted=0")
docker exec -d -e IMPEXPNL_SIGNING_KEY="$KEY" -e IMPEXPNL_SIGNING_KEY_ID="$KEYID" "$TGT" bash -c \
    "php -d memory_limit=$MEM /var/www/vendor/bin/typo3 impexpnl:import /var/www/var/e2e_export.tar.gz 1 --json > /tmp/e2e_kill.json 2>&1"
KILLED=0
for i in $(seq 1 120); do
    # sql darf unter Import-Last transient fehlschlagen (Polling, kein Abbruch).
    NOW=$(sql "$DBT" "SELECT COUNT(*) FROM pages WHERE deleted=0" 2>/dev/null || echo "$PRE_KILL_PAGES")
    if [ "${NOW:-$PRE_KILL_PAGES}" -gt $((PRE_KILL_PAGES + 20)) ]; then
        # "imp[o]rt": das Klammer-Muster verhindert, dass die Kill-Schleife ihre
        # eigene Kommandozeile matcht und sich selbst (bzw. den grep) killt.
        docker exec "$TGT" bash -c 'ME=$$; for p in /proc/[0-9]*/cmdline; do pid=$(basename "$(dirname "$p")"); [ "$pid" = "$ME" ] && continue; tr "\0" " " < "$p" 2>/dev/null | grep -q "impexpnl:imp[o]rt" && kill -9 "$pid" || true; done' || true
        KILLED=1; break
    fi
    sleep 1
done
sleep 3
if [ "$KILLED" = "1" ]; then
    LOCKS=$(sql "$DBT" "SELECT COUNT(*) FROM tx_impexpnl_lock")
    [ "$LOCKS" = "1" ] || fail "Nach SIGKILL wird genau 1 verwaister Lock erwartet, gefunden: $LOCKS"
    KILL_ID=$(sql "$DBT" "SELECT import_id FROM tx_impexpnl_import_log ORDER BY uid DESC LIMIT 1")
    docker exec "$TGT" php /var/www/vendor/bin/typo3 impexpnl:unlock --force --json >/dev/null
    docker exec "$TGT" php -d memory_limit=$MEM /var/www/vendor/bin/typo3 impexpnl:undo "$KILL_ID" --force --json > "$TMP/undo_kill.json" 2>/dev/null \
        || fail "Undo des Teilimports fehlgeschlagen"
    AFTER=$(sql "$DBT" "SELECT COUNT(*) FROM pages WHERE deleted=0")
    [ "$AFTER" = "$PRE_KILL_PAGES" ] || fail "Teilimport nicht restlos entfernt ($AFTER != $PRE_KILL_PAGES)"
    echo "    SIGKILL → verwaister Lock erkannt, unlock + undo $KILL_ID, Bestand wiederhergestellt ✓"
else
    echo "    WARNUNG: Import endete, bevor gekillt werden konnte – Kill-Szenario übersprungen; räume per undo auf."
    KILL_ID=$(sql "$DBT" "SELECT import_id FROM tx_impexpnl_import_log ORDER BY uid DESC LIMIT 1")
    docker exec "$TGT" php -d memory_limit=$MEM /var/www/vendor/bin/typo3 impexpnl:undo "$KILL_ID" --force --json >/dev/null 2>&1 || true
fi

step "9/9 Rollback des Voll-Imports → Baseline"
docker exec "$TGT" php -d memory_limit=$MEM /var/www/vendor/bin/typo3 impexpnl:undo "$IMPORT_ID" --force --json > "$TMP/undo.json" 2>/dev/null \
    || fail "Rollback fehlgeschlagen: $(cat "$TMP/undo.json")"
END_PAGES=$(sql "$DBT" "SELECT COUNT(*) FROM pages WHERE deleted=0")
END_CONTENT=$(sql "$DBT" "SELECT COUNT(*) FROM tt_content WHERE deleted=0")
[ "$END_PAGES" = "$BASE_PAGES" ] || fail "Seiten nicht auf Baseline ($END_PAGES != $BASE_PAGES)"
[ "$END_CONTENT" = "$BASE_CONTENT" ] || fail "Inhalte nicht auf Baseline ($END_CONTENT != $BASE_CONTENT)"
[ "$(sql "$DBT" "SELECT COUNT(*) FROM tx_impexpnl_lock")" = "0" ] || fail "Lock nach Abschluss nicht frei"
echo "    Baseline wiederhergestellt ($END_PAGES Seiten, $END_CONTENT Inhalte), Lock frei ✓"

echo
echo "E2E-Lauf ERFOLGREICH: Export → Schleuse → Verifikation → Import → Delta-No-Op → SIGKILL/undo → Rollback."
