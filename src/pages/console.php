<?php
// SQL-Konsole für das ProxySQL-Admin-Interface und das Backend

const CONSOLE_SNIPPETS = [
    'proxysql' => [
        'Prozessliste'          => 'SELECT * FROM stats_mysql_processlist',
        'Connection-Pool'       => 'SELECT * FROM stats_mysql_connection_pool',
        'Server (Laufzeit)'     => 'SELECT * FROM runtime_mysql_servers',
        'Monitor-Ping'          => 'SELECT * FROM monitor.mysql_server_ping_log ORDER BY time_start_us DESC LIMIT 20',
        'Befehlsstatistik'      => 'SELECT * FROM stats_mysql_commands_counters WHERE Total_cnt > 0',
        'Fehler je Server'      => 'SELECT * FROM stats_mysql_errors ORDER BY last_seen DESC',
        'Konten aktivieren'     => "LOAD MYSQL USERS TO RUNTIME;\nSAVE MYSQL USERS TO DISK",
        'Hashes sichern'        => "SAVE MYSQL USERS FROM RUNTIME;\nSAVE MYSQL USERS TO DISK",
    ],
    'backend' => [
        'Prozessliste'      => 'SHOW FULL PROCESSLIST',
        'Konten'            => 'SELECT User, Host, plugin, account_locked FROM mysql.user ORDER BY User',
        'Verbindungen'      => "SHOW GLOBAL STATUS LIKE 'Threads%'",
        'Datenbankgrößen'   => 'SELECT table_schema, ROUND(SUM(data_length + index_length) / 1048576, 1) AS mb FROM information_schema.tables GROUP BY table_schema',
    ],
];

/** Statements an ; trennen (außerhalb von Strings und Kommentaren) */
function split_sql(string $sql): array
{
    $out = [];
    $buf = '';
    $quote = null;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($quote !== null) {
            $buf .= $c;
            if ($c === '\\' && $quote !== '`' && $i + 1 < $len) {
                $buf .= $sql[++$i];
            } elseif ($c === $quote) {
                $quote = null;
            }
        } elseif ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c;
            $buf .= $c;
        } elseif ($c === '-' && substr($sql, $i, 3) === '-- ' || $c === '#') {
            $nl = strpos($sql, "\n", $i);
            $i = $nl === false ? $len : $nl;
            $buf .= "\n";
        } elseif ($c === ';') {
            $out[] = trim($buf);
            $buf = '';
        } else {
            $buf .= $c;
        }
    }
    $out[] = trim($buf);
    return array_values(array_filter($out, fn ($s) => $s !== ''));
}

function is_read_statement(string $sql): bool
{
    return preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|CHECKSUM)\b/i', $sql) === 1 && stripos($sql, '_reset') === false;
}

$target = (($_POST['target'] ?? $_GET['target'] ?? 'proxysql') === 'backend') ? 'backend' : 'proxysql';
$sql = is_post() ? (string) ($_POST['sql'] ?? '') : (string) ($_GET['sql'] ?? '');
$results = [];

if (is_post() && is_admin() && trim($sql) !== '') {
    $conn = $target === 'backend' ? backend() : admin();
    if (!$conn) {
        $results[] = ['sql' => '', 'error' => 'Backend nicht verbunden'];
    } else {
        if ($target === 'backend' && ($db = trim((string) ($_POST['db'] ?? ''))) !== '') {
            $conn->select_db($db);
        }
        foreach (split_sql($sql) as $stmt) {
            if (!can_write() && !is_read_statement($stmt)) {
                $results[] = ['sql' => $stmt, 'error' => 'Nur-Lesen-Modus: erlaubt sind SELECT, SHOW, DESCRIBE, EXPLAIN, CHECKSUM.'];
                continue;
            }
            $t0 = microtime(true);
            try {
                $res = $conn->query($stmt);
                $ms = (microtime(true) - $t0) * 1000;
                if ($res instanceof mysqli_result) {
                    $results[] = ['sql' => $stmt, 'fields' => array_column($res->fetch_fields(), 'name'), 'rows' => $res->fetch_all(MYSQLI_NUM), 'ms' => $ms];
                    $res->free();
                } else {
                    $results[] = ['sql' => $stmt, 'affected' => $conn->affected_rows, 'ms' => $ms];
                }
            } catch (mysqli_sql_exception $e) {
                $results[] = ['sql' => $stmt, 'error' => $e->getMessage()];
                break;
            }
        }
    }
    $hist = array_values(array_filter($_SESSION['console_history'] ?? [], fn ($h) => $h !== [$target, $sql]));
    array_unshift($hist, [$target, $sql]);
    $_SESSION['console_history'] = array_slice($hist, 0, 15);
}

