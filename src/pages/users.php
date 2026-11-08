<?php
// Konten: Abgleich ProxySQL ↔ Backend, Anlegen in beiden Systemen, Rechte und Passwörter

function need_backend(): mysqli
{
    return backend() ?? throw new InvalidArgumentException('Backend nicht verbunden: ' . (backend_error() ?? 'nicht konfiguriert'));
}

function need_proxy_user(string $name): array
{
    return proxy_user($name) ?? throw new InvalidArgumentException("Benutzer $name existiert in ProxySQL nicht");
}

handle_post(function (string $action) {
    $name = post('name');
    $back = url(['page' => 'users', 'account' => $name]) . '#account';

    switch ($action) {
        case 'create_account':
            return create_account();

        case 'import_proxy': // Backend → ProxySQL
            valid_username($name);
            $pw = post_raw('password');
            $secret = $pw !== '' ? $pw : (backend_native_hash($name)
                ?? throw new InvalidArgumentException('Das Backend-Passwort ist kein mysql_native_password-Hash – bitte Passwort angeben.'));
            insert_proxy_user($name, $secret, (int) aint(post('default_hostgroup', '0'), false), [
                'default_schema' => post('default_schema'),
                'comment'        => post('comment', 'aus Backend übernommen'),
            ]);
            flash('ok', "$name in ProxySQL angelegt" . ($pw === '' ? ' (Passwort-Hash aus dem Backend übernommen)' : '') . '. ' . apply_module('users'));
            return $back;

        case 'create_backend': // ProxySQL → Backend
            need_backend();
            $p = need_proxy_user($name);
            $host = valid_host(post('host') ?: '%');
            $schema = valid_schema(post('schema', '*'));
            $pw = post_raw('password');
            $stored = (string) $p['password'];
            if ($pw !== '') {
                create_backend_user($name, $host, $pw, false);
            } elseif (is_native_hash($stored)) {
                create_backend_user($name, $host, strtoupper($stored), true);
            } elseif ($stored !== '' && !str_starts_with($stored, '$')) {
                create_backend_user($name, $host, $stored, false);
            } else {
                throw new InvalidArgumentException('Passwort aus ProxySQL nicht verwendbar – bitte Passwort angeben.');
            }
            $g = grant_backend($name, $host, $schema, post('preset', 'rw'));
            flash('ok', "'$name'@'$host' im Backend angelegt" . ($g ? " · Rechte: $g" : '') . '.');
            return $back;

        case 'save_proxy':
            need_proxy_user($name);
            run(admin(), 'UPDATE mysql_users SET ' . sql_assign([
                'active'                 => checked('active') ? '1' : '0',
                'default_hostgroup'      => aint(post('default_hostgroup'), false),
                'default_schema'         => aq(post('default_schema') ?: null),
                'max_connections'        => aint(post('max_connections', '10000'), false),
                'transaction_persistent' => checked('transaction_persistent') ? '1' : '0',
                'fast_forward'           => checked('fast_forward') ? '1' : '0',
                'use_ssl'                => checked('use_ssl') ? '1' : '0',
                'comment'                => aq(post('comment')),
            ]) . ' WHERE username = ' . aq($name));
            flash('ok', "ProxySQL-Einstellungen für $name gespeichert. " . apply_module('users'));
            return $back;

        case 'set_password':
            $pw = post_raw('password');
            if ($pw === '') {
                throw new InvalidArgumentException('Passwort fehlt');
            }
            if ($pw !== post_raw('password2')) {
                throw new InvalidArgumentException('Die Passwörter stimmen nicht überein');
            }
            $targets = (array) ($_POST['targets'] ?? []);
            $done = [];
            if (in_array('backend', $targets, true)) {
                need_backend();
                foreach (backend_hosts($name) as $h) {
                    run(backend(), 'ALTER USER ' . bacct($name, $h['host']) . ' IDENTIFIED BY ' . bq($pw));
                    $done[] = "Backend '$name'@'{$h['host']}'";
                }
            }
            if (in_array('proxy', $targets, true) && ($p = proxy_user($name))) {
                // Speicherform beibehalten: Hash bleibt Hash, Klartext bleibt Klartext
                $secret = is_native_hash($p['password']) ? native_hash($pw) : $pw;
                run(admin(), 'UPDATE mysql_users SET password = ' . aq($secret) . ' WHERE username = ' . aq($name));
                $done[] = 'ProxySQL (' . apply_module('users') . ')';
            }
            if (!$done) {
                throw new InvalidArgumentException('Kein Ziel ausgewählt bzw. Konto nicht vorhanden');
            }
            flash('ok', 'Passwort gesetzt: ' . implode(', ', $done));
            return $back;

        case 'grant':
            need_backend();
            $host = post('host');
            if (!in_array($host, array_column(backend_hosts($name), 'host'), true)) {
                throw new InvalidArgumentException("Konto '$name'@'$host' existiert im Backend nicht");
            }
            $g = grant_backend($name, $host, valid_schema(post('schema', '*')), post('preset', 'ro'));
            flash('ok', $g ? "Rechte vergeben: $g" : 'Keine Rechte vergeben.');
            return $back;

        case 'revoke_all':
            need_backend();
            run(backend(), 'REVOKE ALL PRIVILEGES, GRANT OPTION FROM ' . bacct($name, post('host')));
            flash('ok', "Alle Rechte von '$name'@'" . post('host') . "' entzogen.");
            return $back;

        case 'drop_backend':
            need_backend();
            run(backend(), 'DROP USER ' . bacct($name, post('host')));
            flash('ok', "Backend-Konto '$name'@'" . post('host') . "' gelöscht.");
            return $back;

        case 'delete_proxy':
            run(admin(), 'DELETE FROM mysql_users WHERE username = ' . aq($name));
            flash('ok', "$name aus ProxySQL gelöscht. " . apply_module('users'));
            return $back;

        case 'toggle_active':
            run(admin(), 'UPDATE mysql_users SET active = 1 - active WHERE username = ' . aq($name));
            flash('ok', "$name " . ((int) need_proxy_user($name)['active'] ? 'aktiviert' : 'deaktiviert') . '. ' . apply_module('users'));
            return null;

        case 'sync_accounts': // Backend → ProxySQL wie proxysql-admin --syncusers
            need_backend();
            $parts = array_values(array_intersect(['import', 'passwords', 'delete'], (array) ($_POST['parts'] ?? [])));
            if (!$parts) {
                throw new InvalidArgumentException('Nichts ausgewählt');
            }
            flash('ok', account_sync($parts, (int) aint(post('default_hostgroup', '0'), false)));
            return url(['page' => 'users']);

        case 'create_monitor': // Monitor-Benutzer mit dem Passwort aus mysql-monitor_password anlegen
            need_backend();
            $mon = valid_username((string) global_var('mysql-monitor_username'));
            create_backend_user($mon, '%', (string) global_var('mysql-monitor_password'), false);
            run(backend(), 'GRANT USAGE, REPLICATION CLIENT ON *.* TO ' . bacct($mon, '%'));
            flash('ok', "Monitor-Benutzer '$mon'@'%' im Backend angelegt (USAGE, REPLICATION CLIENT).");
            return null;
    }
    throw new InvalidArgumentException('Unbekannte Aktion');
});

