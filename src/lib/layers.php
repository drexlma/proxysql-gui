<?php
// Konfigurationsebenen vergleichen: Speicher (main), Laufzeit (runtime_*) und Disk (disk.*)

/** Tabellen je Modul (siehe MODULES); "global_variables:präfix" vergleicht nur diese Variablen */
const MODULE_TABLES = [
    'servers'   => ['mysql_servers', 'mysql_replication_hostgroups', 'mysql_galera_hostgroups', 'mysql_group_replication_hostgroups',
                    'mysql_aws_aurora_hostgroups', 'mysql_aws_rds_bgd_hostgroups', 'mysql_hostgroup_attributes', 'mysql_servers_ssl_params'],
    'users'     => ['mysql_users'],
    'rules'     => ['mysql_query_rules', 'mysql_query_rules_fast_routing'],
    'mysqlvars' => ['global_variables:mysql-'],
    'adminvars' => ['global_variables:admin-'],
    'pgsqlvars' => ['global_variables:pgsql-'],
    'scheduler' => ['scheduler'],
    'cluster'   => ['proxysql_servers'],
    'firewall'  => ['mysql_firewall_whitelist_users', 'mysql_firewall_whitelist_rules', 'mysql_firewall_whitelist_sqli_fingerprints'],
];

const LAYERS = ['memory' => 'Speicher', 'runtime' => 'Laufzeit', 'disk' => 'Disk'];

function is_secret(string $name): bool
{
    return (bool) preg_match('/password|credentials|secret/i', $name);
}

/** Wert verbergen? (Passwort-Spalten und geheime Variablen) */
function is_masked(array $row, string $col): bool
{
    return is_secret($col) || ($col === 'variable_value' && is_secret((string) ($row['variable_name'] ?? '')));
}

/** Tabellen, die es im Admin-Interface dieser ProxySQL-Version gibt */
function admin_tables(): array
{
    static $t = null;
    return $t ??= array_map(fn ($r) => (string) reset($r), q(admin(), 'SHOW TABLES'));
}

/** Spalten: name => [type, notnull, default, pk] */
function table_columns(string $table): array
{
    static $cache = [];
    if (!in_array($table, admin_tables(), true)) {
        throw new InvalidArgumentException("Unbekannte Tabelle: $table");
    }
    if (!isset($cache[$table])) {
        $cache[$table] = [];
        foreach (q(admin(), "PRAGMA table_info($table)") as $c) {
            $cache[$table][$c['name']] = ['type' => strtoupper((string) $c['type']), 'notnull' => (int) $c['notnull'] === 1, 'default' => $c['dflt_value'], 'pk' => (int) $c['pk']];
        }
    }
    return $cache[$table];
}

/** Schlüsselspalten: Primärschlüssel, sonst alle Spalten */
function table_key(string $table): array
{
    $pk = array_filter(table_columns($table), fn ($c) => $c['pk'] > 0);
    uasort($pk, fn ($a, $b) => $a['pk'] <=> $b['pk']);
    return array_keys($pk ?: table_columns($table));
}

/** Modul, das eine Tabelle aktiviert (LOAD … TO RUNTIME) */
function table_module(string $table): ?string
{
    foreach (MODULE_TABLES as $mod => $specs) {
        if (in_array($table, $specs, true)) {
            return $mod;
        }
    }
    return null;
}

/** Tabellen eines Moduls, die es in dieser Version gibt */
function module_specs(string $module): array
{
    return array_values(array_filter(MODULE_TABLES[$module] ?? [], fn ($s) => in_array(explode(':', $s)[0], admin_tables(), true)));
}

/**
 * Zeilen einer Tabelle in einer Ebene, nach Schlüssel (JSON {spalte: wert}); null, wenn die Ebene nicht lesbar ist.
 * Mit $vsRuntime fallen Unterschiede weg, die ProxySQL zur Laufzeit selbst erzeugt.
 */
