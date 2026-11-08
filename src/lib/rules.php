<?php
// Query-Regeln: Felddefinitionen und Simulation der Regelkette für Digests

/**
 * Editierbare Spalten von mysql_query_rules: [Beschriftung, Typ, Gruppe]
 * Typen: int (leer = NULL), int0 (leer = 0), bool (Checkbox 0/1), tri (leer/0/1), str (leer = NULL), str0 (leer = ''), sel:<key>
 */
const RULE_FIELDS = [
    'rule_id'               => ['Regel-ID', 'int', 'Grundlagen'],
    'active'                => ['Aktiv', 'bool', 'Grundlagen'],
    'apply'                 => ['Apply – danach keine weiteren Regeln', 'bool', 'Grundlagen'],
    'comment'               => ['Kommentar', 'str', 'Grundlagen'],
    'username'              => ['Benutzer', 'str', 'Bedingungen'],
    'schemaname'            => ['Schema', 'str', 'Bedingungen'],
    'digest'                => ['Digest (z. B. 0x1A2B…)', 'str', 'Bedingungen'],
    'match_digest'          => ['match_digest – Regex auf den Digest-Text', 'str', 'Bedingungen'],
    'match_pattern'         => ['match_pattern – Regex auf die Query', 'str', 'Bedingungen'],
    'negate_match_pattern'  => ['Regex negieren', 'bool', 'Bedingungen'],
    're_modifiers'          => ['Regex-Optionen', 'sel:re', 'Bedingungen'],
    'flagIN'                => ['flagIN', 'int0', 'Bedingungen'],
    'client_addr'           => ['Client-Adresse', 'str', 'Bedingungen'],
    'proxy_addr'            => ['Proxy-Adresse', 'str', 'Bedingungen'],
    'proxy_port'            => ['Proxy-Port', 'int', 'Bedingungen'],
    'destination_hostgroup' => ['Ziel-Hostgroup', 'hg', 'Aktionen'],
    'cache_ttl'             => ['Cache-TTL (ms)', 'int', 'Aktionen'],
    'cache_empty_result'    => ['Leere Ergebnisse cachen', 'tri', 'Aktionen'],
    'cache_timeout'         => ['Cache-Timeout (ms)', 'int', 'Aktionen'],
    'timeout'               => ['Query-Timeout (ms)', 'int', 'Aktionen'],
    'flagOUT'               => ['flagOUT', 'int', 'Aktionen'],
    'multiplex'             => ['Multiplexing', 'sel:multiplex', 'Aktionen'],
    'log'                   => ['Query loggen', 'tri', 'Aktionen'],
    'replace_pattern'       => ['replace_pattern – Query umschreiben', 'str', 'Erweitert'],
    'retries'               => ['Wiederholungen', 'int', 'Erweitert'],
    'delay'                 => ['Verzögerung (ms)', 'int', 'Erweitert'],
    'reconnect'             => ['Reconnect', 'tri', 'Erweitert'],
    'sticky_conn'           => ['Sticky Connection', 'tri', 'Erweitert'],
    'next_query_flagIN'     => ['next_query_flagIN', 'int', 'Erweitert'],
    'mirror_flagOUT'        => ['mirror_flagOUT', 'int', 'Erweitert'],
    'mirror_hostgroup'      => ['Mirror-Hostgroup', 'int', 'Erweitert'],
    'gtid_from_hostgroup'   => ['gtid_from_hostgroup', 'int', 'Erweitert'],
    'error_msg'             => ['Fehlermeldung (Query blockieren)', 'str', 'Erweitert'],
    'OK_msg'                => ['OK-Meldung', 'str', 'Erweitert'],
    'attributes'            => ['Attribute (JSON)', 'str0', 'Erweitert'],
];

const RULE_SELECTS = [
    're'        => ['CASELESS' => 'CASELESS', '' => 'keine', 'CASELESS,GLOBAL' => 'CASELESS,GLOBAL', 'GLOBAL' => 'GLOBAL'],
    'multiplex' => ['' => 'Standard', '0' => '0 – aus', '1' => '1 – an', '2' => '2 – nicht wegen dieser Query abschalten'],
];

const CACHE_TTLS = [1000 => '1 s', 5000 => '5 s', 10000 => '10 s', 30000 => '30 s', 60000 => '1 Min.', 300000 => '5 Min.', 3600000 => '1 Std.'];

/** Formularwerte → SQL-Literale */
function rule_values_from_post(): array
{
    $v = [];
    foreach (RULE_FIELDS as $col => [, $type]) {
        $raw = post($col);
        $v[$col] = match (true) {
            $type === 'bool'               => checked($col) ? '1' : '0',
            $type === 'int0'               => aint($raw === '' ? '0' : $raw, false),
            in_array($type, ['int', 'tri', 'hg', 'sel:multiplex'], true) => aint($raw),
            $type === 'str0'               => aq($raw),
            default                        => aq($raw === '' ? null : $raw),
        };
    }
    return $v;
}

/** Nächste freie Regel-ID (in 10er-Schritten) */
function next_rule_id(): int
{
    return (intdiv((int) qv(admin(), 'SELECT MAX(rule_id) FROM mysql_query_rules'), 10) + 1) * 10;
}

function digest_norm(?string $d): string
{
    return strtoupper(ltrim((string) preg_replace('/^0x/i', '', (string) $d), '0'));
}