$showSystem = !empty($_GET['system']);
$onlyProblems = ($_GET['filter'] ?? '') === 'problems';
$matrix = account_matrix($showSystem);
$selected = isset($_GET['account']) ? (string) $_GET['account'] : null;
$b = backend();
$schemas = $b ? backend_schemas() : [];
$schemaOpts = ['*' => 'alle Datenbanken (*.*)'] + array_combine($schemas, $schemas);
$presetOpts = array_map(fn ($p) => $p[0], PRIV_PRESETS);

$counts = ['ok' => 0, 'missing_proxy' => 0, 'missing_backend' => 0, 'pw_mismatch' => 0];
foreach ($matrix as $acc) {
    $codes = array_column($acc['issues'], 'code');
    foreach ($codes as $c) {
        if (isset($counts[$c])) {
            $counts[$c]++;
        }
    }
    if (!array_filter($acc['issues'], fn ($i) => $i['sev'] !== 'info')) {
        $counts['ok']++;
    }
}

layout_start('Konten', 'users', ['title' => 'Konten', 'sub' => 'ProxySQL (mysql_users) ↔ Backend (mysql.user)', 'tools' => module_tools('users')]);

if (!$b) {
    echo '<div class="msg msg-warn">' . icon('warning') . '<span>Backend nicht verbunden'
        . (backend_error() ? ': ' . h(backend_error()) : ' (BACKEND_HOST/BACKEND_USER nicht gesetzt)')
        . ' – es werden nur die ProxySQL-Konten angezeigt.</span></div>';
}

