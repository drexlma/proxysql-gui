#!/bin/bash
# Konten für den ProxySQL-Monitor, die GUI und die Demo-Szenarien.
# Läuft nur beim ersten Start (leeres Datenverzeichnis).

mariadb --protocol=socket -uroot -p"${MARIADB_ROOT_PASSWORD}" <<SQL
-- ProxySQL-Monitor: prüft Verbindung, Ping und read_only
CREATE USER 'monitor'@'%' IDENTIFIED BY '${MONITOR_PASSWORD}';
GRANT USAGE, REPLICATION CLIENT ON *.* TO 'monitor'@'%';

-- GUI: darf Konten anlegen und Rechte vergeben
CREATE USER 'gui_admin'@'%' IDENTIFIED BY '${GUI_BACKEND_PASSWORD}';
GRANT ALL PRIVILEGES ON *.* TO 'gui_admin'@'%' WITH GRANT OPTION;

-- Demo: Konto in beiden Systemen (siehe docker/proxysql/proxysql.cnf.tpl)
CREATE USER 'app'@'%' IDENTIFIED BY '${APP_PASSWORD}';
GRANT SELECT, INSERT, UPDATE, DELETE ON shop.* TO 'app'@'%';

-- Demo: nur im Backend -> GUI meldet "Fehlt in ProxySQL"
CREATE USER 'reporting'@'%' IDENTIFIED BY 'reporting-demo';
GRANT SELECT ON shop.* TO 'reporting'@'%';

-- Demo: anderes Passwort als in ProxySQL -> GUI meldet "Passwort weicht ab"
CREATE USER 'batch'@'%' IDENTIFIED BY 'neues-passwort';
GRANT SELECT, INSERT, UPDATE ON shop.* TO 'batch'@'%';
SQL
