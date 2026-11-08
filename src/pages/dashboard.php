<?php
// Übersicht: Verfügbarkeit, Konfiguration, Diagramme, Vorfälle und Probleme

$g = stats_global();
$avail = availability(600);
$incidents = incidents();
$b = backend();
$bi = $b ? backend_info() : null;
$p = cfg('proxysql');
$bc = cfg('backend');

$down = !$avail['servers'] || array_filter($incidents, fn ($i) => $i['sev'] === 'critical');
$ranges = [900 => '15 Min.', 3600 => '1 Std.', 21600 => '6 Std.', 86400 => '24 Std.', 604800 => '7 Tage'];
$uptime = (int) ($g['ProxySQL_Uptime'] ?? 0);

layout_start('Übersicht', 'dashboard', [
    'status' => $down ? 'down' : 'up',
    'title'  => $p['host'] . ':' . $p['port'],
    'sub'    => 'ProxySQL ' . proxysql_version(),
    'tools'  => '<label class="range">Zeitraum <select data-range>' . options($ranges, 3600) . '</select></label>',
]);

$seg = fn ($s) => $s['pct'] === null ? 'seg-none' : ($s['pct'] >= 99.9 ? 'seg-ok' : ($s['pct'] > 0 ? 'seg-part' : 'seg-bad'));
?>
<div class="dash" data-dashboard data-api="?page=api" data-refresh="<?= (int) cfg('refresh') ?>">
  <div class="dash-col">
    <?php panel_start('Verfügbarkeit (Monitor-Ping, letzte 10 Min.)') ?>
      <div class="avail-pct"><?= $avail['pct'] === null ? '–' : nf($avail['pct'], 1) . '%' ?></div>
      <div class="avail-bar">
        <?php foreach ($avail['segments'] as $s): ?>
          <span class="<?= $seg($s) ?>" title="<?= h(date('H:i', $s['t']) . ': ' . ($s['pct'] === null ? 'keine Daten' : nf($s['pct'], 1) . ' %')) ?>"></span>
        <?php endforeach ?>
      </div>
      <div class="avail-range"><span><?= h(date('d.m.Y H:i', $avail['from'])) ?></span><span><?= h(date('d.m.Y H:i', $avail['to'])) ?></span></div>
      <table class="kv avail-servers">
        <?php foreach ($avail['servers'] as $srv => $s): ?>
          <tr>
            <th><?= h($srv) ?></th>
            <td><?= status_badge($s['status']) ?> <span class="muted">HG <?= h(implode(', ', $s['hostgroups'])) ?></span></td>
            <td class="num"><?= $s['pct'] === null ? '–' : nf($s['pct'], 1) . ' %' ?></td>
          </tr>
        <?php endforeach ?>
        <?php if (!$avail['servers']): ?><tr><td class="empty" colspan="3">Keine Server zur Laufzeit</td></tr><?php endif ?>
      </table>
    <?php panel_end() ?>

    <?php panel_start('Konfigurationsübersicht') ?>
      <table class="kv">
        <tr><th>ProxySQL</th><td><?= h(proxysql_version()) ?></td></tr>
        <tr><th>Admin-Interface</th><td><?= h($p['host'] . ':' . $p['port']) ?></td></tr>
        <tr><th>Client-Port</th><td><?= h(global_var('mysql-interfaces')) ?></td></tr>
        <tr><th>Läuft seit</th><td><?= h(fmt_ts(time() - $uptime)) ?> <span class="muted">(<?= h(fmt_duration($uptime)) ?>)</span></td></tr>
        <tr><th>Worker-Threads</th><td><?= h($g['MySQL_Thread_Workers'] ?? global_var('mysql-threads')) ?></td></tr>
        <tr><th>Server / Hostgroups</th><td><?= nf(qv(admin(), 'SELECT COUNT(*) FROM mysql_servers')) ?> / <?= nf(qv(admin(), 'SELECT COUNT(DISTINCT hostgroup_id) FROM mysql_servers')) ?></td></tr>
        <tr><th>Konten / Regeln</th><td><?= nf(qv(admin(), 'SELECT COUNT(DISTINCT username) FROM mysql_users')) ?> / <?= nf(qv(admin(), 'SELECT COUNT(*) FROM mysql_query_rules WHERE active = 1')) ?> aktiv</td></tr>
        <tr><th>Query-Cache</th><td><?= nf(global_var('mysql-query_cache_size_MB')) ?> MB, belegt <?= h(fmt_bytes($g['Query_Cache_Memory_bytes'] ?? 0)) ?></td></tr>
        <tr><th>Monitor-Benutzer</th><td><?= h(global_var('mysql-monitor_username')) ?></td></tr>
        <?php $pending = array_filter(config_state(), fn ($s) => $s['runtime'] || $s['disk']) ?>
        <tr><th>Änderungen</th><td><a href="?page=diff"><?= $pending ? h(implode(', ', array_map(fn ($m) => MODULES[$m], array_keys($pending)))) . ' – nicht aktiv/gespeichert' : 'alles aktiv und gespeichert' ?></a></td></tr>
        <?php if ($bi): ?>
          <tr class="kv-sep"><th>Backend</th><td><?= h($bc['host'] . ':' . $bc['port']) ?> <span class="muted">als <?= h($bc['user']) ?></span></td></tr>
          <tr><th>Version</th><td><?= h($bi['version']) ?></td></tr>
          <tr><th>Hostname</th><td><?= h($bi['hostname']) ?></td></tr>
          <tr><th>Datenverzeichnis</th><td><?= h($bi['datadir']) ?></td></tr>
          <tr><th>UNIX-Socket</th><td><?= h($bi['socket']) ?></td></tr>
          <tr><th>Backend läuft seit</th><td><?= h(fmt_ts(time() - $bi['uptime'])) ?></td></tr>
        <?php elseif (backend_configured()): ?>
          <tr class="kv-sep"><th>Backend</th><td><?= icon('error') ?> <?= h(backend_error()) ?></td></tr>
        <?php endif ?>
      </table>
    <?php panel_end() ?>
  </div>

  <div class="dash-col">
    <?php chart_panel('Verbindungen', 'connections') ?>
    <?php chart_panel('Statement-Aktivität', 'statements') ?>
    <?php chart_panel('Query-Cache-Aktivität', 'cache') ?>
  </div>

  <div class="dash-col">
    <?php chart_panel('Backend-Latenz (Ping, ms)', 'latency') ?>
    <?php chart_panel('Connection-Pool-Aktivität', 'pool') ?>
    <?php panel_start('Query-Verteilung seit Start') ?>
      <div class="chart-box pie-box" data-pie="split"><div class="chart-empty">Lade …</div></div>
    <?php panel_end() ?>
  </div>
