<?php
// ProxySQL: Statuswerte, Konfigurationszustand, Vorfälle, Metriken für die Diagramme

const MODULES = [
    'servers'   => 'MYSQL SERVERS',
    'users'     => 'MYSQL USERS',
    'rules'     => 'MYSQL QUERY RULES',
    'mysqlvars' => 'MYSQL VARIABLES',
    'adminvars' => 'ADMIN VARIABLES',
    'pgsqlvars' => 'PGSQL VARIABLES',
    'scheduler' => 'SCHEDULER',
    'cluster'   => 'PROXYSQL SERVERS',
    'firewall'  => 'MYSQL FIREWALL',
];

/** Änderungen aktivieren (LOAD ... TO RUNTIME) und speichern (SAVE ... TO DISK) */
function apply_module(string $module): string
{
    $m = MODULES[$module] ?? throw new InvalidArgumentException("Unbekanntes Modul: $module");
    admin()->query("LOAD $m TO RUNTIME");
    if (!cfg('save_to_disk')) {
        return "$m aktiviert (nicht auf Disk gespeichert).";
    }
    admin()->query("SAVE $m TO DISK");
    return "$m aktiviert und gespeichert.";
}

function save_module(string $module): string
{
    $m = MODULES[$module] ?? throw new InvalidArgumentException("Unbekanntes Modul: $module");
    admin()->query("SAVE $m TO DISK");
    return "$m auf Disk gespeichert.";
}

/** Änderungen im Speicher verwerfen: Stand der Laufzeit bzw. von Disk zurückholen */
function revert_module(string $module, string $from): string
{
    $m = MODULES[$module] ?? throw new InvalidArgumentException("Unbekanntes Modul: $module");
    if ($from === 'runtime') {
        admin()->query("SAVE $m FROM RUNTIME");
        return "$m: Speicher auf den aktiven Stand zurückgesetzt.";
    }
    admin()->query("LOAD $m FROM DISK");
    return "$m: Stand von Disk in den Speicher geladen.";
}

function proxysql_version(): string
{
    static $v = null;
    return $v ??= (string) qv(admin(), 'SELECT @@version');
}

function stats_global(): array
{
    static $s = null;
    if ($s === null) {
        $s = array_column(q(admin(), 'SELECT Variable_Name, Variable_Value FROM stats_mysql_global'), 'Variable_Value', 'Variable_Name');
    }
    return $s;
}

function global_var(string $name): ?string
{
    static $vars = null;
    $vars ??= array_column(q(admin(), 'SELECT variable_name, variable_value FROM global_variables'), 'variable_value', 'variable_name');
    return $vars[$name] ?? null;
}

/** Hostgroups [id => Beschriftung] aus Servern und Replikations-Hostgroups */
function hostgroups(): array
{
    static $hg = null;
    if ($hg !== null) {
        return $hg;
    }
    $count = [];
    foreach (q(admin(), 'SELECT hostgroup_id, COUNT(*) AS n FROM runtime_mysql_servers GROUP BY hostgroup_id') as $r) {
        $count[(int) $r['hostgroup_id']] = (int) $r['n'];
    }
    foreach (q(admin(), 'SELECT hostgroup_id, COUNT(*) AS n FROM mysql_servers GROUP BY hostgroup_id') as $r) {
        $count[(int) $r['hostgroup_id']] = max($count[(int) $r['hostgroup_id']] ?? 0, (int) $r['n']);
    }
    $role = [];
    foreach (q(admin(), 'SELECT writer_hostgroup, reader_hostgroup FROM mysql_replication_hostgroups') as $r) {
        $role[(int) $r['writer_hostgroup']] = 'Writer';
        $role[(int) $r['reader_hostgroup']] = 'Reader';
        $count += [(int) $r['writer_hostgroup'] => 0, (int) $r['reader_hostgroup'] => 0];
    }
    $count += [0 => 0];
    ksort($count);
    $hg = [];
    foreach ($count as $id => $n) {
        $hg[$id] = "HG $id · $n Server" . (isset($role[$id]) ? ' · ' . $role[$id] : '');
    }
    return $hg;
}

