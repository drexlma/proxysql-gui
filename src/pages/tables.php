<?php
// Weitere Konfigurationstabellen: Scheduler, ProxySQL-Cluster, Cluster-Hostgroups, Firewall …
// Die Formulare entstehen aus dem Tabellenschema; erlaubte Werte kommen aus den CHECK-Bedingungen.

const EXTRA_TABLES = [
    'Betrieb' => [
        'scheduler'        => ['Scheduler', 'Skripte, die ProxySQL in festen Abständen startet – z. B. eigene Health-Checks oder Failover-Skripte. Die Skripte müssen auf dem ProxySQL-Host liegen.'],
        'proxysql_servers' => ['ProxySQL-Cluster', 'Andere ProxySQL-Instanzen, mit denen diese Instanz ihre Konfiguration (Server, Konten, Regeln, Variablen) automatisch abgleicht.'],
    ],
    'Cluster-Hostgroups' => [
        'mysql_galera_hostgroups'            => ['Galera / PXC', 'Writer-, Backup-Writer-, Reader- und Offline-Hostgroup für Galera bzw. Percona XtraDB Cluster. ProxySQL verschiebt die Knoten anhand ihres Zustands.'],
        'mysql_group_replication_hostgroups' => ['Group Replication', 'Wie Galera, für MySQL Group Replication bzw. InnoDB Cluster.'],
        'mysql_aws_aurora_hostgroups'        => ['AWS Aurora', 'Aurora-Cluster: ProxySQL erkennt Writer und Reader über die Domain selbst.'],
        'mysql_aws_rds_bgd_hostgroups'       => ['AWS RDS Blue/Green', 'Hostgroups für RDS Blue/Green-Deployments.'],
    ],
    'Hostgroups & Server' => [
        'mysql_hostgroup_attributes' => ['Hostgroup-Attribute', 'Einstellungen je Hostgroup: maximale Zahl aktiver Server, Multiplexing, init_connect, Standardwerte für Server (JSON) …'],
        'mysql_servers_ssl_params'   => ['SSL je Server', 'Zertifikate, Chiffren und TLS-Version für einzelne Backend-Server.'],
    ],
    'Routing & Firewall' => [
        'mysql_query_rules_fast_routing'             => ['Fast Routing', 'Routing nach Benutzer, Schema und flagIN ohne Regex – schnell auch bei Tausenden Schemas. Wird nach den Query-Regeln ausgewertet.'],
        'mysql_firewall_whitelist_users'             => ['Firewall: Konten', 'Für welche Konten und Clients die Firewall gilt: OFF, DETECTING (nur protokollieren) oder PROTECTING (blockieren). Braucht mysql-firewall_whitelist_enabled = 1.'],
        'mysql_firewall_whitelist_rules'             => ['Firewall: erlaubte Queries', 'Erlaubte Query-Digests je Konto und Client.'],
        'mysql_firewall_whitelist_sqli_fingerprints' => ['Firewall: SQLi-Ausnahmen', 'Fingerprints, die trotz SQL-Injection-Erkennung durchgelassen werden.'],
    ],
];

/** Erlaubte Werte aus "CHECK (spalte IN (…))" */
function check_options(string $table): array
{
    $sql = (string) (q1(admin(), "SHOW CREATE TABLE $table")['Create Table'] ?? '');
    preg_match_all('/^\s*(\w+)\s[^\n]*?CHECK\s*\(\s*\1\s+IN\s*\(([^)]*)\)\s*\)/mi', $sql, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as [, $col, $list]) {
        $vals = array_map(fn ($v) => trim(trim($v), "'"), explode(',', $list));
        $out[$col] = array_combine($vals, $vals);
    }
    return $out;
}

/** Standardwert aus dem Schema für die Anzeige ("('OFF')" → OFF) */
function default_text(?string $d): string
{
    return $d === null ? '' : trim($d, "()'");
}

/** Bedingung für genau eine Zeile anhand der Schlüsselspalten */
function key_where(string $table, array $key): string
{
    $cols = table_columns($table);
    $parts = [];
    foreach (table_key($table) as $col) {
        if (!array_key_exists($col, $key)) {
            throw new InvalidArgumentException('Zeile nicht eindeutig bestimmt');
        }
        $v = $key[$col];
        $parts[] = $v === null ? "$col IS NULL" : "$col = " . (str_contains($cols[$col]['type'], 'INT') ? aint($v, false) : aq((string) $v));
    }
    return implode(' AND ', $parts);
}

