# ProxySQL-Konfiguration für docker-compose.prod.yml (Profil "proxysql").
# @...@-Platzhalter werden von entrypoint.sh aus der Umgebung ersetzt.
#
# ProxySQL liest diese Datei nur beim allerersten Start. Danach gilt /var/lib/proxysql/proxysql.db (Volume) –
# Konten, Regeln und weitere Server also über die GUI anlegen:
#   Konten  → Abgleich Backend → ProxySQL (oder AUTO_SYNC=users,passwords)
#   Server  → Cluster: Replikate bzw. Cluster-Knoten aufnehmen (oder AUTO_SYNC=cluster)

datadir="/var/lib/proxysql"
errorlog="/var/lib/proxysql/proxysql.log"

admin_variables=
{
    # ein Admin-Benutzer für GUI und Healthcheck; das Admin-Interface ist nur im Compose-Netz erreichbar
    admin_credentials="radmin:@PROXYSQL_ADMIN_PASSWORD@"
    mysql_ifaces="0.0.0.0:6032"

    # Statistik-Historie für die Diagramme der GUI (Standard wäre 60 s)
    stats_mysql_connections=10
    stats_mysql_connection_pool=10
    stats_mysql_query_cache=10
}

mysql_variables=
{
    threads=4
    max_connections=2048
    interfaces="0.0.0.0:6033"
    default_schema="information_schema"
    server_version="@MYSQL_SERVER_VERSION@"
    connect_timeout_server=3000

    # der Monitor-Benutzer muss im Backend existieren (die GUI legt ihn auf Wunsch an)
    monitor_username="@MONITOR_USER@"
    monitor_password="@MONITOR_PASSWORD@"
    monitor_connect_interval=60000
    monitor_ping_interval=10000
    monitor_read_only_interval=1500
    monitor_history=3600000

    query_cache_size_MB=256
    query_digests=true
    commands_stats=true
    sessions_sort=true
}

# Primary bzw. erster Knoten; weitere über die Cluster-Erkennung der GUI
mysql_servers=
(
    {
        address="@BACKEND_HOST@"
        port=@BACKEND_PORT@
        hostgroup=0
        max_connections=1000
        comment="Primary"
    }
)