/** Hostgroups mit mindestens einem ONLINE-Server zur Laufzeit */
function online_hostgroups(): array
{
    static $hg = null;
    return $hg ??= array_map('intval', array_column(q(admin(), "SELECT DISTINCT hostgroup_id FROM runtime_mysql_servers WHERE status = 'ONLINE'"), 'hostgroup_id'));
}

/** Hostgroups, deren Server ProxySQL selbst verschiebt (Replikation, Galera, Group Replication) */
function managed_hostgroups(): array
{
    $hg = [];
    foreach (q(admin(), 'SELECT writer_hostgroup, reader_hostgroup FROM mysql_replication_hostgroups') as $r) {
        array_push($hg, ...array_values($r));
    }
    foreach (['mysql_galera_hostgroups', 'mysql_group_replication_hostgroups'] as $t) {
        foreach (q(admin(), "SELECT writer_hostgroup, backup_writer_hostgroup, reader_hostgroup, offline_hostgroup FROM $t") as $r) {
            array_push($hg, ...array_values($r));
        }
    }
    return array_unique(array_map('intval', $hg));
}

// ---------------------------------------------------------------- Vorfälle

/**
 * Alle erkannten Probleme. Eintrag: sev, cat, summary, target, type, since?, url?/post?, label?
 */
