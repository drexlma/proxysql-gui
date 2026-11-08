<?php
// Abgleich wie bei Perconas proxysql-admin: Konten (--syncusers), Cluster-Knoten (--update-cluster)
// und die Server-Version (--update-mysql-version). Die Seiten nutzen das mit Bestätigung,
// bin/sync.php automatisch laut AUTO_SYNC. Gelöscht wird nie automatisch.

const AUTO_SYNC_PARTS = [
    'users'     => 'fehlende Konten übernehmen',
    'passwords' => 'Passwörter aus dem Backend angleichen',
    'cluster'   => 'neue Cluster-Knoten aufnehmen',
];

/** In AUTO_SYNC eingeschaltete Teile */
function auto_sync(): array
{
    return array_values(array_intersect(array_keys(AUTO_SYNC_PARTS), cfg('auto_sync')));
}

/** Hinweistext zum Automatikbetrieb */
function auto_sync_note(array $parts): string
{
    $on = array_values(array_intersect($parts, auto_sync()));
    return $on
        ? 'Automatisch alle ' . cfg('sync_interval') . ' s: ' . implode(', ', array_map(fn ($p) => AUTO_SYNC_PARTS[$p], $on))
        : 'Automatisch: aus (AUTO_SYNC=' . implode(',', $parts) . ')';
}

/** Hostgroup für übernommene Konten: SYNC_HOSTGROUP oder die Writer-Hostgroup des Backends */
function sync_hostgroup(): int
{
    if (cfg('sync_hostgroup') !== '') {
        return (int) cfg('sync_hostgroup');
    }
    $bc = cfg('backend');
    foreach (q(admin(), 'SELECT hostgroup_id, hostname, port FROM mysql_servers
                         WHERE hostgroup_id NOT IN (SELECT reader_hostgroup FROM mysql_replication_hostgroups) ORDER BY hostgroup_id') as $s) {
        if (same_node($s['hostname'], (int) $s['port'], $bc['host'], (int) $bc['port'])) {
            return (int) $s['hostgroup_id'];
        }
    }
    return 0;
}

// ---------------------------------------------------------------- Konten (Backend → ProxySQL)

/** Gemeinsamer mysql_native_password-Hash aller Backend-Hosts eines Kontos, sonst der Grund, warum es keinen gibt */
function sync_hash(array $hosts): array
{
    if (!array_diff(array_column($hosts, 'host'), LOCAL_HOSTS)) {
        return [null, 'nur für localhost angelegt'];
    }
    $native = array_filter($hosts, fn ($h) => in_array($h['plugin'], ['mysql_native_password', ''], true));
    if (!$native) {
        return [null, 'Anmeldung per ' . implode('/', array_unique(array_column($hosts, 'plugin'))) . ' – Hash nicht übertragbar, Passwort neu setzen'];
    }
    $hashes = array_unique(array_map(fn ($h) => strtoupper((string) $h['auth']), $native));
    if (count($hashes) > 1) {
        return [null, 'unterschiedliche Passwörter je Host'];
    }
    $hash = reset($hashes);
    return is_native_hash($hash) ? [$hash, null] : [null, 'ohne Passwort'];
}

/**
 * Was ein Konten-Abgleich täte: import [name => hash] (fehlt in ProxySQL), passwords [name => hash] (weicht ab),
 * delete [name] (nur in ProxySQL), skipped [name => Grund]
 */
function account_sync_plan(): array
{
    $plan = ['import' => [], 'passwords' => [], 'delete' => [], 'skipped' => []];
    foreach (account_matrix() as $acc) {
        $codes = array_column($acc['issues'], 'code');
        $kind = in_array('missing_proxy', $codes, true) ? 'import' : (in_array('pw_mismatch', $codes, true) ? 'passwords' : null);
        if ($kind) {
            [$hash, $reason] = sync_hash($acc['hosts']);
            if ($hash) {
                $plan[$kind][$acc['name']] = $hash;
            } else {
                $plan['skipped'][$acc['name']] = $reason;
            }
        }
        if (in_array('missing_backend', $codes, true)) {
            $plan['delete'][] = $acc['name'];
        }
    }
    return $plan;
}

/** Konten abgleichen; $parts ⊆ import, passwords, delete. Ergebnis als Meldung. */
function account_sync(array $parts, int $hostgroup): string
{
    $plan = account_sync_plan();
    $done = [];
    if (in_array('import', $parts, true) && $plan['import']) {
        foreach ($plan['import'] as $name => $hash) {
            insert_proxy_user((string) $name, $hash, $hostgroup, ['comment' => 'aus Backend übernommen']);
        }
        $done[] = count($plan['import']) . " übernommen in HG $hostgroup (" . implode(', ', array_keys($plan['import'])) . ')';
    }
    if (in_array('passwords', $parts, true) && $plan['passwords']) {
        foreach ($plan['passwords'] as $name => $hash) {
            run(admin(), 'UPDATE mysql_users SET password = ' . aq($hash) . ' WHERE username = ' . aq((string) $name));
        }
        $done[] = count($plan['passwords']) . ' Passwort/Passwörter angeglichen (' . implode(', ', array_keys($plan['passwords'])) . ')';
    }
    if (in_array('delete', $parts, true) && $plan['delete']) {
        foreach ($plan['delete'] as $name) {
            run(admin(), 'DELETE FROM mysql_users WHERE username = ' . aq((string) $name));
        }
        $done[] = count($plan['delete']) . ' gelöscht (' . implode(', ', $plan['delete']) . ')';
    }
    if ($done) {
        $done[] = apply_module('users');
    }
    $skipped = array_map(fn ($n, $r) => "$n: $r", array_keys($plan['skipped']), $plan['skipped']);
    return ($done ? implode(' · ', $done) : 'Nichts zu tun') . ($skipped ? ' · übersprungen: ' . implode('; ', $skipped) : '');
}

// ---------------------------------------------------------------- Server-Version

/** Version, die ProxySQL den Clients meldet, passt nicht zum Backend: [proxy, backend] oder null */
function server_version_mismatch(): ?array
{
    if (!backend()) {
        return null;
    }
    $proxy = (string) global_var('mysql-server_version');
    $back = (string) qv(backend(), 'SELECT VERSION()');
    $norm = fn (string $v) => [preg_match('/^\d+\.\d+/', $v, $m) ? $m[0] : $v, stripos($v, 'mariadb') !== false];
    return $norm($proxy) === $norm($back) ? null : ['proxy' => $proxy, 'backend' => $back];
}

/** mysql-server_version auf die Backend-Version setzen (ohne Distributions-Anhang) */
function sync_server_version(): string
{
    $back = (string) qv(backend() ?? throw new RuntimeException('Backend nicht verbunden'), 'SELECT VERSION()');
    $version = preg_match('/^\d+\.\d+\.\d+(-MariaDB)?/i', $back, $m) ? $m[0] : $back;
    run(admin(), "UPDATE global_variables SET variable_value = " . aq($version) . " WHERE variable_name = 'mysql-server_version'");
    return "mysql-server_version = $version. " . apply_module('mysqlvars');
}

// ---------------------------------------------------------------- Cluster-Knoten

/** IP-Adressen eines Hosts (Namen werden aufgelöst) */
function host_ips(string $host): array
{
    static $cache = [];
    return $cache[$host] ??= filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: [$host]);
}

