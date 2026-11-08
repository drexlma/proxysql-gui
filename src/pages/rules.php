<?php
// Query-Regeln: Routing, Cache, Umschreiben – mit Treffern und Laufzeit-Abgleich

handle_post(function (string $action) {
    switch ($action) {
        case 'save_rule':
            $values = rule_values_from_post();
            $orig = post('orig_rule_id');
            if ($orig !== '') {
                if ($values['rule_id'] === 'NULL') {
                    $values['rule_id'] = aint($orig, false);
                }
                run(admin(), 'UPDATE mysql_query_rules SET ' . sql_assign($values) . ' WHERE rule_id = ' . aint($orig, false));
            } else {
                if ($values['rule_id'] === 'NULL') {
                    $values['rule_id'] = (string) next_rule_id();
                }
                if (q1(admin(), 'SELECT 1 FROM mysql_query_rules WHERE rule_id = ' . $values['rule_id'])) {
                    throw new InvalidArgumentException('Regel-ID ' . $values['rule_id'] . ' ist bereits vergeben');
                }
                run(admin(), 'INSERT INTO mysql_query_rules (' . implode(', ', array_keys($values)) . ') VALUES (' . implode(', ', $values) . ')');
            }
            flash('ok', 'Regel #' . $values['rule_id'] . ' gespeichert. ' . apply_module('rules'));
            return '?page=rules';

        case 'toggle_rule':
            run(admin(), 'UPDATE mysql_query_rules SET active = 1 - active WHERE rule_id = ' . aint(post('rule_id'), false));
            flash('ok', 'Regel #' . post('rule_id') . ' umgeschaltet. ' . apply_module('rules'));
            return null;

        case 'delete_rule':
            run(admin(), 'DELETE FROM mysql_query_rules WHERE rule_id = ' . aint(post('rule_id'), false));
            flash('ok', 'Regel #' . post('rule_id') . ' gelöscht. ' . apply_module('rules'));
            return '?page=rules';
    }
    throw new InvalidArgumentException('Unbekannte Aktion');
});

$rules = q(admin(), 'SELECT * FROM mysql_query_rules ORDER BY rule_id');
$runtime = [];
foreach (q(admin(), 'SELECT * FROM runtime_mysql_query_rules') as $r) {
    $r['digest'] = $r['digest'] === null ? null : digest_norm($r['digest']);
    $runtime[$r['rule_id']] = $r;
}
$hits = array_column(q(admin(), 'SELECT rule_id, hits FROM stats_mysql_query_rules'), 'hits', 'rule_id');

$edit = isset($_GET['edit']) ? q1(admin(), 'SELECT * FROM mysql_query_rules WHERE rule_id = ' . aint($_GET['edit'], false)) : null;
if ($edit) {
    $f = $edit;
} else {
    // Neue Regel, optional vorbelegt (z. B. aus "Queries & Cache")
    $f = ['rule_id' => '', 'active' => 1, 'apply' => 0, 'flagIN' => 0, 're_modifiers' => 'CASELESS', 'attributes' => ''];
    foreach (RULE_FIELDS as $col => $_) {
        if (isset($_GET[$col]) && is_string($_GET[$col])) {
            $f[$col] = $_GET[$col];
        } elseif (isset($_SESSION['old'][$col])) {
            $f[$col] = $_SESSION['old'][$col];
        }
    }
}

$cell = fn ($v) => $v === null || $v === '' ? '' : h($v);
layout_start('Query-Regeln', 'rules', ['title' => 'Query-Regeln', 'sub' => 'mysql_query_rules – Reihenfolge nach Regel-ID', 'tools' => module_tools('rules')]);