// ---------------------------------------------------------------- Konto-Details
if ($selected !== null):
    $p = proxy_user($selected);
    $hosts = backend_hosts($selected);
    $acc = current(array_filter(account_matrix(true), fn ($a) => $a['name'] === $selected)) ?: ['issues' => [], 'pw' => null];
    $fields = ['name' => $selected];
    panel_start('Konto: ' . $selected, ['id' => 'account', 'tools' => '<a class="btn btn-sm" href="?page=users">Schließen</a>']);
    if ($acc['issues']): ?>
      <div class="issue-list">
        <?php foreach ($acc['issues'] as $is): ?><div><?= sev_icon($is['sev']) ?> <?= h($is['text']) ?></div><?php endforeach ?>
      </div>
    <?php endif ?>
    <div class="split">
      <fieldset>
        <legend><?= icon('cache') ?> ProxySQL</legend>
        <?php if ($p): ?>
          <?= form_open('save_proxy') . hidden('name', $selected) ?>
            <p class="muted">Passwort gespeichert als <?= is_native_hash($p['password']) ? 'mysql_native_password-Hash' : (str_starts_with((string) $p['password'], '$') ? 'caching_sha2-Hash' : 'Klartext') ?>
              <?php if ($acc['pw']): ?> · Abgleich mit Backend: <?= ['ok' => badge('gleich', 'ok'), 'mismatch' => badge('abweichend', 'bad'), 'unknown' => badge('nicht prüfbar', 'off')][$acc['pw']] ?><?php endif ?></p>
            <div class="form-grid">
              <div class="field"><label>Standard-Hostgroup</label><?= hostgroup_select('default_hostgroup', $p['default_hostgroup']) ?></div>
              <div class="field"><label>Standard-Schema</label><input name="default_schema" type="text" value="<?= h($p['default_schema']) ?>"></div>
              <div class="field"><label>Max. Verbindungen</label><input name="max_connections" type="number" min="0" value="<?= h($p['max_connections']) ?>"></div>
              <div class="field"><label>Kommentar</label><input name="comment" type="text" value="<?= h($p['comment']) ?>"></div>
              <div class="field checks span2">
                <label class="check"><input type="checkbox" name="active" value="1"<?= (int) $p['active'] ? ' checked' : '' ?>> Aktiv</label>
                <label class="check" title="Transaktionen bleiben auf einer Backend-Verbindung"><input type="checkbox" name="transaction_persistent" value="1"<?= (int) $p['transaction_persistent'] ? ' checked' : '' ?>> transaction_persistent</label>
                <label class="check" title="Kein Query-Processing (keine Regeln, kein Cache)"><input type="checkbox" name="fast_forward" value="1"<?= (int) $p['fast_forward'] ? ' checked' : '' ?>> fast_forward</label>
                <label class="check"><input type="checkbox" name="use_ssl" value="1"<?= (int) $p['use_ssl'] ? ' checked' : '' ?>> SSL vom Client</label>
              </div>
            </div>
            <div class="form-actions">
              <?= submit_button(icon('check') . ' Speichern') ?>
            </div>
          </form>
          <div class="form-actions">
            <?= action_form('delete_proxy', $fields, icon('trash') . ' Aus ProxySQL löschen', ['confirm' => "$selected aus ProxySQL löschen? Anwendungen können sich danach nicht mehr über ProxySQL anmelden.", 'btn' => 'btn-sm btn-danger']) ?>
          </div>
        <?php else: ?>
          <p><?= icon('warning') ?> Nicht in ProxySQL vorhanden.</p>
          <?= form_open('import_proxy') . hidden('name', $selected) ?>
            <div class="form-grid">
              <div class="field"><label>Standard-Hostgroup</label><?= hostgroup_select('default_hostgroup', 0) ?></div>
              <div class="field"><label>Standard-Schema</label><input name="default_schema" type="text" placeholder="optional"></div>
              <div class="field span2"><label>Passwort</label><input name="password" type="password" autocomplete="new-password"
                placeholder="<?= backend_native_hash($selected) ? 'leer = Hash aus dem Backend übernehmen' : 'erforderlich' ?>">
                <span class="hint">Leer lassen übernimmt den mysql_native_password-Hash – das Passwort muss nicht bekannt sein.</span></div>
            </div>
            <div class="form-actions"><?= submit_button(icon('plus') . ' In ProxySQL anlegen') ?></div>
          </form>
        <?php endif ?>
      </fieldset>

      <fieldset>
        <legend><?= icon('db') ?> Backend<?= $b ? ' (' . h(cfg('backend')['host']) . ')' : '' ?></legend>
        <?php if (!$b): ?>
          <p class="muted">Backend nicht verbunden.</p>
        <?php elseif ($hosts): ?>
          <?php foreach ($hosts as $hst): ?>
            <div class="grant-block">
              <div class="grant-head">
                <b class="mono"><?= h("'{$selected}'@'{$hst['host']}'") ?></b> <span class="muted"><?= h($hst['plugin']) ?></span>
                <span class="actions">
                  <?= action_form('revoke_all', $fields + ['host' => $hst['host']], 'Rechte entziehen', ['confirm' => "Alle Rechte von '$selected'@'{$hst['host']}' entziehen?"]) ?>
                  <?= action_form('drop_backend', $fields + ['host' => $hst['host']], icon('trash') . ' DROP USER', ['confirm' => "Konto '$selected'@'{$hst['host']}' im Backend löschen?", 'btn' => 'btn-sm btn-danger']) ?>
                </span>
              </div>
              <ul class="code-list"><?php foreach (backend_grants($selected, $hst['host']) as $gr): ?><li><?= h($gr) ?></li><?php endforeach ?></ul>
            </div>
          <?php endforeach ?>
          <?= form_open('grant', ['class' => 'inline-form']) . hidden('name', $selected) ?>
            <label>Host <select name="host"><?= options(array_combine($hh = array_column($hosts, 'host'), $hh), $hosts[0]['host']) ?></select></label>
            <label>Datenbank <select name="schema"><?= options($schemaOpts, '*') ?></select></label>
            <label>Rechte <select name="preset"><?= options(array_diff_key($presetOpts, ['none' => 1]), 'ro') ?></select></label>
            <?= submit_button(icon('plus') . ' GRANT', 'btn-sm') ?>
          </form>
        <?php else: ?>
          <p><?= icon('error') ?> Nicht im Backend vorhanden.</p>
          <?= form_open('create_backend') . hidden('name', $selected) ?>
            <div class="form-grid">
              <div class="field"><label>Host</label><input name="host" type="text" value="%"></div>
              <div class="field"><label>Datenbank</label><select name="schema"><?= options($schemaOpts, $p['default_schema'] ?? '*') ?></select></div>
              <div class="field"><label>Rechte</label><select name="preset"><?= options($presetOpts, 'rw') ?></select></div>
              <div class="field"><label>Passwort</label><input name="password" type="password" autocomplete="new-password" placeholder="leer = aus ProxySQL übernehmen"></div>
            </div>
            <div class="form-actions"><?= submit_button(icon('plus') . ' Im Backend anlegen') ?></div>
          </form>
        <?php endif ?>
      </fieldset>
    </div>

    <?php if ($p || $hosts): ?>
    <fieldset>
      <legend><?= icon('key') ?> Passwort setzen</legend>
      <?= form_open('set_password', ['class' => 'inline-form']) . hidden('name', $selected) ?>
        <label>Neues Passwort <input id="pw-set" name="password" type="password" autocomplete="new-password" required></label>
        <label>Wiederholen <input id="pw-set2" name="password2" type="password" autocomplete="new-password" required></label>
        <button type="button" class="btn btn-sm" data-genpw="#pw-set" data-genpw2="#pw-set2">Generieren</button>
        <?php if ($p): ?><label class="check"><input type="checkbox" name="targets[]" value="proxy" checked> in ProxySQL</label><?php endif ?>
        <?php if ($hosts): ?><label class="check"><input type="checkbox" name="targets[]" value="backend" checked> im Backend (alle Hosts)</label><?php endif ?>
        <?= submit_button('Passwort setzen', 'btn-sm btn-primary') ?>
      </form>
    </fieldset>
    <?php endif;
    panel_end();