layout_start('SQL-Konsole', 'console', ['title' => 'SQL-Konsole', 'sub' => $target === 'backend' ? 'Backend ' . cfg('backend')['host'] : 'ProxySQL Admin-Interface']);

if (!is_admin()) {
    // über die Konsole ließen sich Passwörter auslesen – daher nur mit Admin-Login
    echo '<div class="msg msg-warn">' . icon('warning') . '<span>' . (auth_enabled() ? 'Die SQL-Konsole ist nur mit dem Admin-Login verfügbar.' : 'Die Konsole ist nur mit Login verfügbar (GUI_PASSWORD setzen).') . '</span></div>';
    layout_end();
    return;
}
?>
<div class="console-layout">
<div>
<?php panel_start('Abfrage') ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <div class="toolbar">
      <label>Ziel <select name="target" id="console-target">
        <?= options(['proxysql' => 'ProxySQL Admin (' . cfg('proxysql')['host'] . ')'] + (backend_configured() ? ['backend' => 'Backend (' . cfg('backend')['host'] . ')'] : []), $target) ?>
      </select></label>
      <?php if ($target === 'backend' && backend()): ?>
        <label>Datenbank <select name="db"><?= options(['' => '–'] + array_combine(backend_schemas(), backend_schemas()), $_POST['db'] ?? '') ?></select></label>
      <?php endif ?>
      <?php if (!can_write()): ?><?= badge('nur lesende Statements', 'warn') ?><?php endif ?>
    </div>
    <textarea name="sql" id="console-sql" rows="8" spellcheck="false" placeholder="SELECT * FROM stats_mysql_global"><?= h($sql) ?></textarea>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Ausführen <span class="muted-inv">(Strg+Enter)</span></button>
      <span class="muted">Mehrere Statements mit ; trennen. Im Admin-Interface gilt SQLite-Syntax.</span>
    </div>
  </form>
<?php panel_end() ?>

<?php foreach ($results as $r): ?>
  <?php panel_start($r['sql'] === '' ? 'Ergebnis' : mb_strimwidth($r['sql'], 0, 120, '…'), ['flush' => true]) ?>
    <?php if (isset($r['error'])): ?>
      <div class="msg msg-error flat"><?= icon('error') ?><span><?= h($r['error']) ?></span></div>
    <?php elseif (isset($r['fields'])): ?>
      <div class="result-meta"><?= nf(count($r['rows'])) ?> Zeilen · <?= nf($r['ms'], 1) ?> ms<?= count($r['rows']) > 1000 ? ' · angezeigt: 1.000' : '' ?></div>
      <div class="table-wrap result">
        <table class="grid">
          <thead><tr><?php foreach ($r['fields'] as $fname): ?><th><?= h($fname) ?></th><?php endforeach ?></tr></thead>
          <tbody>
          <?php foreach (array_slice($r['rows'], 0, 1000) as $row): ?>
            <tr><?php foreach ($row as $v): ?><td class="mono"><?= $v === null ? '<span class="null">NULL</span>' : h($v) ?></td><?php endforeach ?></tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="msg msg-ok flat"><?= icon('ok') ?><span>OK · <?= nf($r['affected']) ?> Zeilen betroffen · <?= nf($r['ms'], 1) ?> ms</span></div>
    <?php endif ?>
  <?php panel_end() ?>
<?php endforeach ?>
</div>

<div>
<?php panel_start('Vorlagen') ?>
  <?php foreach (CONSOLE_SNIPPETS as $t => $snips): ?>
    <h3 class="sub-head"><?= $t === 'backend' ? 'Backend' : 'ProxySQL' ?></h3>
    <ul class="link-list">
      <?php foreach ($snips as $label => $snip): ?>
        <li><a href="#" data-fill="<?= h($snip) ?>" data-fill-target="<?= h($t) ?>"><?= h($label) ?></a></li>
      <?php endforeach ?>
    </ul>
  <?php endforeach ?>
<?php panel_end() ?>
<?php panel_start('Verlauf') ?>
  <ul class="link-list history">
    <?php foreach ($_SESSION['console_history'] ?? [] as [$t, $s]): ?>
      <li><a href="#" data-fill="<?= h($s) ?>" data-fill-target="<?= h($t) ?>" title="<?= h($s) ?>"><span class="badge badge-off"><?= $t === 'backend' ? 'DB' : 'PS' ?></span> <?= h(mb_strimwidth(preg_replace('/\s+/', ' ', $s), 0, 60, '…')) ?></a></li>
    <?php endforeach ?>
    <?php if (empty($_SESSION['console_history'])): ?><li class="muted">Noch nichts ausgeführt</li><?php endif ?>
  </ul>
<?php panel_end() ?>
</div>
</div>
<?php
layout_end();
