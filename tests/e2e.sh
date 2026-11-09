#!/usr/bin/env bash
# End-to-End-Test gegen den Test-Stack (docker-compose.yml) – lokal und in GitHub Actions:
#
#   docker compose up -d --build --wait
#   tests/e2e.sh                      # GUI auf einem anderen Port: GUI_PORT=8091 tests/e2e.sh
#
# Der Test verändert den Demo-Zustand (Konten, Replikat, Cache-Regel). Danach neu aufsetzen:
#   docker compose down -v && docker compose up -d --wait

set -o pipefail
cd "$(dirname "$0")/.." || exit 1

BASE=${BASE_URL:-http://localhost:${GUI_PORT:-8080}}
JAR=$(mktemp -d)
trap 'rm -rf "$JAR"' EXIT
FAILED=0

ok()  { printf 'ok      %s\n' "$1"; }
bad() { printf 'FEHLER  %s\n' "$1"; FAILED=$((FAILED + 1)); }
# expect <Beschreibung> <Regex> <Text>
expect()    { if grep -qE -- "$2" <<<"$3"; then ok "$1"; else bad "$1 – erwartet: $2"; fi; }
expect_no() { if grep -qE -- "$2" <<<"$3"; then bad "$1 – unerwartet: $2"; else ok "$1"; fi; }
# wait_for <Beschreibung> <Sekunden> <Befehl …>
wait_for() {
    local desc=$1 secs=$2 i
    shift 2
    for ((i = 0; i < secs; i += 2)); do
        "$@" >/dev/null 2>&1 && { ok "$desc"; return 0; }
        sleep 2
    done
    bad "$desc – Zeitüberschreitung nach ${secs} s"
    return 1
}

admin_sql() { docker compose exec -T proxysql mysql -h127.0.0.1 -P6032 -uadmin -padmin -N -B -e "$1" 2>/dev/null; }
db_sql()    { docker compose exec -T mariadb mariadb -uroot -p"${MARIADB_ROOT_PASSWORD:-root-demo}" -N -B -e "$1" 2>/dev/null; }
# Anmeldung einer Anwendung über ProxySQL – gibt bei Erfolg "1" aus
app_login() { docker compose exec -T mariadb mariadb -hproxysql -P6033 --skip-ssl -u"$1" -p"$2" shop -N -B -e 'SELECT 1' 2>&1 | tail -1; }
sync_run()  { docker compose exec -T -e AUTO_SYNC="$1" gui php /var/www/html/bin/sync.php 2>&1; }

csrf()   { grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"$//'; }
code()   { curl -s -o /dev/null -w '%{http_code}' "$@"; }
get()    { curl -s -b "$JAR/$1" "$BASE/?page=$2"; }
status() { code -b "$JAR/$1" "$BASE/?page=$2"; }
# login <Sitzung> <Benutzer> <Passwort> → HTTP-Status (302 = angemeldet)
login() {
    local t
    rm -f "$JAR/$1"
    t=$(curl -s -c "$JAR/$1" "$BASE/?page=login" | csrf)
    code -b "$JAR/$1" -c "$JAR/$1" "$BASE/?page=login" --data-urlencode "_csrf=$t" --data-urlencode "user=$2" --data-urlencode "password=$3"
}
# post <Sitzung> <Seite> <Aktion> [Feld=Wert …] → Meldungen der Seite nach dem Redirect
post() {
    local jar=$1 page=$2 action=$3 t f
    shift 3
    local args=()
    for f in "$@"; do args+=(--data-urlencode "$f"); done
    t=$(get "$jar" users | csrf)
    curl -s -L -b "$JAR/$jar" -c "$JAR/$jar" "$BASE/?page=$page" --data-urlencode "_csrf=$t" --data-urlencode "action=$action" ${args[@]+"${args[@]}"} \
        | grep -o '<div class="msg msg-[a-z]*">.*</span></div>' | sed -e 's/<svg.*<\/svg>//' -e 's/<[^>]*>//g'
}

replica_registered() { [ -n "$(db_sql 'SHOW REPLICA HOSTS')" ]; }
city_digest_seen()   { [ -n "$(admin_sql "SELECT 1 FROM stats_mysql_query_digest WHERE digest = '0xB897F6E71B9FC28B' LIMIT 1")" ]; }

echo "== Bereit?"
wait_for "GUI antwortet ($BASE)" 60 curl -fs "$BASE/?page=login"
wait_for "Replikat beim Primary angemeldet" 90 replica_registered
wait_for "Lastgenerator läuft (Digest der Stadt-Statistik)" 90 city_digest_seen

echo "== Anmeldung"
expect "Admin-Login" '^302$' "$(login admin admin admin-demo)"
expect "Login nur zum Lesen" '^302$' "$(login ro readonly readonly-demo)"
expect "falsches Passwort wird abgelehnt" '^200$' "$(login bad admin falsch)"
expect "ohne Login Umleitung zum Login" '^302$' "$(code "$BASE/?page=dashboard")"

echo "== Seiten"
for p in dashboard servers users 'users&account=batch' rules 'rules&edit=100' queries sessions variables 'variables&prefix=admin-' \
         diff 'diff&module=servers' tables 'tables&t=scheduler' 'tables&t=mysql_galera_hostgroups' console; do
    expect "Admin: $p" '^200$' "$(status admin "$p")"
    expect "Nur lesen: $p" '^200$' "$(status ro "$p")"
done
expect "API liefert Diagrammdaten" '"connections":\{' "$(get admin 'api&range=900')"
for f in lib/sync.php pages/users.php bin/sync.php config.php; do
    expect "nicht direkt abrufbar: $f" '^403$' "$(code "$BASE/$f")"
done

echo "== Vorfälle im Demo"
dash=$(get admin dashboard)
for s in 'Passwort in ProxySQL und Backend unterschiedlich' 'Fehlt im Backend' 'Fehlt in ProxySQL' 'Neuer Cluster-Knoten' 'ProxySQL meldet Clients Version'; do
    expect "Vorfall: $s" "$s" "$dash"
done
expect_no "Replikationskonto repl gilt als Systemkonto" '<b>repl</b>' "$(get admin users)"

echo "== Login nur zum Lesen"
expect_no "keine SQL-Konsole im Menü" 'href="\?page=console"' "$(get ro dashboard)"
expect "SQL-Konsole gesperrt" 'nur mit dem Admin-Login' "$(get ro console)"
expect "Schreiben gesperrt" 'Nur-Lesen-Modus' "$(post ro servers sync_version)"
expect_no "keine Passwörter im HTML" 'radmin-demo|monitor-demo' "$(get ro 'variables&prefix=admin-')$(get ro 'variables&prefix=mysql-monitor_')"

echo "== Konfigurationsabgleich"
expect "Ausgangszustand sauber" 'Alles aktiv und gespeichert' "$(get admin diff)"
admin_sql "UPDATE mysql_servers SET max_connections = 333 WHERE hostname = 'mariadb'"
d=$(get admin 'diff&module=servers')
expect "Änderung am Admin-Interface vorbei erkannt" 'max_connections' "$d"
expect "als nicht aktiviert gemeldet" '1 nicht aktiviert' "$d"
expect "Verwerfen" 'auf den aktiven Stand zurückgesetzt' "$(post admin 'diff&module=servers' revert_runtime module=servers)"
expect "danach wieder sauber" 'Alles aktiv und gespeichert' "$(get admin diff)"

echo "== Tabellen"
expect "Galera-Hostgroup anlegen" 'Zeile angelegt' \
    "$(post admin 'tables&t=mysql_galera_hostgroups' save_row key= 'c[writer_hostgroup]=10' 'c[backup_writer_hostgroup]=11' 'c[reader_hostgroup]=12' 'c[offline_hostgroup]=13')"
expect "zur Laufzeit aktiv" '^10$' "$(admin_sql 'SELECT writer_hostgroup FROM runtime_mysql_galera_hostgroups')"
expect "ungültige Werte lehnt ProxySQL ab" 'CHECK constraint' \
    "$(post admin 'tables&t=mysql_galera_hostgroups' save_row key= 'c[writer_hostgroup]=20' 'c[backup_writer_hostgroup]=21' 'c[reader_hostgroup]=20' 'c[offline_hostgroup]=23')"
expect "Zeile löschen" 'Zeile gelöscht' "$(post admin 'tables&t=mysql_galera_hostgroups' delete_row 'key={"writer_hostgroup":"10"}')"
expect "zur Laufzeit gelöscht" '^0$' "$(admin_sql 'SELECT COUNT(*) FROM runtime_mysql_galera_hostgroups')"

echo "== Konten-Abgleich"
r=$(post admin users sync_accounts 'parts[]=import' 'parts[]=passwords' default_hostgroup=0)
expect "fehlendes Konto übernommen" '1 übernommen.*reporting' "$r"
expect "abweichendes Passwort angeglichen" 'angeglichen \(batch\)' "$r"
expect "reporting meldet sich über ProxySQL an" '^1$' "$(app_login reporting reporting-demo)"
expect "batch meldet sich mit dem Backend-Passwort an" '^1$' "$(app_login batch neues-passwort)"
expect "legacy wurde nicht gelöscht" '^1$' "$(admin_sql "SELECT COUNT(*) FROM runtime_mysql_users WHERE username = 'legacy' AND frontend = 1")"

echo "== Server-Version und Cluster"
expect "Server-Version angeglichen" 'mysql-server_version = [0-9.]+-MariaDB' "$(post admin servers sync_version)"
expect "Replikat aufgenommen" 'Hostgroup 0 \(Writer\) → 1 \(Reader\) angelegt.*mariadb-replica:3306 → HG 1' "$(post admin servers cluster_add reader_hg=1)"
expect "Replikat zur Laufzeit in HG 1" 'mariadb-replica' "$(admin_sql 'SELECT hostname FROM runtime_mysql_servers WHERE hostgroup_id = 1')"
expect "danach alles aktiv und gespeichert" 'Alles aktiv und gespeichert' "$(get admin diff)"
admin_sql "UPDATE mysql_servers SET max_connections = 444 WHERE hostname = 'mariadb'"
expect "Änderung in einer Replikations-Hostgroup erkannt" '1 nicht aktiviert' "$(get admin 'diff&module=servers')"
post admin 'diff&module=servers' revert_runtime module=servers >/dev/null
expect "nach Verwerfen wieder sauber" 'Alles aktiv und gespeichert' "$(get admin diff)"

echo "== Query-Cache"
expect "Cache-Regel angelegt" 'Cache-Regel #[0-9]+ angelegt' \
    "$(post admin queries cache_digest digest=0xB897F6E71B9FC28B ttl=5000 rule_id=110 'text=SELECT city,COUNT(*) FROM customers GROUP BY city')"
expect "Regel zur Laufzeit aktiv" '^5000$' "$(admin_sql "SELECT cache_ttl FROM runtime_mysql_query_rules WHERE UPPER(digest) = '0XB897F6E71B9FC28B'")"

echo "== Automatischer Abgleich (bin/sync.php)"
db_sql "CREATE USER 'ci_user'@'%' IDENTIFIED BY 'ci-demo'; GRANT SELECT ON shop.* TO 'ci_user'@'%'"
expect "neues Backend-Konto automatisch übernommen" 'übernommen.*ci_user' "$(sync_run users,passwords,cluster)"
expect "ci_user meldet sich über ProxySQL an" '^1$' "$(app_login ci_user ci-demo)"
expect "zweiter Lauf schreibt nichts ins Log" '^$' "$(sync_run users,passwords,cluster)"
expect "ohne AUTO_SYNC nichts zu tun" 'AUTO_SYNC ist leer' "$(sync_run '')"

echo "== Log"
expect "keine PHP-Warnungen" '^0$' "$(docker compose logs gui 2>&1 | grep -ciE 'php (warning|notice|fatal|deprecated)')"

echo
if [ "$FAILED" -gt 0 ]; then
    echo "$FAILED Prüfung(en) fehlgeschlagen"
    exit 1
fi
echo "Alle Prüfungen bestanden"