function same_node(string $h1, int $p1, string $h2, int $p2): bool
{
    return $p1 === $p2 && array_intersect(host_ips($h1), host_ips($h2));
}

/** "host:port" bzw. "[ipv6]:port" zerlegen */
function split_addr(string $addr, int $defaultPort): array
{
    $addr = trim($addr);
    if (preg_match('/^\[(.+)\](?::(\d+))?$/', $addr, $m) || preg_match('/^([^:]+)(?::(\d+))?$/', $addr, $m)) {
        return [$m[1], (int) ($m[2] ?? $defaultPort) ?: $defaultPort];
    }
    return [$addr, $defaultPort];
}

/**
 * Knoten aus Sicht des Backends: type (galera | group_replication | replication | null),
 * nodes [host, port, role]. Bei Replikation: Replikate, die beim Primary (= Backend) angemeldet sind.
 */
function cluster_discover(): array
{
    $b = backend();
    $out = ['type' => null, 'nodes' => []];
    if (!$b) {
        return $out;
    }
    $bc = cfg('backend');

    $wsrep = (string) (q1($b, "SHOW GLOBAL STATUS LIKE 'wsrep_incoming_addresses'")['Value'] ?? '');
    if (trim($wsrep) !== '' && $wsrep !== 'AUTO') {
        $out['type'] = 'galera';
        foreach (explode(',', $wsrep) as $addr) {
            [$h, $p] = split_addr($addr, (int) $bc['port']);
            $out['nodes'][] = ['host' => $h, 'port' => $p, 'role' => 'Galera-Knoten'];
        }
        return $out;
    }

    try {
        $members = q($b, 'SELECT MEMBER_HOST AS host, MEMBER_PORT AS port, MEMBER_ROLE AS role FROM performance_schema.replication_group_members WHERE MEMBER_HOST <> \'\'');
    } catch (mysqli_sql_exception) {
        $members = []; // MariaDB kennt Group Replication nicht
    }
    if ($members) {
        $out['type'] = 'group_replication';
        foreach ($members as $m) {
            $out['nodes'][] = ['host' => $m['host'], 'port' => (int) $m['port'], 'role' => $m['role'] === 'PRIMARY' ? 'Primary' : 'Secondary'];
        }
        return $out;
    }

    $replicas = [];
    foreach (['SHOW REPLICA HOSTS', 'SHOW SLAVE HOSTS'] as $sql) {
        try {
            $replicas = q($b, $sql);
            break;
        } catch (mysqli_sql_exception) {
        }
    }
    $nodes = [];
    foreach ($replicas as $r) {
        if (($r['Host'] ?? '') !== '') {
            $nodes[] = ['host' => $r['Host'], 'port' => (int) ($r['Port'] ?? 0) ?: (int) $bc['port'], 'role' => 'Replikat'];
        }
    }
    // Replikate ohne report_host: nur die IP aus der Prozessliste, Port wie beim Primary angenommen
    foreach (q($b, "SELECT HOST FROM information_schema.PROCESSLIST WHERE COMMAND LIKE 'Binlog Dump%'") as $r) {
        [$ip] = split_addr((string) $r['HOST'], 0);
        if (!array_filter($nodes, fn ($n) => in_array($ip, host_ips($n['host']), true))) {
            $nodes[] = ['host' => $ip, 'port' => (int) $bc['port'], 'role' => 'Replikat (Port angenommen)'];
        }
    }
    if ($nodes) {
        $out['type'] = 'replication';
        $out['nodes'] = array_merge([['host' => $bc['host'], 'port' => (int) $bc['port'], 'role' => 'Primary']], $nodes);
    }
    return $out;
}

