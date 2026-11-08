<?php
// Konfiguration – kommt aus Umgebungsvariablen (siehe docker-compose.yml).
// Ohne Docker: config.local.php neben diese Datei legen, z. B.
//   <?php return ['proxysql' => ['host' => '127.0.0.1', 'password' => 'xxx'], 'gui_password' => 'geheim'];

function env(string $key, string $default = ''): string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

$config = [
    // ProxySQL Admin-Interface (Port 6032)
    'proxysql' => [
        'host'     => env('PROXYSQL_HOST', '127.0.0.1'),
        'port'     => (int) env('PROXYSQL_PORT', '6032'),
        'user'     => env('PROXYSQL_USER', 'radmin'),
        'password' => env('PROXYSQL_PASSWORD'),
    ],

    // Backend (MariaDB/MySQL) für Kontenabgleich und -verwaltung; leerer Host = deaktiviert.
    // Der Benutzer braucht CREATE USER und GRANT OPTION auf den zu vergebenden Rechten.
    'backend' => [
        'host'     => env('BACKEND_HOST'),
        'port'     => (int) env('BACKEND_PORT', '3306'),
        'user'     => env('BACKEND_USER'),
        'password' => env('BACKEND_PASSWORD'),
    ],

    // Login für die GUI. Ohne Passwort gibt es keinen Login und die GUI ist nur lesend.
    'gui_user'     => env('GUI_USER', 'admin'),
    'gui_password' => env('GUI_PASSWORD'),
    'readonly'     => env('GUI_READONLY', '0') === '1',

    // Zweites Login nur zum Lesen (ohne SQL-Konsole); ohne Passwort gibt es kein zweites Login
    'gui_readonly_user'     => env('GUI_READONLY_USER', 'readonly'),
    'gui_readonly_password' => env('GUI_READONLY_PASSWORD'),

    // Nach jeder Änderung LOAD ... TO RUNTIME und (wenn aktiv) SAVE ... TO DISK
    'save_to_disk' => env('SAVE_TO_DISK', '1') === '1',

    // Backend-Konten, die beim Abgleich ausgeblendet werden
    // (Monitor-Benutzer und GUI-Backend-Benutzer kommen automatisch dazu)
    'ignore_users' => array_values(array_filter(array_map('trim', explode(',',
        env('IGNORE_USERS', 'root,mariadb.sys,mysql.sys,mysql.session,mysql.infoschema,healthcheck,PUBLIC'))))),

    // Automatischer Abgleich durch bin/sync.php (Sync-Container oder Cronjob):
    // users = fehlende Konten übernehmen, passwords = Passwörter aus dem Backend angleichen,
    // cluster = neue Cluster-Knoten aufnehmen. Leer = nur anzeigen, Übernehmen per Klick.
    'auto_sync'      => array_values(array_filter(array_map('trim', explode(',', env('AUTO_SYNC'))))),
    'sync_interval'  => (int) env('SYNC_INTERVAL', '60'),
    // Hostgroup für übernommene Konten; leer = Writer-Hostgroup des Backends
    'sync_hostgroup' => env('SYNC_HOSTGROUP'),

    // Aktualisierung der Diagramme in Sekunden
    'refresh'  => (int) env('GUI_REFRESH', '15'),
    'timezone' => env('TZ', 'Europe/Berlin'),
];

if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace_recursive($config, require __DIR__ . '/config.local.php');
}

return $config;