endif;

// ---------------------------------------------------------------- Abgleich
$list = $onlyProblems ? array_filter($matrix, fn ($a) => array_filter($a['issues'], fn ($i) => $i['sev'] !== 'info')) : $matrix;
$tab = fn (string $label, array $params, bool $active) => '<a class="tab' . ($active ? ' active' : '') . '" href="' . h(url(['page' => 'users'] + $params)) . '">' . $label . '</a>';
$tools = $tab('Alle', $showSystem ? ['system' => 1] : [], !$onlyProblems)
    . $tab('Nur Probleme', ['filter' => 'problems'] + ($showSystem ? ['system' => 1] : []), $onlyProblems)
    . $tab($showSystem ? 'Systemkonten ausblenden' : 'Systemkonten zeigen', ($showSystem ? [] : ['system' => 1]) + ($onlyProblems ? ['filter' => 'problems'] : []), false);

panel_start('Kontenabgleich', ['flush' => true, 'tools' => $tools]);
?>
  <div class="toolbar pad">
    <span><?= icon('ok') ?> <?= $counts['ok'] ?> in Ordnung</span>
    <span><?= icon('warning') ?> <?= $counts['missing_proxy'] ?> fehlen in ProxySQL</span>
    <span><?= icon('error') ?> <?= $counts['missing_backend'] ?> fehlen im Backend</span>
    <span><?= icon('error') ?> <?= $counts['pw_mismatch'] ?> Passwort abweichend</span>
    <input type="search" class="filter" placeholder="Filtern …" data-filter-table="#accounts">
  </div>
  <?php if ($b):
      $plan = account_sync_plan();
      $syncOpts = [
          'import'    => [count($plan['import']) . ' fehlende übernehmen', array_keys($plan['import']), true],
          'passwords' => [count($plan['passwords']) . (count($plan['passwords']) === 1 ? ' Passwort' : ' Passwörter') . ' angleichen', array_keys($plan['passwords']), true],
          'delete'    => [count($plan['delete']) . ' nur in ProxySQL vorhandene löschen', $plan['delete'], false],
      ]; ?>
    <div class="sync-bar pad">
      <?php if ($plan['import'] || $plan['passwords'] || $plan['delete']): ?>
        <?= form_open('sync_accounts', ['class' => 'inline-form', 'confirm' => 'Konten abgleichen (Backend → ProxySQL)? Ausgewählte Konten werden in ProxySQL angelegt, geändert bzw. gelöscht.']) ?>
          <b>Abgleich Backend → ProxySQL:</b>
          <?php foreach ($syncOpts as $part => [$label, $names, $default]): ?>
            <label class="check" title="<?= h(implode(', ', $names)) ?>"><input type="checkbox" name="parts[]" value="<?= $part ?>"<?= $names && $default ? ' checked' : '' ?><?= $names ? '' : ' disabled' ?>> <?= h($label) ?></label>
            <?php if ($part === 'import'): ?><label>in <?= hostgroup_select('default_hostgroup', sync_hostgroup()) ?></label><?php endif ?>
          <?php endforeach ?>
          <?= submit_button('Abgleichen', 'btn-sm btn-primary') ?>
        </form>
      <?php else: ?>
        <span><?= icon('ok') ?> Backend und ProxySQL sind abgeglichen.</span>
      <?php endif ?>
      <span class="muted"><?= h(auto_sync_note(['users', 'passwords'])) ?></span>
      <?php if ($plan['skipped']): ?>
        <div class="muted small">Wird übersprungen: <?= h(implode('; ', array_map(fn ($n, $r) => "$n ($r)", array_keys($plan['skipped']), $plan['skipped']))) ?></div>
      <?php endif ?>
    </div>
  <?php endif ?>
  <div class="table-wrap">
  <table class="grid" id="accounts">
    <thead><tr><th>Konto</th><th>ProxySQL</th><th>Hostgroup</th><th>Schema</th><th>Backend-Hosts</th><th>Plugin</th><th>Passwort</th><th>Status</th><th>Aktionen</th></tr></thead>
    <tbody>
    <?php foreach ($list as $acc):
        $p = $acc['proxy'];
        $codes = array_column($acc['issues'], 'code');
        $link = url(['page' => 'users', 'account' => $acc['name']] + ($showSystem ? ['system' => 1] : [])) . '#account'; ?>
      <tr<?= $acc['name'] === $selected ? ' class="selected"' : '' ?>>
        <td><a href="<?= h($link) ?>"><b><?= h($acc['name']) ?></b></a><?= $acc['system'] ? ' ' . badge('System', 'off') : '' ?></td>
        <td><?= $p ? ((int) $p['active'] ? badge('aktiv', 'ok') : badge('inaktiv', 'off')) : badge('fehlt', in_array('missing_proxy', $codes, true) ? 'warn' : 'off') ?></td>
        <td class="num"><?= $p ? h($p['default_hostgroup']) : '' ?></td>
        <td><?= $p ? h($p['default_schema']) : '' ?></td>
        <td class="mono"><?= $acc['hosts'] ? h(implode(', ', array_column($acc['hosts'], 'host'))) : ($b ? badge('fehlt', in_array('missing_backend', $codes, true) ? 'bad' : 'off') : '') ?></td>
        <td class="muted"><?= h(implode(', ', array_unique(array_column($acc['hosts'], 'plugin')))) ?></td>
        <td><?= match ($acc['pw']) { 'ok' => badge('gleich', 'ok'), 'mismatch' => badge('abweichend', 'bad'), 'unknown' => badge('nicht prüfbar', 'off', 'Plugin oder Hash nicht vergleichbar'), default => '' } ?></td>
        <td><?php foreach ($acc['issues'] as $is): ?><div class="issue"><?= sev_icon($is['sev']) ?> <?= h($is['text']) ?></div><?php endforeach ?>
            <?= !$acc['issues'] ? icon('ok') . ' OK' : '' ?></td>
        <td class="actions">
          <?php if (in_array('missing_proxy', $codes, true) && backend_native_hash($acc['name'])): ?>
            <?= action_form('import_proxy', ['name' => $acc['name'], '_back' => url(['page' => 'users'])], 'In ProxySQL übernehmen', ['extra' => hostgroup_select('default_hostgroup', 0), 'btn' => 'btn-sm btn-primary']) ?>
          <?php endif ?>
          <?php if ($p): ?>
            <?= action_form('toggle_active', ['name' => $acc['name']], (int) $p['active'] ? 'Deaktivieren' : 'Aktivieren') ?>
          <?php endif ?>
          <a class="btn btn-sm" href="<?= h($link) ?>"><?= icon('edit') ?> Details</a>
        </td>
      </tr>
    <?php endforeach ?>
    <?php if (!$list): ?><tr><td colspan="9" class="empty">Keine Konten<?= $onlyProblems ? ' mit Problemen' : '' ?></td></tr><?php endif ?>
    </tbody>
  </table>
  </div>
