# ProxySQL-Konfiguration für das Docker-Beispiel.
# @...@-Platzhalter werden von entrypoint.sh aus der Umgebung ersetzt.
#
# Wichtig: ProxySQL liest diese Datei nur beim allerersten Start. Danach gilt die
# Konfiguration aus /var/lib/proxysql/proxysql.db (Volume) – Änderungen also über
# die GUI bzw. das Admin-Interface machen.

datadir="/var/lib/proxysql"
errorlog="/var/lib/proxysql/proxysql.log"

admin_variables=
{
    # "admin" funktioniert nur innerhalb des Containers, "radmin" nutzt die GUI
    admin_credentials="admin:admin;radmin:@PROXYSQL_ADMIN_PASSWORD@"
    mysql_ifaces="0.0.0.0:6032"

    # Statistik-Historie alle 10 s schreiben (Standard 60 s) – für die Diagramme
    stats_mysql_connections=10
    stats_mysql_connection_pool=10
    stats_mysql_query_cache=10
    stats_system_cpu=10
    stats_system_memory=10
}

mysql_variables=
{
    threads=4
    max_connections=2048
    interfaces="0.0.0.0:6033"
    default_schema="information_schema"
    server_version="8.0.36"
    connect_timeout_server=3000

    monitor_username="monitor"
    monitor_password="@MONITOR_PASSWORD@"
    monitor_connect_interval=10000
    monitor_ping_interval=5000
    monitor_read_only_interval=1500
    monitor_history=3600000

    query_cache_size_MB=64
    query_digests=true
    commands_stats=true
    sessions_sort=true
}

mysql_servers=
(
    {
        address="mariadb"
        port=3306
        hostgroup=0
        max_connections=200
        comment="MariaDB primary"
    }
)

mysql_users=
(
    {
        username="app"
        password="@APP_PASSWORD@"
        default_hostgroup=0
        default_schema="shop"
        comment="Demo-Anwendung"
    },
    {
        # existiert nicht in MariaDB -> GUI meldet "Fehlt im Backend"
        username="legacy"
        password="legacy-demo"
        default_hostgroup=0
        comment="Demo: altes Konto"
    },
    {
        # MariaDB hat ein anderes Passwort -> GUI meldet "Passwort weicht ab"
        username="batch"
        password="altes-passwort"
        default_hostgroup=0
        default_schema="shop"
        comment="Demo: Batch-Jobs"
    }
)

mysql_query_rules=
(
    {
        rule_id=100
        active=1
        match_digest="^SELECT category,COUNT"
        cache_ttl=10000
        apply=0
        comment="Demo: Kategorie-Statistik 10 s cachen"
    }
)