function row_key(string $table, array $row): array
{
    return array_intersect_key($row, array_flip(table_key($table)));
}

/** Formularwerte → SQL-Literale. Beim Anlegen fehlen leere Spalten mit Standardwert (die setzt ProxySQL). */
function table_values(string $table, bool $insert): array
{
    $out = [];
    foreach (table_columns($table) as $col => $c) {
        $raw = $_POST['c'][$col] ?? '';
        $raw = is_string($raw) ? trim($raw) : '';
        $int = str_contains($c['type'], 'INT');
        if ($raw !== '') {
            $out[$col] = $int ? aint($raw, false) : aq($raw);
        } elseif ($insert && ($c['default'] !== null || ($c['pk'] && $int))) {
            continue;
        } elseif (!$c['notnull']) {
            $out[$col] = 'NULL';
        } elseif ($c['default'] !== null) {
            $out[$col] = $c['default'];
        } elseif (!$int && !$c['pk']) {
            $out[$col] = "''";
        } else {
            throw new InvalidArgumentException("$col darf nicht leer sein");
        }
    }
    return $out;
}

$available = [];
foreach (EXTRA_TABLES as $group => $tables) {
    foreach ($tables as $t => $info) {
        if (in_array($t, admin_tables(), true)) {
            $available[$t] = $info + [2 => $group];
        }
    }
}
$table = (string) ($_GET['t'] ?? '');
if (!isset($available[$table])) {
    $table = (string) array_key_first($available);
}
$module = table_module($table);

handle_post(function (string $action) use ($table, $module) {
    $key = json_decode(post_raw('key'), true);
    switch ($action) {
        case 'save_row':
            $vals = table_values($table, !is_array($key));
            if (!is_array($key)) {
                run(admin(), $vals ? "INSERT INTO $table (" . implode(', ', array_keys($vals)) . ') VALUES (' . implode(', ', $vals) . ')' : "INSERT INTO $table DEFAULT VALUES");
                $msg = 'Zeile angelegt.';
            } else {
                run(admin(), "UPDATE $table SET " . sql_assign($vals) . ' WHERE ' . key_where($table, $key));
                $msg = 'Zeile gespeichert.';
            }
            flash('ok', "$msg " . apply_module($module));
            return url(['page' => 'tables', 't' => $table]);

        case 'delete_row':
            run(admin(), "DELETE FROM $table WHERE " . key_where($table, is_array($key) ? $key : []));
            flash('ok', 'Zeile gelöscht. ' . apply_module($module));
            return null;
    }
    throw new InvalidArgumentException('Unbekannte Aktion');
});

$cols = table_columns($table);
$opts = check_options($table);
$rows = q(admin(), "SELECT * FROM $table");

// Zeilen, die im Speicher anders sind als zur Laufzeit
$pending = [];
$onlyRuntime = [];
foreach (layer_diff($table, 'runtime') as $e) {
    if ($e['kind'] === 'deleted') {
        $onlyRuntime[] = $e['other'];
    } else {
        $pending[json_encode($e['key'])] = true;
    }
}

$editKey = json_decode((string) ($_GET['edit'] ?? ''), true);
$edit = is_array($editKey) ? q1(admin(), "SELECT * FROM $table WHERE " . key_where($table, $editKey)) : null;
$form = old('c', null) ?? $edit ?? [];

[$label, $desc] = $available[$table];
layout_start('Tabellen', 'tables', ['title' => $label, 'sub' => $table . ($module ? ' · aktiviert mit LOAD ' . MODULES[$module] . ' TO RUNTIME' : ''),
    'tools' => $module ? module_tools($module) : '']);
?>
<div class="tables-layout">
  <nav class="panel side-list">
    <?php foreach (EXTRA_TABLES as $group => $tables): ?>
      <div class="side-group"><?= h($group) ?></div>
      <?php foreach ($tables as $t => [$l]): if (!isset($available[$t])) continue ?>
        <a href="<?= h(url(['page' => 'tables', 't' => $t])) ?>"<?= $t === $table ? ' class="active"' : '' ?>>
          <?= h($l) ?> <span class="count"><?= nf(qv(admin(), "SELECT COUNT(*) FROM $t")) ?></span>
        </a>
      <?php endforeach ?>
    <?php endforeach ?>
  </nav>
  <div>