panel_start('Regeln', ['flush' => true, 'tools' => '<a class="btn btn-sm btn-primary" href="?page=rules#rule-form">' . icon('plus') . ' Neue Regel</a>']);
?>
<div class="table-wrap">
<table class="grid">
  <thead><tr>
    <th>ID</th><th>Aktiv</th><th>Benutzer</th><th>Schema</th><th>flagIN→OUT</th><th>Digest</th><th>Match</th>
    <th>Ziel-HG</th><th>Cache-TTL</th><th>Apply</th><th>Treffer</th><th>Laufzeit</th><th>Kommentar</th><th>Aktionen</th>
  </tr></thead>
  <tbody>
  <?php foreach ($rules as $r):
      $norm = $r;
      $norm['digest'] = $r['digest'] === null ? null : digest_norm($r['digest']);
      $rt = $runtime[$r['rule_id']] ?? null;
      $match = array_filter([
          $r['match_digest'] !== null ? 'digest ~ ' . $r['match_digest'] : null,
          $r['match_pattern'] !== null ? 'query ~ ' . $r['match_pattern'] : null,
      ]); ?>
    <tr<?= (int) $r['active'] ? '' : ' class="muted-row"' ?>>
      <td class="num"><b><?= h($r['rule_id']) ?></b></td>
      <td><?= yesno($r['active']) ?></td>
      <td><?= $cell($r['username']) ?></td>
      <td><?= $cell($r['schemaname']) ?></td>
      <td class="num"><?= h($r['flagIN']) ?><?= $r['flagOUT'] !== null ? ' → ' . h($r['flagOUT']) : '' ?></td>
      <td class="mono"><?= $cell($r['digest']) ?></td>
      <td class="mono"><?= (int) $r['negate_match_pattern'] ? 'NICHT ' : '' ?><?= h(implode(' · ', $match)) ?></td>
      <td class="num"><?= $cell($r['destination_hostgroup']) ?></td>
      <td class="num"><?= $r['cache_ttl'] !== null ? h(fmt_ttl($r['cache_ttl'])) : '' ?></td>
      <td><?= yesno($r['apply']) ?></td>
      <td class="num"><?= nf($hits[$r['rule_id']] ?? null) ?></td>
      <td><?= !$rt ? badge('nicht aktiv', 'warn') : ($rt == $norm ? badge('aktiv', 'ok') : badge('geändert', 'warn', 'Memory weicht von der Laufzeit ab')) ?></td>
      <td><?= $cell($r['comment']) ?><?= $r['error_msg'] !== null ? ' ' . badge('blockiert', 'bad', $r['error_msg']) : '' ?><?= $r['replace_pattern'] !== null ? ' ' . badge('schreibt um', 'info') : '' ?></td>
      <td class="actions">
        <a class="btn btn-sm" href="<?= h(url(['page' => 'rules', 'edit' => $r['rule_id']])) ?>#rule-form"><?= icon('edit') ?></a>
        <?= action_form('toggle_rule', ['rule_id' => $r['rule_id']], (int) $r['active'] ? 'Deaktivieren' : 'Aktivieren') ?>
        <?= action_form('delete_rule', ['rule_id' => $r['rule_id']], icon('trash'), ['confirm' => 'Regel #' . $r['rule_id'] . ' löschen?', 'btn' => 'btn-sm btn-danger']) ?>
      </td>
    </tr>
  <?php endforeach ?>
  <?php if (!$rules): ?><tr><td colspan="14" class="empty">Keine Query-Regeln – alle Queries gehen an die Standard-Hostgroup des Kontos.</td></tr><?php endif ?>
  </tbody>
</table>
</div>
<?php
panel_end();

// ---------------------------------------------------------------- Formular
panel_start($edit ? 'Regel #' . $edit['rule_id'] . ' bearbeiten' : 'Neue Regel', ['id' => 'rule-form']);
echo form_open('save_rule');
if ($edit) {
    echo hidden('orig_rule_id', $edit['rule_id']);
}

function rule_field(string $col, array $def, array $f): string
{
    [$label, $type] = $def;
    $v = $f[$col] ?? null;
    $name = h($col);
    if ($type === 'bool') {
        return '<div class="field checks"><label class="check"><input type="checkbox" name="' . $name . '" value="1"' . ((int) $v ? ' checked' : '') . '> ' . h($label) . '</label></div>';
    }
    $wide = in_array($col, ['comment', 'match_digest', 'match_pattern', 'replace_pattern', 'error_msg', 'attributes'], true) ? ' span2' : '';
    $html = '<div class="field' . $wide . '"><label>' . h($label) . '</label>';
    if ($type === 'tri') {
        $html .= '<select name="' . $name . '">' . options(['' => 'Standard', '0' => 'Nein', '1' => 'Ja'], $v ?? '') . '</select>';
    } elseif ($type === 'hg') {
        $html .= hostgroup_select($col, $v, true);
    } elseif (str_starts_with($type, 'sel:')) {
        $html .= '<select name="' . $name . '">' . options(RULE_SELECTS[substr($type, 4)], $v ?? '') . '</select>';
    } elseif (in_array($type, ['int', 'int0'], true)) {
        $html .= '<input name="' . $name . '" type="number" value="' . h($v) . '"' . ($col === 'rule_id' ? ' placeholder="automatisch"' : '') . '>';
    } else {
        $html .= '<input name="' . $name . '" type="text" value="' . h($v) . '"' . ($col === 'match_digest' ? ' placeholder="^SELECT .* FROM products"' : '') . '>';
    }
    return $html . '</div>';
}

$groups = [];
foreach (RULE_FIELDS as $col => $def) {
    $groups[$def[2]][] = rule_field($col, $def, $f);
}
?>
  <?php foreach (['Grundlagen', 'Bedingungen', 'Aktionen'] as $gname): ?>
    <fieldset><legend><?= h($gname) ?></legend><div class="form-grid"><?= implode('', $groups[$gname]) ?></div></fieldset>
  <?php endforeach ?>
  <details<?= $edit && array_filter(array_intersect_key($edit, array_filter(RULE_FIELDS, fn ($d) => $d[2] === 'Erweitert')), fn ($v) => $v !== null && $v !== '') ? ' open' : '' ?>>
    <summary>Erweitert</summary>
    <div class="form-grid pad-top"><?= implode('', $groups['Erweitert']) ?></div>
  </details>
  <p class="hint">Regeln werden nach Regel-ID abgearbeitet. Eine passende Regel mit <b>Apply</b> beendet die Verarbeitung –
    Cache-Regeln daher mit niedrigerer ID als allgemeine Routing-Regeln anlegen (oder ohne Apply).</p>
  <div class="form-actions">
    <?= submit_button(icon('check') . ' Speichern und aktivieren') ?>
    <?php if ($edit): ?><a class="btn" href="?page=rules">Abbrechen</a><?php endif ?>
  </div>
</form>
<?php
panel_end();
layout_end();