function incidents(): array
{
    $out = [];
    $add = function (string $sev, string $cat, string $summary, string $target, array $extra = []) use (&$out): void {
        $out[] = $extra + ['sev' => $sev, 'cat' => $cat, 'summary' => $summary, 'target' => $target, 'type' => 'Problem'];
    };
    $a = admin();

    // Backend-Verbindung der GUI
    $bc = cfg('backend');
    if (!backend_configured()) {
        $add('info', 'Konfiguration', 'Kein Backend für den Kontenabgleich konfiguriert (BACKEND_HOST/BACKEND_USER)', 'GUI', ['type' => 'Hinweis']);
    } elseif (!backend()) {
        $add('critical', 'Server', 'GUI erreicht das Backend nicht: ' . backend_error(), $bc['host'] . ':' . $bc['port'], ['type' => 'Vorfall']);
    }

    // Server-Status zur Laufzeit
    if (!(int) qv($a, 'SELECT COUNT(*) FROM mysql_servers')) {
        $add('error', 'Server', 'Keine Backend-Server konfiguriert', 'mysql_servers', ['url' => '?page=servers', 'label' => 'Server anlegen']);
    }
    $sev = ['SHUNNED' => 'critical', 'OFFLINE_HARD' => 'error', 'OFFLINE_SOFT' => 'warning'];
    $runtime = [];
    foreach (q($a, 'SELECT hostgroup_id, hostname, port, status FROM runtime_mysql_servers') as $s) {
        $runtime[$s['hostname'] . ':' . $s['port']] = true;
        if ($s['status'] !== 'ONLINE') {
            $add($sev[$s['status']] ?? 'warning', 'Server', "Server ist {$s['status']} (Hostgroup {$s['hostgroup_id']})", $s['hostname'] . ':' . $s['port'],
                ['type' => 'Vorfall', 'url' => '?page=servers', 'label' => 'Server']);
        }
    }

    // Monitor: letzter Check je Server fehlgeschlagen (nur Server, die aktuell konfiguriert sind)
    $recent = (time() - 300) * 1000000;
    foreach (['ping' => 'Ping', 'connect' => 'Verbindung'] as $kind => $label) {
        $t = "monitor.mysql_server_{$kind}_log";
        $sql = "SELECT l.hostname, l.port, l.time_start_us, l.{$kind}_error AS err FROM $t l
                JOIN (SELECT hostname, port, MAX(time_start_us) AS ts FROM $t GROUP BY hostname, port) m
                  ON m.hostname = l.hostname AND m.port = l.port AND m.ts = l.time_start_us
                WHERE l.{$kind}_error IS NOT NULL AND l.time_start_us > $recent";
        foreach (q($a, $sql) as $e) {
            if (isset($runtime[$e['hostname'] . ':' . $e['port']])) {
                $add('error', 'Monitor', "Monitor-$label fehlgeschlagen: {$e['err']}", $e['hostname'] . ':' . $e['port'],
                    ['type' => 'Vorfall', 'since' => intdiv((int) $e['time_start_us'], 1000000)]);
            }
        }
    }

    if (backend()) {
        // Monitor-Benutzer muss im Backend existieren
        $mon = (string) global_var('mysql-monitor_username');
        if ($mon !== '' && !isset(backend_accounts()[$mon])) {
            $add('error', 'Monitor', "Monitor-Benutzer '$mon' fehlt im Backend", $mon,
                ['post' => ['action' => 'create_monitor'], 'to' => '?page=users', 'label' => 'Im Backend anlegen']);
        }
    }

    if (backend()) {
        // Cluster-Knoten, die in ProxySQL fehlen bzw. nicht mehr im Cluster sind
        $cluster = cluster_plan();
        foreach ($cluster['new'] as $n) {
            $direct = !$cluster['setup'] && !$cluster['hint'];
            $add('warning', 'Server', "Neuer Cluster-Knoten ({$n['role']}) ist nicht in ProxySQL eingetragen", $n['host'] . ':' . $n['port'],
                ['url' => '?page=servers#cluster', 'label' => $direct ? 'Aufnehmen' : 'Ansehen']
                + ($direct ? ['post' => ['action' => 'cluster_add'], 'to' => '?page=servers'] : []));
        }
        foreach ($cluster['gone'] as $g) {
            $add('warning', 'Server', 'Eingetragener Server ist nicht mehr im Cluster', $g['hostname'] . ':' . $g['port'], ['url' => '?page=servers#cluster', 'label' => 'Ansehen']);
        }
        // Version, die ProxySQL den Clients meldet
        if ($v = server_version_mismatch()) {
            $add('warning', 'Konfiguration', "ProxySQL meldet Clients Version {$v['proxy']}, das Backend ist {$v['backend']}", 'mysql-server_version',
                ['post' => ['action' => 'sync_version'], 'to' => '?page=servers', 'label' => 'Angleichen']);
        }
    }

    // Konten
    foreach (account_matrix() as $acc) {
        foreach ($acc['issues'] as $is) {
            $add($is['sev'], 'Konten', $is['text'], $acc['name'], ['url' => url(['page' => 'users', 'account' => $acc['name']]) . '#account', 'label' => 'Konto öffnen']);
        }
    }

    // Regeln, die auf eine Hostgroup ohne ONLINE-Server zeigen
    $online = online_hostgroups();
    foreach (q($a, 'SELECT destination_hostgroup AS hg, GROUP_CONCAT(rule_id) AS ids FROM mysql_query_rules WHERE active = 1 AND destination_hostgroup IS NOT NULL GROUP BY destination_hostgroup') as $r) {
        if (!in_array((int) $r['hg'], $online, true)) {
            $add('error', 'Server', "Query-Regeln #{$r['ids']} leiten auf Hostgroup {$r['hg']} – dort ist kein Server ONLINE", "Hostgroup {$r['hg']}",
                ['url' => '?page=rules', 'label' => 'Regeln']);
        }
    }

    // Konfiguration nicht aktiv / nicht gespeichert
    foreach (config_state() as $mod => $st) {
        $diffUrl = url(['page' => 'diff', 'module' => $mod]);
        if ($st['runtime']) {
            $add('warning', 'Konfiguration', MODULES[$mod] . ": {$st['runtime']} Änderung(en) im Speicher sind noch nicht aktiv", 'Runtime',
                ['url' => $diffUrl . '#runtime', 'post' => ['action' => 'apply', 'module' => $mod], 'label' => 'Aktivieren']);
        }
        if ($st['disk']) {
            $add('warning', 'Konfiguration', MODULES[$mod] . ": {$st['disk']} Änderung(en) nicht auf Disk gespeichert – gehen beim Neustart verloren", 'Disk',
                ['url' => $diffUrl . '#disk', 'post' => ['action' => 'save', 'module' => $mod], 'label' => 'Speichern']);
        }
    }

    // Query-Cache
    $hits = array_column(q($a, 'SELECT rule_id, hits FROM stats_mysql_query_rules'), 'hits', 'rule_id');
    foreach (q($a, 'SELECT rule_id, cache_ttl FROM runtime_mysql_query_rules WHERE active = 1 AND cache_ttl IS NOT NULL') as $r) {
        if ((int) ($hits[$r['rule_id']] ?? 0) === 0) {
            $add('info', 'Cache', "Cache-Regel #{$r['rule_id']} hatte bisher keine Treffer", "Regel #{$r['rule_id']}",
                ['type' => 'Hinweis', 'url' => url(['page' => 'rules', 'edit' => $r['rule_id']]) . '#rule-form', 'label' => 'Regel']);
        }
    }
    foreach (cache_candidates(3) as $d) {
        $add('info', 'Cache', 'Häufige Query ohne Cache (' . nf($d['count_star']) . '×): ' . mb_strimwidth($d['digest_text'], 0, 90, '…'), $d['digest'],
            ['type' => 'Hinweis', 'url' => url(['page' => 'queries', 'q' => $d['digest']]), 'label' => 'Cachen …']);
    }

    $rank = ['critical' => 0, 'error' => 1, 'warning' => 2, 'info' => 3];
    usort($out, fn ($x, $y) => $rank[$x['sev']] <=> $rank[$y['sev']]);
    return $out;
}