/** Regeln, die ProxySQL gerade verwendet */
function runtime_rules(): array
{
    static $rules = null;
    return $rules ??= q(admin(), 'SELECT * FROM runtime_mysql_query_rules WHERE active = 1 ORDER BY rule_id');
}

/** Regex wie ProxySQL (RE2/PCRE) – null, wenn PHP den Ausdruck nicht versteht */
function regex_match(string $pattern, string $subject, ?string $mods): ?bool
{
    $flags = stripos((string) $mods, 'CASELESS') !== false ? 'i' : '';
    $r = @preg_match('~' . str_replace('~', '\~', $pattern) . '~' . $flags, $subject);
    return $r === false ? null : $r === 1;
}

/** Passt die Regel auf den Digest? null = nicht bestimmbar (z. B. client_addr) */
function rule_matches(array $r, array $d): ?bool
{
    if ($r['username'] !== null && $r['username'] !== $d['username']) {
        return false;
    }
    if ($r['schemaname'] !== null && $r['schemaname'] !== $d['schemaname']) {
        return false;
    }
    if ($r['digest'] !== null && digest_norm($r['digest']) !== digest_norm($d['digest'])) {
        return false;
    }
    foreach (['match_digest', 'match_pattern'] as $f) {
        if ($r[$f] === null) {
            continue;
        }
        // match_pattern prüft eigentlich die Original-Query; der Digest-Text ist eine gute Näherung
        $m = regex_match($r[$f], $d['digest_text'], $r['re_modifiers']);
        if ($m === null) {
            return null;
        }
        if ((int) $r['negate_match_pattern']) {
            $m = !$m;
        }
        if (!$m) {
            return false;
        }
    }
    if ($r['client_addr'] !== null || $r['proxy_addr'] !== null || $r['proxy_port'] !== null) {
        return null;
    }
    return true;
}

/**
 * Regelkette für einen Digest durchspielen (Reihenfolge rule_id, flagIN/flagOUT, apply).
 * Ergebnis: rules (getroffene IDs), cache_ttl, cache_rule, hostgroup, applied (ID der apply-Regel), uncertain
 */
function simulate_rules(array $d): array
{
    $res = ['rules' => [], 'cache_ttl' => null, 'cache_rule' => null, 'hostgroup' => null, 'applied' => null, 'uncertain' => false];
    $flag = 0;
    foreach (runtime_rules() as $r) {
        if ((int) $r['flagIN'] !== $flag) {
            continue;
        }
        $m = rule_matches($r, $d);
        if ($m === null) {
            $res['uncertain'] = true;
            continue;
        }
        if (!$m) {
            continue;
        }
        $res['rules'][] = (int) $r['rule_id'];
        if ($r['cache_ttl'] !== null) {
            $res['cache_ttl'] = (int) $r['cache_ttl'];
            $res['cache_rule'] = (int) $r['rule_id'];
        }
        if ($r['destination_hostgroup'] !== null) {
            $res['hostgroup'] = (int) $r['destination_hostgroup'];
        }
        if ($r['flagOUT'] !== null) {
            $flag = (int) $r['flagOUT'];
        }
        if ((int) $r['apply']) {
            $res['applied'] = (int) $r['rule_id'];
            break;
        }
    }
    return $res;
}

/**
 * Regel-ID für eine neue Cache-Regel: vor einer greifenden apply-Regel einsortieren,
 * sonst ans Ende
 */
function suggest_cache_rule_id(array $sim): int
{
    if ($sim['applied'] !== null) {
        $used = array_map('intval', array_column(q(admin(), 'SELECT rule_id FROM mysql_query_rules'), 'rule_id'));
        for ($id = $sim['applied'] - 1; $id >= 1; $id--) {
            if (!in_array($id, $used, true)) {
                return $id;
            }
        }
    }
    return next_rule_id();
}

/** Lässt sich das Ergebnis dieser Query sinnvoll cachen? */
function cacheable(string $digestText): bool
{
    return preg_match('/^\s*SELECT\b/i', $digestText) === 1
        && stripos($digestText, ' FROM ') !== false
        && !preg_match('/\bFOR\s+UPDATE\b|\bLOCK\s+IN\s+SHARE\b|@@|LAST_INSERT_ID|CONNECTION_ID|\b(CURRENT_)?USER\s*\(|\bDATABASE\s*\(|\bNOW\s*\(|\bRAND\s*\(|\bUUID\s*\(|\bSLEEP\s*\(/i', $digestText);
}

/** Häufige, cachebare Queries ohne Cache-Regel */
function cache_candidates(int $limit, int $minCount = 100): array
{
    $rows = q(admin(), "SELECT digest, MIN(digest_text) AS digest_text, MIN(username) AS username, MIN(schemaname) AS schemaname, SUM(count_star) AS count_star
                        FROM stats_mysql_query_digest WHERE hostgroup >= 0 AND digest_text LIKE 'SELECT%'
                        GROUP BY digest ORDER BY count_star DESC LIMIT 25");
    $out = [];
    foreach ($rows as $d) {
        if ((int) $d['count_star'] < $minCount || count($out) >= $limit) {
            break;
        }
        if (cacheable($d['digest_text']) && simulate_rules($d)['cache_ttl'] === null) {
            $out[] = $d;
        }
    }
    return $out;
}
