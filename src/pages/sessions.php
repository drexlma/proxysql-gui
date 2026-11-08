<?php
// Sitzungen: aktuelle Client-Verbindungen über ProxySQL, Verbindungen je Konto

handle_post(function (string $action) {
    if ($action !== 'kill') {
        throw new InvalidArgumentException('Unbekannte Aktion');
    }
    $id = aint(post('session'), false);
    run(admin(), "KILL CONNECTION $id");
    flash('ok', "Sitzung $id beendet.");
    return null;
});

$sessions = q(admin(), 'SELECT SessionID, user, db, cli_host, cli_port, hostgroup, srv_host, srv_port, command, time_ms, info
    FROM stats_mysql_processlist ORDER BY time_ms DESC');
$perUser = q(admin(), 'SELECT username, frontend_connections, frontend_max_connections FROM stats_mysql_users ORDER BY frontend_connections DESC, username');

layout_start('Sitzungen', 'sessions', ['title' => 'Sitzungen', 'sub' => 'stats_mysql_processlist · ' . count($sessions) . ' Client-Verbindungen']);

panel_start('Aktive Sitzungen', ['flush' => true]);
echo data_table($sessions, [
    'cols'   => ['SessionID' => 'Sitzung', 'user' => 'Benutzer', 'db' => 'Schema', 'cli_host' => 'Client', 'hostgroup' => 'HG', 'srv_host' => 'Backend',
        'command' => 'Befehl', 'time_ms' => 'Dauer', 'info' => 'Query', '_a' => ''],
    'format' => [
        'cli_host' => fn ($v, $r) => '<span class="mono">' . h($v . ':' . $r['cli_port']) . '</span>',
        'srv_host' => fn ($v, $r) => $v ? '<span class="mono">' . h($v . ':' . $r['srv_port']) . '</span>' : '',
        'time_ms'  => fn ($v) => '<span class="nowrap">' . h(fmt_us((int) $v * 1000)) . '</span>',
        'info'     => fn ($v) => '<div class="digest-text mono" title="' . h($v) . '">' . h($v) . '</div>',
        '_a'       => fn ($v, $r) => action_form('kill', ['session' => $r['SessionID']], 'Beenden', ['confirm' => 'Sitzung ' . $r['SessionID'] . ' (' . $r['user'] . ') beenden?', 'btn' => 'btn-sm btn-danger']),
    ],
    'empty'  => 'Keine Client-Verbindungen.',
]);
panel_end();
?>
<div class="split">
<?php
panel_start('Verbindungen je Konto', ['flush' => true]);
echo data_table($perUser, [
    'cols'   => ['username' => 'Konto', 'frontend_connections' => 'Verbunden', 'frontend_max_connections' => 'Maximum'],
    'format' => [
        'username'                 => fn ($v) => '<a href="' . h(url(['page' => 'users', 'account' => $v])) . '#account">' . h($v) . '</a>',
        'frontend_connections'     => fn ($v) => nf($v),
        'frontend_max_connections' => fn ($v) => nf($v),
    ],
]);
panel_end();
?>
</div>
<?php
layout_end();