<?php
panel_end();

// ---------------------------------------------------------------- Neues Konto
panel_start('Neues Konto anlegen', ['id' => 'new-account']);
echo form_open('create_account');
$oldChk = fn (string $k, bool $def) => isset($_SESSION['old']) ? !empty($_SESSION['old'][$k]) : $def;
?>
  <div class="form-grid">
    <div class="field"><label>Benutzername</label><input name="name" type="text" required pattern="[A-Za-z0-9_.\-]{1,80}" value="<?= h(old('name')) ?>"></div>
    <div class="field"><label>Passwort</label><input id="pw-new" name="password" type="password" autocomplete="new-password" required></div>
    <div class="field"><label>Passwort wiederholen</label><input id="pw-new2" name="password2" type="password" autocomplete="new-password" required></div>
    <div class="field"><label>&nbsp;</label><button type="button" class="btn" data-genpw="#pw-new" data-genpw2="#pw-new2"><?= icon('key') ?> Passwort generieren</button></div>
  </div>
  <div class="split">
    <fieldset>
      <legend><label class="check"><input type="checkbox" name="in_backend" value="1"<?= $oldChk('in_backend', (bool) $b) ? ' checked' : '' ?><?= $b ? '' : ' disabled' ?>> Im Backend anlegen (CREATE USER + GRANT)</label></legend>
      <div class="form-grid">
        <div class="field"><label>Host</label><input name="host" type="text" value="<?= h(old('host', '%')) ?>"><span class="hint">% = von überall (ProxySQL verbindet sich übers Netzwerk)</span></div>
        <div class="field"><label>Datenbank</label><select name="schema"><?= options($schemaOpts, old('schema', $schemas[0] ?? '*')) ?></select></div>
        <div class="field"><label>Rechte</label><select name="preset"><?= options($presetOpts, old('preset', 'rw')) ?></select></div>
      </div>
    </fieldset>
    <fieldset>
      <legend><label class="check"><input type="checkbox" name="in_proxy" value="1"<?= $oldChk('in_proxy', true) ? ' checked' : '' ?>> In ProxySQL anlegen (mysql_users)</label></legend>
      <div class="form-grid">
        <div class="field"><label>Standard-Hostgroup</label><?= hostgroup_select('default_hostgroup', old('default_hostgroup', 0)) ?></div>
        <div class="field"><label>Standard-Schema</label><input name="default_schema" type="text" placeholder="= gewählte Datenbank" value="<?= h(old('default_schema')) ?>"></div>
        <div class="field"><label>Max. Verbindungen</label><input name="max_connections" type="number" min="0" value="<?= h(old('max_connections', 10000)) ?>"></div>
        <div class="field"><label>Kommentar</label><input name="comment" type="text" value="<?= h(old('comment')) ?>"></div>
        <div class="field checks span2">
          <label class="check"><input type="checkbox" name="transaction_persistent" value="1"<?= $oldChk('transaction_persistent', true) ? ' checked' : '' ?>> transaction_persistent</label>
          <label class="check" title="Nur bei mysql_native_password im Backend; für caching_sha2_password (MySQL 8) Klartext verwenden"><input type="checkbox" name="hash_proxy" value="1"<?= $oldChk('hash_proxy', $b && backend_is_mariadb()) ? ' checked' : '' ?>> Passwort in ProxySQL nur als Hash speichern</label>
        </div>
      </div>
    </fieldset>
  </div>
  <div class="form-actions"><?= submit_button(icon('plus') . ' Konto anlegen') ?></div>
</form>
<?php
panel_end();
layout_end();