const CLUSTER_TYPES = ['galera' => 'Galera', 'group_replication' => 'Group Replication', 'replication' => 'Replikation'];
const CLUSTER_HG_TABLES = ['galera' => 'mysql_galera_hostgroups', 'group_replication' => 'mysql_group_replication_hostgroups'];

/**
 * Abgleich Cluster ↔ mysql_servers.
 * type, nodes (+ hostgroups, in denen der Knoten eingetragen ist), new, gone (eingetragen, aber nicht mehr im Cluster),
 * writer_hg (Hostgroup des Backends), target (Hostgroup für neue Knoten), setup (Replikations-Hostgroup muss erst angelegt werden),
 * suggest_reader, hint (warum nichts aufgenommen werden kann)
 */
function cluster_plan(): array
{
    static $plan = null;
    if ($plan !== null) {
        return $plan;
    }
    $plan = cluster_discover() + ['new' => [], 'gone' => [], 'writer_hg' => null, 'target' => null, 'setup' => false, 'suggest_reader' => null, 'hint' => null];
    if (!$plan['type']) {
        return $plan;
    }
    $servers = q(admin(), 'SELECT hostgroup_id, hostname, port, comment FROM mysql_servers ORDER BY hostgroup_id');
    foreach ($plan['nodes'] as &$n) {
        $n['hostgroups'] = [];
        foreach ($servers as $s) {
            if (same_node($n['host'], $n['port'], $s['hostname'], (int) $s['port'])) {
                $n['hostgroups'][] = (int) $s['hostgroup_id'];
            }
        }
        if (!$n['hostgroups']) {
            $plan['new'][] = $n;
        }
    }
    unset($n);

    $bc = cfg('backend');
    foreach ($servers as $s) {
        if (same_node($s['hostname'], (int) $s['port'], $bc['host'], (int) $bc['port'])) {
            $plan['writer_hg'] = (int) $s['hostgroup_id'];
            break;
        }
    }
    if ($plan['writer_hg'] === null) {
        $plan['hint'] = 'Das Backend ' . $bc['host'] . ':' . $bc['port'] . ' ist in ProxySQL nicht als Server eingetragen.';
        return $plan;
    }
    $w = $plan['writer_hg'];

    // Hostgroups des Clusters und Ziel für neue Knoten
    if ($plan['type'] === 'replication') {
        $rhg = q1(admin(), "SELECT writer_hostgroup, reader_hostgroup FROM mysql_replication_hostgroups WHERE $w IN (writer_hostgroup, reader_hostgroup)");
        if ($rhg) {
            $plan['target'] = (int) $rhg['reader_hostgroup'];
            $clusterHgs = [(int) $rhg['writer_hostgroup'], (int) $rhg['reader_hostgroup']];
        } else {
            $plan['setup'] = true;
            $used = array_map('intval', array_merge(array_column($servers, 'hostgroup_id'), array_keys(hostgroups())));
            $plan['suggest_reader'] = max($used) + 1;
            $clusterHgs = [$w];
        }
    } else {
        $t = CLUSTER_HG_TABLES[$plan['type']];
        $g = in_array($t, admin_tables(), true)
            ? q1(admin(), "SELECT * FROM $t WHERE $w IN (writer_hostgroup, backup_writer_hostgroup, reader_hostgroup, offline_hostgroup)") : null;
        if (!$g) {
            $plan['hint'] = CLUSTER_TYPES[$plan['type']] . "-Cluster erkannt, aber für Hostgroup $w ist in $t nichts eingetragen (Tabellen → " . CLUSTER_TYPES[$plan['type']] . ').';
            return $plan;
        }
        $plan['target'] = (int) $g['writer_hostgroup'];
        $clusterHgs = array_map('intval', [$g['writer_hostgroup'], $g['backup_writer_hostgroup'], $g['reader_hostgroup'], $g['offline_hostgroup']]);
    }

    foreach ($servers as $s) {
        if (in_array((int) $s['hostgroup_id'], $clusterHgs, true)
            && !array_filter($plan['nodes'], fn ($n) => same_node($n['host'], $n['port'], $s['hostname'], (int) $s['port']))) {
            $plan['gone'][] = $s;
        }
    }
    return $plan;
}