// ---------------------------------------------------------------- Dashboard-Daten

/** Ping-Verfügbarkeit der letzten $seconds Sekunden (Monitor-Log) */
function availability(int $seconds = 600): array
{
    $now = time();
    $since = $now - $seconds;
    $rows = q(admin(), 'SELECT hostname, port, time_start_us / 60000000 AS minute, COUNT(*) AS n, SUM(ping_error IS NULL) AS ok
                        FROM monitor.mysql_server_ping_log WHERE time_start_us >= ' . ($since * 1000000) . ' GROUP BY hostname, port, minute');
    $buckets = [];
    $servers = [];
    $n = $ok = 0;
    foreach ($rows as $r) {
        $key = $r['hostname'] . ':' . $r['port'];
        $buckets[(int) $r['minute']][0] = ($buckets[(int) $r['minute']][0] ?? 0) + $r['n'];
        $buckets[(int) $r['minute']][1] = ($buckets[(int) $r['minute']][1] ?? 0) + $r['ok'];
        $servers[$key][0] = ($servers[$key][0] ?? 0) + $r['n'];
        $servers[$key][1] = ($servers[$key][1] ?? 0) + $r['ok'];
        $n += $r['n'];
        $ok += $r['ok'];
    }
    $segments = [];
    for ($m = intdiv($since, 60) + 1; $m <= intdiv($now, 60); $m++) {
        $segments[] = isset($buckets[$m]) ? ['t' => $m * 60, 'pct' => 100 * $buckets[$m][1] / $buckets[$m][0]] : ['t' => $m * 60, 'pct' => null];
    }
    $status = [];
    foreach (q(admin(), 'SELECT hostgroup_id, hostname, port, status FROM runtime_mysql_servers ORDER BY hostgroup_id, hostname') as $s) {
        $key = $s['hostname'] . ':' . $s['port'];
        $status[$key] ??= ['status' => $s['status'], 'hostgroups' => []];
        $status[$key]['hostgroups'][] = $s['hostgroup_id'];
        if ($s['status'] !== 'ONLINE') {
            $status[$key]['status'] = $s['status'];
        }
    }
    foreach ($status as $key => $st) {
        $status[$key]['pct'] = isset($servers[$key]) ? 100 * $servers[$key][1] / $servers[$key][0] : null;
    }
    return [
        'pct'      => $n ? 100 * $ok / $n : null,
        'segments' => $segments,
        'servers'  => $status,
        'from'     => $since,
        'to'       => $now,
    ];
}

function backend_info(): array
{
    $b = backend();
    $i = q1($b, 'SELECT @@hostname AS hostname, @@version AS version, @@version_comment AS comment, @@port AS port, @@datadir AS datadir, @@socket AS socket');
    $i['uptime'] = (int) (q1($b, "SHOW GLOBAL STATUS LIKE 'Uptime'")['Value'] ?? 0);
    return $i;
}

/** Nur jede n-te Zeile behalten, damit Diagramme nicht zu viele Punkte bekommen */
function downsample(array $rows, int $max = 240): array
{
    $c = count($rows);
    if ($c <= $max) {
        return $rows;
    }
    $step = (int) ceil($c / $max);
    $out = [];
    for ($i = 0; $i < $c; $i += $step) {
        $out[] = $rows[$i];
    }
    if (end($out) !== $rows[$c - 1]) {
        $out[] = $rows[$c - 1];
    }
    return $out;
}

/** Ab diesem Abstand zweier Messpunkte gilt die Zeitreihe als unterbrochen (3 × üblicher Abstand) */
function gap_limit(array $ts): int
{
    $d = [];
    for ($i = 1, $c = count($ts); $i < $c; $i++) {
        $d[] = $ts[$i] - $ts[$i - 1];
    }
    if (!$d) {
        return PHP_INT_MAX;
    }
    sort($d);
    return 3 * max(1, $d[intdiv(count($d), 2)]);
}

/**
 * Zeitreihe aus stats_history: $gauges werden direkt angezeigt, $rates als Delta pro Sekunde.
 * Lücken (ProxySQL-Neustart, angehaltener Host) bekommen einen null-Punkt, damit die Linie dort abreißt.
 */
function chart_series(array $rows, array $gauges, array $rates, string $empty): array
{
    $out = ['t' => [], 'series' => [], 'empty' => $empty];
    foreach (array_merge(array_keys($gauges), array_keys($rates)) as $name) {
        $out['series'][$name] = [];
    }
    $gap = gap_limit(array_map('intval', array_column($rows, 'timestamp')));
    for ($i = 1, $c = count($rows); $i < $c; $i++) {
        $cur = $rows[$i];
        $prev = $rows[$i - 1];
        $dt = $cur['timestamp'] - $prev['timestamp'];
        if ($dt > $gap) {
            $out['t'][] = intdiv($cur['timestamp'] + $prev['timestamp'], 2);
            foreach (array_keys($out['series']) as $name) {
                $out['series'][$name][] = null;
            }
        }
        $out['t'][] = (int) $cur['timestamp'];
        foreach ($gauges as $name => $col) {
            $out['series'][$name][] = (int) $cur[$col];
        }
        foreach ($rates as $name => $col) {
            $dv = $cur[$col] - $prev[$col];
            $out['series'][$name][] = ($dt > 0 && $dt <= $gap && $dv >= 0) ? round($dv / $dt, 2) : null;
        }
    }
    return $out;
}

/** Ping-Latenz je Server in ms (aus der Connection-Pool-Historie) */
function latency_series(array $rows, string $empty): array
{
    $ts = [];
    $vals = [];
    foreach ($rows as $r) {
        $ts[(int) $r['timestamp']] = true;
        $vals[$r['srv']][(int) $r['timestamp']] = round($r['lat'] / 1000, 2);
    }
    $t = downsample(array_keys($ts));
    $gap = gap_limit($t);
    $out = ['t' => [], 'series' => array_fill_keys(array_keys($vals), []), 'empty' => $empty, 'unit' => 'ms'];
    foreach ($t as $i => $x) {
        if ($i > 0 && $x - $t[$i - 1] > $gap) {
            $out['t'][] = intdiv($x + $t[$i - 1], 2);
            foreach (array_keys($vals) as $srv) {
                $out['series'][$srv][] = null;
            }
        }
        $out['t'][] = $x;
        foreach ($vals as $srv => $byTs) {
            $out['series'][$srv][] = $byTs[$x] ?? null;
        }
    }
    return $out;
}

function metrics(int $range): array
{
    $a = admin();
    $since = time() - $range;
    $conn = downsample(q($a, "SELECT * FROM stats_history.mysql_connections WHERE timestamp >= $since ORDER BY timestamp"));
    $cache = downsample(q($a, "SELECT * FROM stats_history.mysql_query_cache WHERE timestamp >= $since ORDER BY timestamp"));
    $pool = q($a, "SELECT timestamp, srv_host || ':' || srv_port AS srv, MAX(Latency_us) AS lat
                   FROM stats_history.history_stats_mysql_connection_pool WHERE timestamp >= $since
                   GROUP BY timestamp, srv ORDER BY timestamp");
    if (!$pool) {
        // Pool-Historie wird nicht von jeder Version geschrieben – dann das Monitor-Ping-Log nehmen,
        // auf das Ping-Intervall gebündelt, damit mehrere Server gemeinsame Zeitpunkte haben
        $bucket = max(1, intdiv((int) global_var('mysql-monitor_ping_interval'), 1000));
        $pool = q($a, "SELECT time_start_us / 1000000 / $bucket * $bucket AS timestamp, hostname || ':' || port AS srv, MAX(ping_success_time_us) AS lat
                       FROM monitor.mysql_server_ping_log WHERE ping_error IS NULL AND time_start_us >= " . ($since * 1000000) . '
                       GROUP BY timestamp, srv ORDER BY timestamp');
    }
    $empty = 'Noch keine Daten – ProxySQL schreibt die Historie alle ' . (int) global_var('admin-stats_mysql_connections') . ' s.';

    $g = stats_global();
    $questions = (int) ($g['Questions'] ?? 0);
    $get = (int) ($g['Query_Cache_count_GET'] ?? 0);
    $getOk = (int) ($g['Query_Cache_count_GET_OK'] ?? 0);

    return [
        'connections' => chart_series($conn, ['Clients verbunden' => 'Client_Connections_connected', 'Backend-Verbindungen' => 'Server_Connections_connected'], [], $empty),
        'statements'  => chart_series($conn, [], ['Queries/s' => 'Questions', 'Slow Queries/s' => 'Slow_queries'], $empty),
        'pool'        => chart_series($conn, [], [
            'Neue Clients/s'           => 'Client_Connections_created',
            'Neue Backend-Verb./s'     => 'Server_Connections_created',
            'Verbindung aus Pool/s'    => 'ConnPool_get_conn_success',
        ], $empty),
        'cache'       => chart_series($cache, [], ['Cache-Abfragen/s' => 'count_GET', 'Treffer/s' => 'count_GET_OK', 'Gespeichert/s' => 'count_SET'], $empty),
        'latency'     => latency_series($pool, $empty),
        'split'       => [
            'labels' => ['Aus dem Cache', 'Cache-Miss → Backend', 'Ohne Cache → Backend'],
            'values' => [$getOk, max(0, $get - $getOk), max(0, $questions - $get)],
            'kpis'   => [
                ['Trefferquote', $get ? nf(100 * $getOk / $get, 1) . ' %' : '–'],
                ['Einträge', nf($g['Query_Cache_Entries'] ?? 0)],
                ['Speicher', fmt_bytes($g['Query_Cache_Memory_bytes'] ?? 0) . ' / ' . nf(global_var('mysql-query_cache_size_MB')) . ' MB'],
            ],
        ],
    ];
}