function layer_rows(string $spec, string $layer, bool $vsRuntime): ?array
{
    static $cache = [];
    $ck = "$spec|$layer|" . (int) $vsRuntime;
    if (array_key_exists($ck, $cache)) {
        return $cache[$ck];
    }
    [$table, $prefix] = array_pad(explode(':', $spec, 2), 2, '');
    $src = ['memory' => $table, 'runtime' => "runtime_$table", 'disk' => "disk.$table"][$layer];
    $key = table_key($table);
    $drop = [];
    // admin-version ist keine Einstellung, sondern die laufende Version – nach jedem Update stünde sonst "nicht gespeichert" da
    $where = $prefix !== '' ? ' WHERE variable_name LIKE ' . aq($prefix . '%') . " AND variable_name <> 'admin-version'" : '';
    if ($table === 'mysql_users') {
        // zur Laufzeit steht jedes Konto zweimal drin (frontend = 1 und backend = 1)
        $key = ['username'];
        $drop = ['frontend', 'backend'];
    }
    if ($table === 'mysql_servers' && $vsRuntime) {
        // Status und Server in Replikations-/Galera-Hostgroups setzt ProxySQL selbst
        $drop[] = 'status';
        if ($managed = managed_hostgroups()) {
            $where = ' WHERE hostgroup_id NOT IN (' . implode(',', $managed) . ')';
        }
    }
    try {
        $res = q(admin(), "SELECT * FROM $src$where");
    } catch (mysqli_sql_exception) {
        return $cache[$ck] = null;
    }
    $rows = [];
    foreach ($res as $r) {
        $r = array_diff_key($r, array_flip($drop));
        if ($table === 'mysql_users') {
            $r['default_schema'] ??= ''; // zur Laufzeit wird aus NULL ein Leerstring
        }
        if ($table === 'mysql_query_rules' && $r['digest'] !== null) {
            $r['digest'] = digest_norm($r['digest']);
        }
        $rows[json_encode(array_intersect_key($r, array_flip($key)))] = $r;
    }
    return $cache[$ck] = $rows;
}

/**
 * Unterschiede einer Tabelle zwischen Speicher und $other (runtime|disk).
 * Eintrag: key ([spalte => wert]), kind (new = nur im Speicher, deleted = nur in $other, changed), mem, other, cols (geänderte Spalten)
 */
function layer_diff(string $spec, string $other): array
{
    $mem = layer_rows($spec, 'memory', $other === 'runtime');
    $oth = layer_rows($spec, $other, $other === 'runtime');
    if ($mem === null || $oth === null) {
        return [];
    }
    $out = [];
    foreach (array_keys($mem + $oth) as $k) {
        $a = $mem[$k] ?? null;
        $b = $oth[$k] ?? null;
        $cols = $a && $b ? array_keys(array_filter($a, fn ($v, $c) => ($b[$c] ?? null) !== $v, ARRAY_FILTER_USE_BOTH)) : [];
        if ($a && $b && !$cols) {
            continue;
        }
        $out[] = ['key' => json_decode($k, true), 'kind' => !$b ? 'new' : (!$a ? 'deleted' : 'changed'), 'mem' => $a, 'other' => $b, 'cols' => $cols];
    }
    return $out;
}

/** Unterschiede aller Tabellen eines Moduls: [spec => Einträge] (nur Tabellen mit Unterschieden) */
function module_diff(string $module, string $other): array
{
    $out = [];
    foreach (module_specs($module) as $spec) {
        if ($d = layer_diff($spec, $other)) {
            $out[$spec] = $d;
        }
    }
    return $out;
}

/**
 * Je Modul: runtime = Zeilen, die im Speicher anders sind als zur Laufzeit (nicht aktiviert),
 * disk = Zeilen, die anders sind als auf Disk (nicht gespeichert)
 */
function config_state(): array
{
    static $state = null;
    if ($state !== null) {
        return $state;
    }
    $state = [];
    foreach (array_keys(MODULES) as $mod) {
        if (module_specs($mod)) {
            $count = fn (string $other) => array_sum(array_map('count', module_diff($mod, $other)));
            $state[$mod] = ['runtime' => $count('runtime'), 'disk' => $count('disk')];
        }
    }
    return $state;
}
