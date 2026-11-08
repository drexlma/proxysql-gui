<?php
// Konfigurationsabgleich: was im Speicher anders ist als zur Laufzeit (nicht aktiviert) bzw. auf Disk (nicht gespeichert)

handle_post(function (string $action) {
    if (!in_array($action, ['revert_runtime', 'revert_disk'], true)) {
        throw new InvalidArgumentException('Unbekannte Aktion');
    }
    flash('ok', revert_module(post('module'), $action === 'revert_runtime' ? 'runtime' : 'disk'));
    return null;
});

/** Zellwert für die Anzeige: Passwörter verborgen, lange Werte gekürzt */
function diff_value(array $row, string $col): string
{
    if (is_masked($row, $col)) {
        return '•••';
    }
    $v = $row[$col] ?? null;
    return $v === null ? '<span class="null">NULL</span>' : '<span title="' . h($v) . '">' . h(mb_strimwidth($v, 0, 80, '…')) . '</span>';
}

/** Werte einer Zeile außer den Schlüsselspalten */
function diff_row_summary(array $row, array $key): string
{
    $parts = [];
    foreach ($row as $col => $v) {
        if (!array_key_exists($col, $key) && $v !== null && $v !== '') {
            $parts[] = '<span class="muted">' . h($col) . '=</span>' . diff_value($row, $col);
        }
    }
    return implode(' · ', $parts);
}

function spec_label(string $spec): string
{
    [$table, $prefix] = array_pad(explode(':', $spec, 2), 2, '');
    return $table . ($prefix !== '' ? " ($prefix*)" : '');
}

$state = config_state();
$module = (string) ($_GET['module'] ?? '');
if (!isset($state[$module])) {
    $module = array_key_first(array_filter($state, fn ($s) => $s['runtime'] || $s['disk'])) ?? '';
}

layout_start('Konfigurationsabgleich', 'diff', ['title' => 'Konfigurationsabgleich', 'sub' => 'Speicher (bearbeitet) ↔ Laufzeit (aktiv) ↔ Disk (nach Neustart)']);

panel_start('Module', ['flush' => true]);
echo data_table(array_map(fn ($mod) => ['mod' => $mod] + $state[$mod], array_keys($state)), [
    'cols'   => ['mod' => 'Modul', 'tables' => 'Tabellen', 'runtime' => 'Laufzeit', 'disk' => 'Disk', '_a' => ''],
    'format' => [
        'mod'     => fn ($v) => '<a class="nowrap" href="' . h(url(['page' => 'diff', 'module' => $v])) . '">' . ($v === $module ? '<b>' . h(MODULES[$v]) . '</b>' : h(MODULES[$v])) . '</a>',
        'tables'  => fn ($v, $r) => '<span class="muted small mono">' . h(implode(', ', array_map('spec_label', module_specs($r['mod'])))) . '</span>',
        'runtime' => fn ($v) => $v ? badge("$v nicht aktiviert", 'warn') : badge('aktiv', 'ok'),
        'disk'    => fn ($v) => $v ? badge("$v nicht gespeichert", 'warn') : badge('gespeichert', 'ok'),
        '_a'      => fn ($v, $r) => '<span class="nowrap">'
            . ($r['runtime'] ? action_form('apply', ['module' => $r['mod']], 'Aktivieren', ['btn' => 'btn-sm btn-primary']) . ' ' : '')
            . ($r['disk'] ? action_form('save', ['module' => $r['mod']], 'Speichern') : '') . '</span>',
    ],
]);
panel_end();

if ($module === '') {
    echo '<div class="msg msg-ok">' . icon('ok') . '<span>Alles aktiv und gespeichert – Speicher, Laufzeit und Disk stimmen überein.</span></div>';
    layout_end();
    return;
}

$views = [
    'runtime' => [
        'title'   => MODULES[$module] . ': Speicher ↔ Laufzeit (nicht aktiviert)',
        'other'   => 'Laufzeit (aktiv)',
        'kinds'   => ['new' => ['neu', 'ok'], 'deleted' => ['gelöscht', 'bad'], 'changed' => ['geändert', 'warn']],
        'same'    => 'Speicher und Laufzeit stimmen überein.',
        'apply'   => ['apply', 'Aktivieren'],
        'revert'  => ['revert_runtime', 'Verwerfen', 'Änderungen im Speicher verwerfen und den aktiven Stand zurückholen (SAVE ' . MODULES[$module] . ' FROM RUNTIME)?'],
    ],
    'disk' => [
        'title'   => MODULES[$module] . ': Speicher ↔ Disk (nicht gespeichert)',
        'other'   => 'Disk',
        'kinds'   => ['new' => ['neu', 'ok'], 'deleted' => ['gelöscht', 'bad'], 'changed' => ['geändert', 'warn']],
        'same'    => 'Speicher und Disk stimmen überein.',
        'apply'   => ['save', 'Speichern'],
        'revert'  => ['revert_disk', 'Stand von Disk laden', 'Speicher mit dem Stand von Disk überschreiben (LOAD ' . MODULES[$module] . ' FROM DISK)? Danach noch aktivieren.'],
    ],
];

foreach ($views as $other => $v) {
    $diff = module_diff($module, $other);
    $tools = '';
    if ($diff) {
        $tools = action_form($v['apply'][0], ['module' => $module], h($v['apply'][1]), ['btn' => 'btn-sm btn-primary']) . ' '
            . action_form($v['revert'][0], ['module' => $module], h($v['revert'][1]), ['confirm' => $v['revert'][2]]);
    }
    panel_start($v['title'], ['flush' => true, 'id' => $other, 'tools' => $tools]);
    if (!$diff) {
        echo '<p class="empty">' . icon('ok') . ' ' . h($v['same']) . '</p>';
    }
    foreach ($diff as $spec => $entries) {
        echo '<h3 class="diff-table mono">' . h(spec_label($spec)) . '</h3>';
        echo data_table($entries, [
            'cols'   => ['kind' => 'Art', 'key' => 'Schlüssel', 'cols' => 'Änderung: ' . $v['other'] . ' → Speicher'],
            'format' => [
                'kind' => fn ($k) => badge(...$v['kinds'][$k]),
                'key'  => fn ($kv) => '<span class="mono">' . h(implode(' · ', array_map(fn ($c, $x) => "$c=" . ($x ?? 'NULL'), array_keys($kv), $kv))) . '</span>',
                'cols' => function ($cols, $e) {
                    if ($e['kind'] === 'new') {
                        return diff_row_summary($e['mem'], $e['key']);
                    }
                    if ($e['kind'] === 'deleted') {
                        return '<span class="diff-old">' . diff_row_summary($e['other'], $e['key']) . '</span>';
                    }
                    $out = '';
                    foreach ($cols as $c) {
                        $out .= '<div><span class="mono">' . h($c) . '</span>: <span class="diff-old">' . diff_value($e['other'], $c) . '</span> → <span class="diff-new">' . diff_value($e['mem'], $c) . '</span></div>';
                    }
                    return $out;
                },
            ],
        ]);
    }
    panel_end();
}

layout_end();
