#!/usr/bin/env bash
# Prüft docker-compose.prod.yml: die gehärtete GUI (Image, schreibgeschütztes Dateisystem, Healthcheck)
# gegen den laufenden Test-Stack. Vorher: docker compose up -d --build --wait

set -o pipefail
cd "$(dirname "$0")/.." || exit 1

P=(docker compose -f docker-compose.prod.yml -f tests/prod-ci.override.yml --env-file tests/prod-ci.env)
BASE=http://localhost:8090
JAR=$(mktemp -d)
FAILED=0
cleanup() { "${P[@]}" down --rmi all >/dev/null 2>&1; rm -rf "$JAR"; }
trap cleanup EXIT

ok()     { printf 'ok      %s\n' "$1"; }
bad()    { printf 'FEHLER  %s\n' "$1"; FAILED=$((FAILED + 1)); }
expect() { if grep -qE -- "$2" <<<"$3"; then ok "$1"; else bad "$1 – erwartet: $2"; fi; }
code()   { curl -s -o /dev/null -w '%{http_code}' "$@"; }

echo "== Prod-GUI starten"
if "${P[@]}" up -d --build --wait gui; then
    ok "Container gesund (Healthcheck)"
else
    bad "Container nicht gesund"
    "${P[@]}" logs gui
fi
cid=$("${P[@]}" ps -q gui)
expect "Dateisystem schreibgeschützt" '^true$' "$(docker inspect -f '{{.HostConfig.ReadonlyRootfs}}' "$cid")"
expect "Schreiben ins Code-Verzeichnis scheitert" 'Read-only file system' "$(docker exec "$cid" touch /var/www/html/x 2>&1)"
expect "Logs an Syslog" '^syslog$' "$(docker inspect -f '{{.HostConfig.LogConfig.Type}}' "$cid")"

echo "== Anmeldung"
t=$(curl -s -c "$JAR/s" "$BASE/?page=login" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"$//')
expect "Admin-Login" '^302$' "$(code -b "$JAR/s" -c "$JAR/s" "$BASE/?page=login" --data-urlencode "_csrf=$t" --data-urlencode user=admin --data-urlencode password=ci-prod)"
expect "Übersicht mit Verbindung zu ProxySQL" 'proxysql:6032' "$(curl -s -b "$JAR/s" "$BASE/?page=dashboard")"
expect "lib/ nicht direkt abrufbar" '^403$' "$(code "$BASE/lib/sync.php")"
expect "Cookie hinter HTTPS-Proxy als Secure" '[Ss]ecure' "$(curl -s -o /dev/null -D - -H 'X-Forwarded-Proto: https' "$BASE/?page=login" | grep -i '^set-cookie')"

echo
if [ "$FAILED" -gt 0 ]; then
    echo "$FAILED Prüfung(en) fehlgeschlagen"
    exit 1
fi
echo "Alle Prüfungen bestanden"
