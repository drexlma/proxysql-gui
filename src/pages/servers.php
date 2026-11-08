<?php
// Backend-Server, Connection-Pool, Replikations-Hostgroups, Monitor-Fehler

const SERVER_STATUS = ['ONLINE', 'OFFLINE_SOFT', 'OFFLINE_HARD'];
const CHECK_TYPES = ['read_only', 'innodb_read_only', 'super_read_only', 'read_only|innodb_read_only', 'read_only&innodb_read_only'];

function server_where(string $hg, string $host, string $port): string
{
    return 'hostgroup_id = ' . aint($hg, false) . ' AND hostname = ' . aq($host) . ' AND port = ' . aint($port, false);
}

handle_post(function (string $action) {
    switch ($action) {
        case 'save_server':
            $host = post('hostname');
            if ($host === '') {
                throw new InvalidArgumentException('Hostname fehlt');
            }
            $status = in_array(post('status'), SERVER_STATUS, true) ? post('status') : 'ONLINE';
            $values = [
                'hostgroup_id'        => aint(post('hostgroup_id', '0'), false),
                'hostname'            => aq($host),
                'port'                => aint(post('port', '3306'), false),
                'status'              => aq($status),
                'weight'              => aint(post('weight', '1'), false),
                'max_connections'     => aint(post('max_connections', '1000'), false),
                'max_replication_lag' => aint(post('max_replication_lag', '0'), false),
                'max_latency_ms'      => aint(post('max_latency_ms', '0'), false),
                'use_ssl'             => checked('use_ssl') ? '1' : '0',
                'compression'         => checked('compression') ? '1' : '0',
                'comment'             => aq(post('comment')),
            ];
            if (post('orig_host') !== '') {
                run(admin(), 'UPDATE mysql_servers SET ' . sql_assign($values) . ' WHERE ' . server_where(post('orig_hg'), post('orig_host'), post('orig_port')));
            } else {
                run(admin(), 'INSERT INTO mysql_servers (' . implode(', ', array_keys($values)) . ') VALUES (' . implode(', ', $values) . ')');
            }
            flash('ok', "Server $host gespeichert. " . apply_module('servers'));
            return '?page=servers';

        case 'delete_server':
            run(admin(), 'DELETE FROM mysql_servers WHERE ' . server_where(post('hg'), post('host'), post('port')));
            flash('ok', 'Server ' . post('host') . ':' . post('port') . ' entfernt. ' . apply_module('servers'));
            return null;

        case 'set_status':
            $status = in_array(post('status'), SERVER_STATUS, true) ? post('status') : throw new InvalidArgumentException('Ungültiger Status');
            run(admin(), 'UPDATE mysql_servers SET status = ' . aq($status) . ' WHERE ' . server_where(post('hg'), post('host'), post('port')));
            flash('ok', 'Server ' . post('host') . " ist jetzt $status. " . apply_module('servers'));
            return null;

        case 'save_rhg':
            run(admin(), 'REPLACE INTO mysql_replication_hostgroups (writer_hostgroup, reader_hostgroup, check_type, comment) VALUES ('
                . aint(post('writer'), false) . ', ' . aint(post('reader'), false) . ', ' . aq(post('check_type', 'read_only')) . ', ' . aq(post('comment')) . ')');
            flash('ok', 'Replikations-Hostgroup gespeichert. ' . apply_module('servers'));
            return null;

        case 'delete_rhg':
            run(admin(), 'DELETE FROM mysql_replication_hostgroups WHERE writer_hostgroup = ' . aint(post('writer'), false));
            flash('ok', 'Replikations-Hostgroup entfernt. ' . apply_module('servers'));
            return null;

        case 'cluster_add': // neue Cluster-Knoten aufnehmen (wie proxysql-admin --update-cluster)
            flash('ok', cluster_add(post('reader_hg') !== '' ? (int) aint(post('reader_hg'), false) : null));
            return '?page=servers#cluster';

        case 'cluster_remove': // Knoten, der nicht mehr im Cluster ist, aus allen Hostgroups austragen
            run(admin(), 'DELETE FROM mysql_servers WHERE hostname = ' . aq(post('host')) . ' AND port = ' . aint(post('port'), false));
            flash('ok', 'Server ' . post('host') . ':' . post('port') . ' ausgetragen. ' . apply_module('servers'));
            return '?page=servers#cluster';

        case 'sync_version':
            flash('ok', sync_server_version());
            return null;
    }
    throw new InvalidArgumentException('Unbekannte Aktion');
});