/**
 * Neue Knoten aufnehmen. Ohne Replikations-Hostgroup wird sie nur mit $readerHg (Bestätigung in der GUI) angelegt –
 * automatisch nie, weil sich dadurch das Routing ändert.
 */
function cluster_add(?int $readerHg = null): string
{
    $plan = cluster_plan();
    if (!$plan['new']) {
        return 'Keine neuen Knoten.';
    }
    if ($plan['hint']) {
        throw new RuntimeException($plan['hint']);
    }
    $target = $plan['target'];
    $done = [];
    if ($plan['setup']) {
        if ($readerHg === null) {
            throw new RuntimeException('Für das Replikat fehlt eine Replikations-Hostgroup – bitte in der GUI unter Server → Cluster bestätigen.');
        }
        if ($readerHg === $plan['writer_hg']) {
            throw new InvalidArgumentException('Reader- und Writer-Hostgroup müssen verschieden sein');
        }
        run(admin(), "INSERT INTO mysql_replication_hostgroups (writer_hostgroup, reader_hostgroup, check_type, comment)
                      VALUES ({$plan['writer_hg']}, $readerHg, 'read_only', 'von der GUI angelegt')");
        $done[] = "Replikations-Hostgroup {$plan['writer_hg']} (Writer) → $readerHg (Reader) angelegt";
        $target = $readerHg;
    }
    foreach ($plan['new'] as $n) {
        run(admin(), 'INSERT INTO mysql_servers (hostgroup_id, hostname, port, comment) VALUES ('
            . $target . ', ' . aq($n['host']) . ', ' . (int) $n['port'] . ', ' . aq('Cluster-Erkennung: ' . $n['role']) . ')');
        $done[] = "{$n['host']}:{$n['port']} → HG $target";
    }
    $done[] = apply_module('servers');
    return implode(' · ', $done);
}

/** Automatischer Lauf (bin/sync.php): Meldungen der eingeschalteten Teile, leere Teile fehlen */
function run_auto_sync(): array
{
    $parts = auto_sync();
    $log = [];
    $accountParts = array_values(array_filter(['users' => 'import', 'passwords' => 'passwords'], fn ($p) => in_array($p, $parts, true), ARRAY_FILTER_USE_KEY));
    if ($accountParts) {
        if (!backend()) {
            throw new RuntimeException('Backend nicht erreichbar: ' . backend_error());
        }
        // übersprungene Konten zeigt die GUI dauerhaft – im Log nur zusammen mit Änderungen, sonst jede Minute dieselbe Zeile
        $msg = account_sync($accountParts, sync_hostgroup());
        if (!str_starts_with($msg, 'Nichts zu tun')) {
            $log[] = "Konten: $msg";
        }
    }
    if (in_array('cluster', $parts, true)) {
        $plan = cluster_plan();
        if ($plan['new'] && !$plan['setup'] && !$plan['hint']) {
            $log[] = 'Cluster: ' . cluster_add();
        } elseif ($plan['new']) {
            $log[] = 'Cluster: ' . count($plan['new']) . ' neue(r) Knoten, aber ' . ($plan['hint'] ?? 'keine Replikations-Hostgroup – bitte in der GUI bestätigen');
        }
    }
    return $log;
}
