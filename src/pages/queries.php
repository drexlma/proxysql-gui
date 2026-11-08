<?php
// Queries & Cache: Query-Digests, Regel-Simulation, Cache-Regeln mit einem Klick

handle_post(function (string $action) {
    switch ($action) {
        case 'cache_digest':
            $digest = post('digest');
            if (!preg_match('/^0x[0-9A-Fa-f]{1,16}$/', $digest)) {
                throw new InvalidArgumentException('Ungültiger Digest');
            }
            $ttl = (int) aint(post('ttl'), false);
            if ($ttl <= 0) {
                throw new InvalidArgumentException('TTL muss größer als 0 sein');
            }
            $id = (int) aint(post('rule_id'), false);
            if ($id <= 0 || q1(admin(), "SELECT 1 FROM mysql_query_rules WHERE rule_id = $id")) {
                $id = next_rule_id();
            }
            run(admin(), "INSERT INTO mysql_query_rules (rule_id, active, digest, cache_ttl, apply, comment) VALUES ($id, 1, "
                . aq('0x' . strtoupper(substr($digest, 2))) . ", $ttl, 0, "
                . aq('GUI-Cache: ' . mb_strimwidth(post('text'), 0, 80, '…')) . ')');
            flash('ok', "Cache-Regel #$id angelegt (TTL " . fmt_ttl($ttl) . '). ' . apply_module('rules'));
            return null;

        case 'uncache':
            $id = aint(post('rule_id'), false);
            $r = q1(admin(), "SELECT * FROM mysql_query_rules WHERE rule_id = $id") ?? throw new InvalidArgumentException("Regel #$id nicht gefunden");
            if (str_starts_with((string) $r['comment'], 'GUI-Cache:')) {
                run(admin(), "DELETE FROM mysql_query_rules WHERE rule_id = $id");
                $msg = "Cache-Regel #$id gelöscht.";
            } else {
                run(admin(), "UPDATE mysql_query_rules SET cache_ttl = NULL WHERE rule_id = $id");
                $msg = "Cache-TTL aus Regel #$id entfernt.";
            }
            flash('ok', $msg . ' ' . apply_module('rules'));
            return null;

        case 'flush_cache':
            run(admin(), 'PROXYSQL FLUSH QUERY CACHE');
            flash('ok', 'Query-Cache geleert.');
            return null;

        case 'reset_digests':
            q(admin(), 'SELECT COUNT(*) FROM stats_mysql_query_digest_reset');
            flash('ok', 'Query-Digest-Statistik zurückgesetzt.');
            return null;

        case 'cache_size':
            $mb = aint(post('size'), false);
            run(admin(), 'UPDATE global_variables SET variable_value = ' . aq($mb) . " WHERE variable_name = 'mysql-query_cache_size_MB'");
            flash('ok', "Query-Cache-Größe auf $mb MB gesetzt. " . apply_module('mysqlvars'));
            return null;
    }
    throw new InvalidArgumentException('Unbekannte Aktion');
});

const DIGEST_SORT = [
    'sum_time'   => ['Gesamtzeit', 'sum_time'],
    'count_star' => ['Anzahl', 'count_star'],
    'avg'        => ['Ø Zeit', 'sum_time * 1.0 / count_star'],
    'max_time'   => ['Max. Zeit', 'max_time'],
    'last_seen'  => ['Zuletzt', 'last_seen'],
    'rows'       => ['Zeilen', 'sum_rows_sent'],
];