<?php
panel_start(count($rows) . (count($rows) === 1 ? ' Eintrag' : ' Einträge'), ['flush' => true, 'tools' => '<a class="btn btn-sm btn-primary" href="' . h(url(['page' => 'tables', 't' => $table])) . '#row-form">' . icon('plus') . ' Neu</a>']);
echo '<p class="pad muted">' . h($desc) . '</p>';
$format = [];
foreach ($cols as $col => $c) {
    $format[$col] = fn ($v, $r) => is_masked($r, $col) ? '•••' : ($v === null ? '<span class="null">NULL</span>' : '<span title="' . h($v) . '">' . h(mb_strimwidth($v, 0, 60, '…')) . '</span>');
}
$format['_st'] = fn ($v, $r) => isset($pending[json_encode(row_key($table, $r))]) ? badge('nicht aktiv', 'warn') : badge('aktiv', 'ok');
$format['_a'] = fn ($v, $r) => '<span class="nowrap"><a class="btn btn-sm" href="' . h(url(['page' => 'tables', 't' => $table, 'edit' => json_encode(row_key($table, $r))])) . '#row-form">' . icon('edit') . '</a> '
    . action_form('delete_row', ['key' => json_encode(row_key($table, $r))], icon('trash'), ['confirm' => 'Zeile aus ' . $table . ' löschen?', 'btn' => 'btn-sm btn-danger']) . '</span>';
echo data_table($rows, [
    // Aktionen vorne – breite Tabellen scrollen sonst die Buttons aus dem Bild
    'cols'   => ['_a' => '', '_st' => 'Laufzeit'] + array_combine(array_keys($cols), array_keys($cols)),
    'format' => $format,
    'empty'  => 'Keine Einträge.',
    'class'  => 'mono-cells',
]);
if ($onlyRuntime) {
    echo '<p class="pad">' . badge(count($onlyRuntime) . ' gelöscht, aber noch aktiv', 'warn') . ' <a href="' . h(url(['page' => 'diff', 'module' => $module])) . '#runtime">Details</a></p>';
}
panel_end();

panel_start($edit ? 'Eintrag bearbeiten' : 'Neuer Eintrag', ['id' => 'row-form']);
echo form_open('save_row') . hidden('key', $edit ? json_encode(row_key($table, $edit)) : '');
?>
  <div class="form-grid">
  <?php foreach ($cols as $col => $c):
      $int = str_contains($c['type'], 'INT');
      $value = array_key_exists($col, $form) ? $form[$col] : null;
      $required = $c['notnull'] && $c['default'] === null && !($int && $c['pk']);
      $def = default_text($c['default']);
      $name = 'c[' . $col . ']';
      $wide = preg_match('/settings|defaults|ignore_session_variables|comment|filename|init_connect/', $col); ?>
    <div class="field<?= $wide ? ' span2' : '' ?>">
      <label class="mono"><?= h($col) ?><?= $c['pk'] ? ' ' . badge('Schlüssel', 'off') : '' ?></label>
      <?php if (isset($opts[$col])): ?>
        <select name="<?= h($name) ?>"><?= options(($c['notnull'] ? [] : ['' => 'NULL']) + $opts[$col], $value ?? $def) ?></select>
      <?php elseif (preg_match('/settings|defaults|ignore_session_variables/', $col)): ?>
        <textarea name="<?= h($name) ?>" rows="2" class="mono" placeholder="JSON, z. B. {}"><?= h($value) ?></textarea>
      <?php else: ?>
        <input name="<?= h($name) ?>" type="<?= $int ? 'number' : 'text' ?>" value="<?= h($value) ?>"<?= $required ? ' required' : '' ?>
          placeholder="<?= h($def !== '' ? 'Standard: ' . $def : ($int && $c['pk'] ? 'automatisch' : ($c['notnull'] ? '' : 'leer = NULL'))) ?>">
      <?php endif ?>
    </div>
  <?php endforeach ?>
  </div>
  <div class="form-actions">
    <?= submit_button(icon('check') . ' Speichern und aktivieren') ?>
    <?php if ($edit): ?><a class="btn" href="<?= h(url(['page' => 'tables', 't' => $table])) ?>">Abbrechen</a><?php endif ?>
  </div>
</form>
<?php
panel_end();
?>
  </div>
</div>
<?php
layout_end();
