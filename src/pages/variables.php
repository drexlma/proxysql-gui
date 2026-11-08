<?php
// Globale Variablen: anzeigen, filtern, ändern (mit Abgleich Memory ↔ Runtime)

const VAR_MODULES = ['mysql-' => 'mysqlvars', 'admin-' => 'adminvars', 'pgsql-' => 'pgsqlvars'];

function var_module(string $name): ?string
{
    foreach (VAR_MODULES as $prefix => $mod) {
        if (str_starts_with($name, $prefix)) {
            return $mod;
        }
    }
    return null;
}

handle_post(function (string $action) {
    if ($action !== 'set_var') {
        throw new InvalidArgumentException('Unbekannte Aktion');
    }
    $name = post('name');
    if (qv(admin(), 'SELECT COUNT(*) FROM global_variables WHERE variable_name = ' . aq($name)) !== '1') {
        throw new InvalidArgumentException("Unbekannte Variable: $name");
    }
    $mod = var_module($name) ?? throw new InvalidArgumentException("Variable $name kann hier nicht geändert werden");
    run(admin(), 'UPDATE global_variables SET variable_value = ' . aq(post_raw('value')) . ' WHERE variable_name = ' . aq($name));
    $msg = "$name gesetzt. " . apply_module($mod);
    $rt = qv(admin(), 'SELECT variable_value FROM runtime_global_variables WHERE variable_name = ' . aq($name));
    if ($rt !== null && $rt !== post_raw('value')) {
        $msg .= ' Hinweis: Laufzeitwert ist „' . (is_secret($name) ? '•••' : $rt) . '“ – ProxySQL hat den Wert nicht (vollständig) übernommen.';
    }
    flash('ok', $msg);
    return null;
});

$prefixes = ['mysql-' => 'MySQL', 'mysql-monitor_' => 'Monitor', 'admin-' => 'Admin', 'pgsql-' => 'PostgreSQL', '' => 'Alle'];
$prefix = array_key_exists($_GET['prefix'] ?? 'mysql-', $prefixes) ? (string) ($_GET['prefix'] ?? 'mysql-') : 'mysql-';
$onlyDiff = !empty($_GET['diff']);

$vars = q(admin(), 'SELECT g.variable_name, g.variable_value, r.variable_value AS runtime_value
    FROM global_variables g LEFT JOIN runtime_global_variables r ON r.variable_name = g.variable_name'
    . ($prefix !== '' ? ' WHERE g.variable_name LIKE ' . aq(str_replace('_', '\_', $prefix) . '%') . " ESCAPE '\\'" : '')
    . ' ORDER BY g.variable_name');
if ($onlyDiff) {
    $vars = array_values(array_filter($vars, fn ($v) => $v['runtime_value'] !== null && $v['runtime_value'] !== $v['variable_value']));
}

$tools = '';
foreach ($prefixes as $p => $label) {
    $tools .= '<a class="tab' . ($p === $prefix ? ' active' : '') . '" href="' . h(url(['page' => 'variables', 'prefix' => $p] + ($onlyDiff ? ['diff' => 1] : []))) . '">' . h($label) . '</a>';
}
$tools .= '<a class="tab' . ($onlyDiff ? ' active' : '') . '" href="' . h(url(['page' => 'variables', 'prefix' => $prefix] + ($onlyDiff ? [] : ['diff' => 1]))) . '">Nur Abweichungen</a>';

$mod = var_module($prefix);
layout_start('Variablen', 'variables', ['title' => 'Globale Variablen', 'sub' => 'global_variables · Änderungen werden sofort aktiviert und gespeichert',
    'tools' => $mod ? module_tools($mod) : '']);
panel_start(count($vars) . ' Variablen', ['flush' => true, 'tools' => $tools]);
?>
  <div class="toolbar pad">
    <input type="search" class="filter" placeholder="Variable filtern …" data-filter-table="#vars" autofocus>
    <span class="muted">Vorsicht bei admin-admin_credentials und admin-mysql_ifaces – die GUI kann sich damit aussperren.</span>
  </div>
  <div class="table-wrap">
  <table class="grid" id="vars">
    <thead><tr><th>Variable</th><th>Wert</th><th>Laufzeit</th></tr></thead>
    <tbody>
    <?php foreach ($vars as $v):
        $secret = is_secret($v['variable_name']);
        $diff = $v['runtime_value'] !== null && $v['runtime_value'] !== $v['variable_value']; ?>
      <tr<?= $diff ? ' class="diff-row"' : '' ?>>
        <td class="mono"><?= h($v['variable_name']) ?></td>
        <td>
          <?php if ($secret && !is_admin()): ?>
            <span class="mono">•••</span>
          <?php else: ?>
          <?= form_open('set_var', ['class' => 'inline-form var-form']) . hidden('name', $v['variable_name']) ?>
            <input name="value" type="<?= $secret ? 'password' : 'text' ?>" value="<?= h($v['variable_value']) ?>" autocomplete="off">
            <?= submit_button('Setzen', 'btn-sm') ?>
          </form>
          <?php endif ?>
        </td>
        <td class="mono"><?= $v['runtime_value'] === null ? '<span class="null">–</span>' : ($secret ? '•••' : h($v['runtime_value'])) ?><?= $diff ? ' ' . badge('abweichend', 'warn') : '' ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
<?php
panel_end();
layout_end();