$q = trim((string) ($_GET['q'] ?? ''));
$sort = isset(DIGEST_SORT[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'sum_time';
$limit = (int) ($_GET['limit'] ?? 50);
$limit = in_array($limit, [25, 50, 100, 250], true) ? $limit : 50;
$onlySelect = !empty($_GET['select']);

$where = [];
if ($q !== '') {
    $where[] = preg_match('/^0x[0-9A-F]+$/i', $q)
        ? 'digest = ' . aq('0x' . strtoupper(substr($q, 2)))
        : 'instr(lower(digest_text), lower(' . aq($q) . ')) > 0';
}
if ($onlySelect) {
    $where[] = "digest_text LIKE 'SELECT%'";
}
$digests = q(admin(), 'SELECT digest, MIN(digest_text) AS digest_text,
        GROUP_CONCAT(DISTINCT username) AS users, GROUP_CONCAT(DISTINCT schemaname) AS schemas,
        MIN(username) AS username, MIN(schemaname) AS schemaname,
        GROUP_CONCAT(DISTINCT CASE WHEN hostgroup >= 0 THEN hostgroup END) AS hostgroups,
        SUM(count_star) AS count_star, SUM(CASE WHEN hostgroup = -1 THEN count_star ELSE 0 END) AS cache_hits,
        SUM(sum_time) AS sum_time, MIN(min_time) AS min_time, MAX(max_time) AS max_time,
        MIN(first_seen) AS first_seen, MAX(last_seen) AS last_seen, SUM(sum_rows_sent) AS sum_rows_sent
    FROM stats_mysql_query_digest' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . '
    GROUP BY digest ORDER BY ' . DIGEST_SORT[$sort][1] . " DESC LIMIT $limit");

$g = stats_global();
$get = (int) ($g['Query_Cache_count_GET'] ?? 0);
$getOk = (int) ($g['Query_Cache_count_GET_OK'] ?? 0);
$sizeMb = (int) global_var('mysql-query_cache_size_MB');
$usedPct = $sizeMb ? min(100, 100 * (int) ($g['Query_Cache_Memory_bytes'] ?? 0) / ($sizeMb * 1048576)) : 0;
$userHg = array_column(q(admin(), 'SELECT username, default_hostgroup FROM mysql_users WHERE frontend = 1'), 'default_hostgroup', 'username');

layout_start('Queries & Cache', 'queries', ['title' => 'Queries & Query-Cache', 'sub' => 'stats_mysql_query_digest · Query-Cache', 'tools' => module_tools('rules')]);
?>
<div class="split split-wide">
<?php panel_start('Query-Cache') ?>
  <div class="kpi-row">
    <div><span>Trefferquote</span><b><?= $get ? nf(100 * $getOk / $get, 1) . ' %' : '–' ?></b></div>
    <div><span>Einträge</span><b><?= nf($g['Query_Cache_Entries'] ?? 0) ?></b></div>
    <div><span>Speicher</span><b><?= h(fmt_bytes($g['Query_Cache_Memory_bytes'] ?? 0)) ?></b></div>
    <div><span>Abfragen / Treffer</span><b><?= nf($get) ?> / <?= nf($getOk) ?></b></div>
    <div><span>Gespeichert</span><b><?= nf($g['Query_Cache_count_SET'] ?? 0) ?></b></div>
    <div><span>Verdrängt</span><b><?= nf($g['Query_Cache_Purged'] ?? 0) ?></b></div>
    <div><span>Ausgeliefert</span><b><?= h(fmt_bytes($g['Query_Cache_bytes_OUT'] ?? 0)) ?></b></div>
  </div>
  <div class="meter" title="Belegung des Query-Cache"><span style="width: <?= round($usedPct, 1) ?>%"></span></div>
  <div class="toolbar pad-top">
    <?= form_open('cache_size', ['class' => 'inline-form']) ?>
      <label>Max. Größe <input name="size" type="number" min="1" value="<?= $sizeMb ?>" class="w-num"> MB</label>
      <?= submit_button('Übernehmen', 'btn-sm') ?>
    </form>
    <?= action_form('flush_cache', [], icon('trash') . ' Cache leeren', ['confirm' => 'Alle Einträge im Query-Cache verwerfen?']) ?>
  </div>
<?php panel_end() ?>

<?php
$cacheRules = q(admin(), 'SELECT r.rule_id, r.digest, r.match_digest, r.match_pattern, r.username, r.cache_ttl, r.comment, s.hits
    FROM mysql_query_rules r LEFT JOIN stats_mysql_query_rules s USING (rule_id) WHERE r.cache_ttl IS NOT NULL AND r.active = 1 ORDER BY r.rule_id');
panel_start('Aktive Cache-Regeln', ['flush' => true]);
echo data_table($cacheRules, [
    'cols'   => ['rule_id' => 'ID', 'digest' => 'Bedingung', 'cache_ttl' => 'TTL', 'hits' => 'Treffer', 'comment' => 'Kommentar', '_a' => ''],
    'format' => [
        'rule_id'   => fn ($v) => '<a href="' . h(url(['page' => 'rules', 'edit' => $v])) . '#rule-form">#' . h($v) . '</a>',
        'digest'    => fn ($v, $r) => '<span class="mono">' . h(implode(' · ', array_filter([$r['digest'], $r['match_digest'] ? '~ ' . $r['match_digest'] : null, $r['match_pattern'] ? '~ ' . $r['match_pattern'] : null, $r['username'] ? 'user=' . $r['username'] : null]))) . '</span>',
        'cache_ttl' => fn ($v) => '<span class="nowrap">' . h(fmt_ttl($v)) . '</span>',
        'hits'      => fn ($v) => nf($v),
        '_a'        => fn ($v, $r) => action_form('uncache', ['rule_id' => $r['rule_id']], 'Entfernen', ['confirm' => 'Cache für Regel #' . $r['rule_id'] . ' entfernen?']),
    ],
    'empty'  => 'Noch keine Cache-Regeln – unten bei einer Query auf „Cachen“ klicken.',
]);
panel_end();
?>
</div>
<?php
$tools = action_form('reset_digests', [], 'Statistik zurücksetzen', ['confirm' => 'Query-Digest-Statistik zurücksetzen?']);
panel_start('Query-Digests (Top ' . $limit . ' nach ' . DIGEST_SORT[$sort][0] . ')', ['flush' => true, 'tools' => $tools]);
?>
  <form method="get" class="toolbar pad">
    <input type="hidden" name="page" value="queries">
    <label>Suche <input name="q" type="search" value="<?= h($q) ?>" placeholder="Text oder 0x-Digest"></label>
    <label>Sortierung <select name="sort" data-autosubmit><?= options(array_map(fn ($s) => $s[0], DIGEST_SORT), $sort) ?></select></label>
    <label>Anzahl <select name="limit" data-autosubmit><?= options([25 => 25, 50 => 50, 100 => 100, 250 => 250], $limit) ?></select></label>
    <label class="check"><input type="checkbox" name="select" value="1" data-autosubmit<?= $onlySelect ? ' checked' : '' ?>> nur SELECT</label>
    <button class="btn btn-sm" type="submit">Anzeigen</button>
    <?php if ($q !== ''): ?><a class="btn btn-sm" href="?page=queries">Zurücksetzen</a><?php endif ?>
  </form>
  <div class="table-wrap">
  <table class="grid digests">
    <thead><tr>
      <th>Query (Digest-Text)</th><th>Anzahl</th><th>aus Cache</th><th>Gesamtzeit</th><th>Ø Zeit</th><th>Max.</th><th>Zeilen</th>
      <th>Benutzer / Schema</th><th>HG</th><th>Zuletzt</th><th>Regeln</th><th>Cache</th>
    </tr></thead>
    <tbody>
    <?php foreach ($digests as $d):
        $sim = simulate_rules($d);
        $avg = $d['count_star'] ? $d['sum_time'] / $d['count_star'] : 0;
        $hg = $sim['hostgroup'] ?? ($userHg[$d['username']] ?? null); ?>
      <tr>
        <td class="digest-cell">
          <div class="digest-text mono" title="<?= h($d['digest_text']) ?>"><?= h($d['digest_text']) ?></div>
          <div class="muted mono small"><?= h($d['digest']) ?></div>
        </td>
        <td class="num"><?= nf($d['count_star']) ?></td>
        <td class="num"><?= (int) $d['cache_hits'] ? nf($d['cache_hits']) . ' <span class="muted">(' . nf(100 * $d['cache_hits'] / $d['count_star'], 0) . ' %)</span>' : '' ?></td>
        <td class="num"><?= h(fmt_us($d['sum_time'])) ?></td>
        <td class="num"><?= h(fmt_us($avg)) ?></td>
        <td class="num"><?= h(fmt_us($d['max_time'])) ?></td>
        <td class="num"><?= nf($d['sum_rows_sent']) ?></td>
        <td><?= h($d['users']) ?> <span class="muted">/ <?= h($d['schemas']) ?></span></td>
        <td class="num" title="Ziel laut Regeln bzw. Standard-Hostgroup des Kontos"><?= h($hg) ?></td>
        <td class="nowrap"><?= h(fmt_ago((int) $d['last_seen'])) ?></td>
        <td class="nowrap"><?php foreach ($sim['rules'] as $rid): ?><a href="<?= h(url(['page' => 'rules', 'edit' => $rid])) ?>#rule-form">#<?= $rid ?></a> <?php endforeach ?><?= $sim['uncertain'] ? '<span class="muted" title="Mindestens eine Regel konnte nicht ausgewertet werden (client_addr, Regex)">?</span>' : '' ?></td>
        <td class="actions">
          <?php if ($sim['cache_ttl'] !== null): ?>
            <?= badge(fmt_ttl($sim['cache_ttl']), 'ok', 'Gecacht durch Regel #' . $sim['cache_rule']) ?>
            <?= action_form('uncache', ['rule_id' => $sim['cache_rule']], icon('cross'), ['confirm' => 'Cache für Regel #' . $sim['cache_rule'] . ' entfernen?']) ?>
          <?php elseif (cacheable($d['digest_text'])): ?>
            <?= action_form('cache_digest', ['digest' => $d['digest'], 'rule_id' => suggest_cache_rule_id($sim), 'text' => $d['digest_text']], icon('cache') . ' Cachen', [
                'extra' => '<select name="ttl" title="Cache-TTL">' . options(CACHE_TTLS, 10000) . '</select> ',
                'btn'   => 'btn-sm btn-primary',
            ]) ?>
            <?php if ($sim['applied'] !== null): ?><span class="muted small" title="Neue Regel wird vor Regel #<?= $sim['applied'] ?> (apply) einsortiert">vor #<?= $sim['applied'] ?></span><?php endif ?>
          <?php else: ?>
            <a class="btn btn-sm" href="<?= h(url(['page' => 'rules', 'digest' => $d['digest'], 'comment' => mb_strimwidth($d['digest_text'], 0, 60, '…')])) ?>#rule-form" title="Regel für diesen Digest anlegen (z. B. Routing)">Regel …</a>
          <?php endif ?>
        </td>
      </tr>
    <?php endforeach ?>
    <?php if (!$digests): ?><tr><td colspan="12" class="empty">Keine Queries<?= $q !== '' ? ' für „' . h($q) . '“' : '' ?> – ist mysql-query_digests aktiv?</td></tr><?php endif ?>
    </tbody>
  </table>
  </div>
<?php
panel_end();
layout_end();