$servers = q(admin(), 'SELECT s.*, r.status AS rt_status, p.ConnUsed, p.ConnFree, p.ConnOK, p.ConnERR, p.MaxConnUsed, p.Queries, p.Latency_us, p.Bytes_data_sent, p.Bytes_data_recv
    FROM mysql_servers s
    LEFT JOIN runtime_mysql_servers r ON r.hostgroup_id = s.hostgroup_id AND r.hostname = s.hostname AND r.port = s.port
    LEFT JOIN stats_mysql_connection_pool p ON p.hostgroup = s.hostgroup_id AND p.srv_host = s.hostname AND p.srv_port = s.port
    ORDER BY s.hostgroup_id, s.hostname, s.port');
$runtimeOnly = q(admin(), 'SELECT r.hostgroup_id, r.hostname, r.port, r.status FROM runtime_mysql_servers r
    LEFT JOIN mysql_servers s ON r.hostgroup_id = s.hostgroup_id AND r.hostname = s.hostname AND r.port = s.port
    WHERE s.hostname IS NULL');
$rhg = q(admin(), 'SELECT * FROM mysql_replication_hostgroups ORDER BY writer_hostgroup');
$errors = q(admin(), "SELECT * FROM (
      SELECT hostname, port, time_start_us, ping_error AS error, 'Ping' AS kind FROM monitor.mysql_server_ping_log WHERE ping_error IS NOT NULL
      UNION ALL
      SELECT hostname, port, time_start_us, connect_error, 'Verbindung' FROM monitor.mysql_server_connect_log WHERE connect_error IS NOT NULL
    ) ORDER BY time_start_us DESC LIMIT 25");

$edit = null;
if (isset($_GET['edit'])) {
    [$ehg, $ehost, $eport] = array_pad(explode('|', (string) $_GET['edit'], 3), 3, '');
    $edit = q1(admin(), 'SELECT * FROM mysql_servers WHERE ' . server_where($ehg ?: '0', $ehost, $eport ?: '0'));
}
$f = $edit ?? ['hostgroup_id' => old('hostgroup_id', 0), 'hostname' => old('hostname'), 'port' => old('port', 3306), 'status' => 'ONLINE', 'weight' => 1,
    'max_connections' => 1000, 'max_replication_lag' => 0, 'max_latency_ms' => 0, 'use_ssl' => 0, 'compression' => 0, 'comment' => old('comment')];

layout_start('Server', 'servers', ['title' => 'Backend-Server', 'sub' => 'mysql_servers · Connection-Pool · Monitor', 'tools' => module_tools('servers')]);

panel_start('Server (Konfiguration, Laufzeit und Connection-Pool)', ['flush' => true]);
?>
<div class="table-wrap">
<table class="grid">
  <thead><tr>
    <th>HG</th><th>Host</th><th>Port</th><th>Status</th><th>Laufzeit</th><th>Gewicht</th><th>Max. Verb.</th>
    <th>Genutzt / Frei</th><th>OK / Fehler</th><th>Queries</th><th>Latenz</th><th>Gesendet / Empfangen</th><th>Kommentar</th><th>Aktionen</th>
  </tr></thead>
  <tbody>
  <?php foreach ($servers as $s):
      $key = ['hg' => $s['hostgroup_id'], 'host' => $s['hostname'], 'port' => $s['port']]; ?>
    <tr>
      <td class="num"><?= h($s['hostgroup_id']) ?></td>
      <td class="mono"><?= h($s['hostname']) ?></td>
      <td class="num"><?= h($s['port']) ?></td>
      <td><?= status_badge($s['status']) ?></td>
      <td><?= $s['rt_status'] ? status_badge($s['rt_status']) : badge('nicht aktiv', 'warn') ?></td>
      <td class="num"><?= nf($s['weight']) ?></td>
      <td class="num"><?= nf($s['max_connections']) ?></td>
      <td class="num"><?= nf($s['ConnUsed']) ?> / <?= nf($s['ConnFree']) ?></td>
      <td class="num"><?= nf($s['ConnOK']) ?> / <span class="<?= $s['ConnERR'] ? 'text-bad' : '' ?>"><?= nf($s['ConnERR']) ?></span></td>
      <td class="num"><?= nf($s['Queries']) ?></td>
      <td class="num"><?= $s['Latency_us'] !== null ? h(fmt_us($s['Latency_us'])) : '' ?></td>
      <td class="num"><?= $s['Bytes_data_sent'] !== null ? h(fmt_bytes($s['Bytes_data_sent']) . ' / ' . fmt_bytes($s['Bytes_data_recv'])) : '' ?></td>
      <td><?= h($s['comment']) ?></td>
      <td class="actions">
        <a class="btn btn-sm" href="<?= h(url(['page' => 'servers', 'edit' => implode('|', $key)])) ?>#server-form"><?= icon('edit') ?> Bearbeiten</a>
        <?php if ($s['status'] === 'ONLINE'): ?>
          <?= action_form('set_status', $key + ['status' => 'OFFLINE_SOFT'], 'Offline (soft)', ['confirm' => 'Server ' . $s['hostname'] . ' auf OFFLINE_SOFT setzen? Laufende Verbindungen werden beendet, neue nicht mehr zugeteilt.']) ?>
        <?php else: ?>
          <?= action_form('set_status', $key + ['status' => 'ONLINE'], 'Online') ?>
        <?php endif ?>
        <?= action_form('delete_server', $key, icon('trash'), ['confirm' => 'Server ' . $s['hostname'] . ':' . $s['port'] . ' aus Hostgroup ' . $s['hostgroup_id'] . ' entfernen?', 'btn' => 'btn-sm btn-danger']) ?>
      </td>
    </tr>
  <?php endforeach ?>
  <?php foreach ($runtimeOnly as $s): ?>
    <tr class="muted-row">
      <td class="num"><?= h($s['hostgroup_id']) ?></td><td class="mono"><?= h($s['hostname']) ?></td><td class="num"><?= h($s['port']) ?></td>
      <td><?= badge('nur Laufzeit', 'info', 'Von ProxySQL selbst eingetragen (z. B. Reader-Hostgroup)') ?></td><td><?= status_badge($s['status']) ?></td>
      <td colspan="9"></td>
    </tr>
  <?php endforeach ?>
  <?php if (!$servers && !$runtimeOnly): ?><tr><td colspan="14" class="empty">Keine Server konfiguriert</td></tr><?php endif ?>
  </tbody>
</table>
</div>
<?php
panel_end();

// ---------------------------------------------------------------- Cluster-Erkennung
if (backend()):
    $cluster = cluster_plan();
    panel_start('Cluster' . ($cluster['type'] ? ': ' . CLUSTER_TYPES[$cluster['type']] : ''), ['id' => 'cluster', 'flush' => true]);
    if (!$cluster['type']): ?>
      <p class="pad muted">Kein Cluster erkannt – <?= h(cfg('backend')['host']) ?> hat keine angemeldeten Replikate, kein Galera und keine Group Replication. <?= h(auto_sync_note(['cluster'])) ?></p>
    <?php else:
        echo data_table($cluster['nodes'], [
            'cols'   => ['host' => 'Knoten', 'port' => 'Port', 'role' => 'Rolle', 'hostgroups' => 'In ProxySQL'],
            'format' => [
                'host'       => fn ($v) => '<span class="mono">' . h($v) . '</span>',
                'hostgroups' => fn ($v) => $v ? h('HG ' . implode(', ', $v)) : badge('neu – fehlt in ProxySQL', 'warn'),
            ],
        ]);
        foreach ($cluster['gone'] as $g): ?>
          <div class="pad"><?= icon('warning') ?> <span class="mono"><?= h($g['hostname'] . ':' . $g['port']) ?></span> (HG <?= h($g['hostgroup_id']) ?>)
            ist eingetragen, aber nicht mehr im Cluster<?= $cluster['type'] === 'replication' ? ' bzw. nicht beim Primary angemeldet (Replikation gestoppt?)' : '' ?>.
            <?= action_form('cluster_remove', ['host' => $g['hostname'], 'port' => $g['port']], 'Austragen', ['confirm' => "{$g['hostname']}:{$g['port']} aus allen Hostgroups austragen?", 'btn' => 'btn-sm btn-danger']) ?></div>
        <?php endforeach ?>
        <div class="pad">
        <?php if ($cluster['hint']): ?>
          <?= icon('info') ?> <?= h($cluster['hint']) ?>
        <?php elseif ($cluster['new']): ?>
          <?= form_open('cluster_add', ['class' => 'inline-form']) ?>
            <?php if ($cluster['setup']): ?>
              <span>Für Replikate gibt es noch keine Replikations-Hostgroup. Anlegen mit Writer HG <?= (int) $cluster['writer_hg'] ?> und Reader</span>
              <label>HG <input name="reader_hg" type="number" min="0" value="<?= (int) $cluster['suggest_reader'] ?>" style="width: 70px"></label>
              <span class="muted">– ProxySQL verteilt die Server dann anhand von read_only.</span>
            <?php endif ?>
            <?= submit_button(icon('plus') . ' ' . count($cluster['new']) . ' Knoten aufnehmen' . ($cluster['setup'] ? '' : ' (HG ' . $cluster['target'] . ')'), 'btn-sm btn-primary') ?>
          </form>
        <?php else: ?>
          <?= icon('ok') ?> Alle Knoten sind in ProxySQL eingetragen.
        <?php endif ?>
          <span class="muted"><?= h(auto_sync_note(['cluster'])) ?><?= $cluster['setup'] ? ' – die Replikations-Hostgroup wird nie automatisch angelegt.' : '' ?></span>
        </div>
    <?php endif;
    panel_end();
endif;

panel_start($edit ? 'Server bearbeiten: ' . $edit['hostname'] . ':' . $edit['port'] : 'Server hinzufügen', ['id' => 'server-form']);
echo form_open('save_server');
if ($edit) {
    echo hidden('orig_hg', $edit['hostgroup_id']) . hidden('orig_host', $edit['hostname']) . hidden('orig_port', $edit['port']);
}
?>
  <div class="form-grid">
    <div class="field"><label>Hostgroup</label><input name="hostgroup_id" type="number" min="0" value="<?= h($f['hostgroup_id']) ?>" required></div>
    <div class="field"><label>Hostname / IP</label><input name="hostname" type="text" value="<?= h($f['hostname']) ?>" required placeholder="db1.example.com"></div>
    <div class="field"><label>Port</label><input name="port" type="number" min="1" max="65535" value="<?= h($f['port']) ?>" required></div>
    <div class="field"><label>Status</label><select name="status"><?= options(array_combine(SERVER_STATUS, SERVER_STATUS), $f['status']) ?></select></div>
    <div class="field"><label>Gewicht</label><input name="weight" type="number" min="0" value="<?= h($f['weight']) ?>"></div>
    <div class="field"><label>Max. Verbindungen</label><input name="max_connections" type="number" min="0" value="<?= h($f['max_connections']) ?>"></div>
    <div class="field"><label>Max. Replikations-Lag (s)</label><input name="max_replication_lag" type="number" min="0" value="<?= h($f['max_replication_lag']) ?>"></div>
    <div class="field"><label>Max. Latenz (ms)</label><input name="max_latency_ms" type="number" min="0" value="<?= h($f['max_latency_ms']) ?>"></div>
    <div class="field span2"><label>Kommentar</label><input name="comment" type="text" value="<?= h($f['comment']) ?>"></div>
    <div class="field checks">
      <label class="check"><input type="checkbox" name="use_ssl" value="1"<?= (int) $f['use_ssl'] ? ' checked' : '' ?>> SSL zum Backend</label>
      <label class="check"><input type="checkbox" name="compression" value="1"<?= (int) $f['compression'] ? ' checked' : '' ?>> Kompression</label>
    </div>
  </div>
  <div class="form-actions">
    <?= submit_button(icon('check') . ' Speichern und aktivieren') ?>
    <?php if ($edit): ?><a class="btn" href="?page=servers">Abbrechen</a><?php endif ?>
  </div>
</form>
<?php
panel_end();
?>
<div class="split">
<?php
panel_start('Replikations-Hostgroups (Writer/Reader anhand read_only)', ['flush' => true]);
echo data_table($rhg, [
    'cols'   => ['writer_hostgroup' => 'Writer-HG', 'reader_hostgroup' => 'Reader-HG', 'check_type' => 'Prüfung', 'comment' => 'Kommentar', '_a' => ''],
    'format' => ['_a' => fn ($v, $r) => action_form('delete_rhg', ['writer' => $r['writer_hostgroup']], icon('trash'), ['confirm' => 'Replikations-Hostgroup entfernen?', 'btn' => 'btn-sm btn-danger'])],
    'empty'  => 'Keine Replikations-Hostgroups – alle Queries gehen an die Standard-Hostgroup des Kontos bzw. an die Ziel-Hostgroup der Query-Regeln.',
]);
echo '<div class="pad">' . form_open('save_rhg', ['class' => 'inline-form']);
?>
  <label>Writer <input name="writer" type="number" min="0" value="0" class="w-num"></label>
  <label>Reader <input name="reader" type="number" min="0" value="1" class="w-num"></label>
  <label>Prüfung <select name="check_type"><?= options(array_combine(CHECK_TYPES, CHECK_TYPES), 'read_only') ?></select></label>
  <label>Kommentar <input name="comment" type="text"></label>
  <?= submit_button(icon('plus') . ' Hinzufügen', 'btn-sm') ?>
</form></div>
<?php
panel_end();

panel_start('Monitor: letzte Fehler', ['flush' => true]);
echo data_table($errors, [
    'cols'   => ['time_start_us' => 'Zeit', 'kind' => 'Prüfung', 'hostname' => 'Host', 'port' => 'Port', 'error' => 'Fehler'],
    'format' => ['time_start_us' => fn ($v) => h(fmt_ts(intdiv((int) $v, 1000000)))],
    'empty'  => 'Keine Fehler im Monitor-Log (' . fmt_duration(intdiv((int) global_var('mysql-monitor_history'), 1000)) . ' Historie).',
]);
panel_end();
?>
</div>
<?php
layout_end();