</div>

<?php
$cats = array_values(array_unique(array_column($incidents, 'cat')));
$counts = array_count_values(array_column($incidents, 'sev'));
panel_start('Vorfälle und Probleme', ['id' => 'incidents', 'flush' => true]);
?>
  <div class="toolbar pad">
    <label>Kategorie <select data-incident-filter><option value="">Alle</option><?= options(array_combine($cats, $cats) ?: [], '') ?></select></label>
    <span class="sev-counts">
      <?php foreach (SEVERITY as $sev => $label): ?>
        <span title="<?= h($label) ?>"><?= sev_icon($sev) ?> <?= (int) ($counts[$sev] ?? 0) ?></span>
      <?php endforeach ?>
    </span>
  </div>
  <div class="table-wrap">
  <table class="grid incidents">
    <thead><tr><th>Zusammenfassung</th><th>Ziel</th><th>Schweregrad</th><th>Kategorie</th><th>Typ</th><th>Seit</th><th>Aktion</th></tr></thead>
    <tbody>
    <?php foreach ($incidents as $i): ?>
      <tr data-cat="<?= h($i['cat']) ?>">
        <td><?= isset($i['url']) ? '<a href="' . h($i['url']) . '">' . h($i['summary']) . '</a>' : h($i['summary']) ?></td>
        <td class="mono"><?= h($i['target']) ?></td>
        <td class="center"><?= sev_icon($i['sev']) ?></td>
        <td><?= h($i['cat']) ?></td>
        <td><?= h($i['type']) ?></td>
        <td><?= isset($i['since']) ? h(fmt_ago($i['since'])) : '' ?></td>
        <td class="actions">
          <?php if (isset($i['post'])): ?>
            <?= action_form($i['post']['action'], array_diff_key($i['post'], ['action' => 1]) + ['_back' => '?page=dashboard'], h($i['label']), ['to' => $i['to'] ?? null, 'btn' => 'btn-sm btn-primary']) ?>
          <?php elseif (isset($i['url'])): ?>
            <a class="btn btn-sm" href="<?= h($i['url']) ?>"><?= h($i['label']) ?></a>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
    <?php if (!$incidents): ?>
      <tr><td colspan="7" class="empty"><?= icon('ok') ?> Keine Probleme gefunden</td></tr>
    <?php endif ?>
    </tbody>
  </table>
  </div>
<?php
panel_end();
layout_end();
