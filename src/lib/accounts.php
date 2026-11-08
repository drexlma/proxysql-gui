<?php
// Konten: Abgleich ProxySQL (mysql_users) ↔ Backend (mysql.user), Anlegen und Ändern

const PRIV_PRESETS = [
    'ro'   => ['Nur lesen', 'SELECT, SHOW VIEW'],
    'rw'   => ['Lesen + Schreiben', 'SELECT, INSERT, UPDATE, DELETE, EXECUTE, CREATE TEMPORARY TABLES, LOCK TABLES'],
    'ddl'  => ['Schreiben + DDL', 'SELECT, INSERT, UPDATE, DELETE, EXECUTE, CREATE TEMPORARY TABLES, LOCK TABLES, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW, TRIGGER, CREATE ROUTINE, ALTER ROUTINE, EVENT'],
    'all'  => ['Alle Rechte', 'ALL PRIVILEGES'],
    'none' => ['Keine (nur Login)', ''],
];

const SYSTEM_SCHEMAS = ['information_schema', 'performance_schema', 'mysql', 'sys'];

const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];

function native_hash(string $password): string
{
    return '*' . strtoupper(sha1(sha1($password, true)));
}

function is_native_hash(?string $pw): bool
{
    return $pw !== null && preg_match('/^\*[0-9A-F]{40}$/i', $pw) === 1;
}

function valid_username(string $u): string
{
    if (!preg_match('/^[A-Za-z0-9_.\-]{1,80}$/', $u)) {
        throw new InvalidArgumentException('Ungültiger Benutzername (erlaubt: A–Z, a–z, 0–9, _ . -, max. 80 Zeichen)');
    }
    return $u;
}

function valid_host(string $h): string
{
    if (!preg_match('/^[A-Za-z0-9_.%:\-\/]{1,255}$/', $h)) {
        throw new InvalidArgumentException('Ungültiger Host (z. B. %, 10.0.% oder app.example.com)');
    }
    return $h;
}

function valid_schema(string $s): string
{
    if ($s !== '*' && !in_array($s, backend_schemas(), true)) {
        throw new InvalidArgumentException("Unbekannte Datenbank: $s");
    }
    return $s;
}

function grant_target(string $schema): string
{
    return $schema === '*' ? '*.*' : bident($schema) . '.*';
}

function preset_privs(string $preset): string
{
    return PRIV_PRESETS[$preset][1] ?? throw new InvalidArgumentException('Unbekannte Rechte-Vorlage');
}

function backend_is_mariadb(): bool
{
    static $m = null;
    return $m ??= stripos((string) qv(backend(), 'SELECT VERSION()'), 'mariadb') !== false;
}

function backend_schemas(): array
{
    static $s = null;
    return $s ??= array_values(array_diff(array_column(q(backend(), 'SHOW DATABASES'), 'Database'), SYSTEM_SCHEMAS));
}

/** Backend-Konten gruppiert nach Benutzer: [user => [[user, host, plugin, auth], ...]] */
function backend_accounts(): array
{
    static $acc = null;
    if ($acc !== null) {
        return $acc;
    }
    $sql = 'SELECT User AS user, Host AS host, plugin, authentication_string AS auth, Repl_slave_priv AS repl, Select_priv AS sel FROM mysql.user';
    try {
        $rows = q(backend(), $sql . " WHERE is_role = 'N' ORDER BY User, Host");
    } catch (mysqli_sql_exception) {
        $rows = q(backend(), $sql . ' ORDER BY User, Host'); // MySQL kennt is_role nicht
    }
    $acc = [];
    foreach ($rows as $r) {
        $acc[$r['user']][] = $r;
    }
    return $acc;
}

function backend_hosts(string $name): array
{
    return backend() ? (backend_accounts()[$name] ?? []) : [];
}

/** mysql_native_password-Hash aus dem Backend (für die Übernahme nach ProxySQL) */
function backend_native_hash(string $name): ?string
{
    foreach (backend_hosts($name) as $h) {
        if (in_array($h['plugin'], ['mysql_native_password', ''], true) && is_native_hash($h['auth'])) {
            return strtoupper($h['auth']);
        }
    }
    return null;
}

function proxy_user(string $name): ?array
{
    return q1(admin(), 'SELECT * FROM mysql_users WHERE username = ' . aq($name) . ' ORDER BY frontend DESC LIMIT 1');
}

function ignored_users(): array
{
    return array_unique(array_merge(cfg('ignore_users'), [(string) global_var('mysql-monitor_username'), cfg('backend')['user']]));
}

/** Reines Replikationskonto (REPLICATION SLAVE ohne globale Leserechte) – braucht keinen Eintrag in ProxySQL */
function is_replication_account(array $hosts): bool
{
    return $hosts && !array_filter($hosts, fn ($h) => ($h['repl'] ?? 'N') !== 'Y' || ($h['sel'] ?? 'N') === 'Y');
}

/**
 * Passwortvergleich ProxySQL ↔ Backend: ok | mismatch | unknown
 * ProxySQL speichert Klartext oder den mysql_native_password-Hash (*ABC…).
 */
function password_state(?string $proxyPw, array $hosts): string
{
    $pw = (string) $proxyPw;
    if (str_starts_with($pw, '$')) {
        return 'unknown'; // caching_sha2-Hash, nicht vergleichbar
    }
    $hash = $pw === '' ? '' : (is_native_hash($pw) ? strtoupper($pw) : native_hash($pw));
    $native = array_filter($hosts, fn ($h) => in_array($h['plugin'], ['mysql_native_password', ''], true));
    if (!$native) {
        return 'unknown';
    }
    foreach ($native as $h) {
        if (strtoupper((string) $h['auth']) === $hash) {
            return 'ok';
        }
    }
    return 'mismatch';
}

/**
 * Alle Konten beider Systeme mit Problemen.
 * Eintrag: name, proxy (Zeile|null), hosts (Backend-Zeilen), system, pw, issues [code, sev, text]
 */
function account_matrix(bool $withSystem = false): array
{
    $proxy = [];
    foreach (q(admin(), 'SELECT * FROM mysql_users ORDER BY username, frontend DESC') as $u) {
        $proxy[$u['username']] ??= $u;
    }
    $back = backend() ? backend_accounts() : null;
    $ignore = ignored_users();
    $online = online_hostgroups();

    $names = array_unique(array_merge(array_keys($proxy), array_keys($back ?? [])));
    natcasesort($names);

    $list = [];
    foreach ($names as $name) {
        $name = (string) $name;
        $p = $proxy[$name] ?? null;
        $hosts = $back[$name] ?? [];
        $system = in_array($name, $ignore, true) || is_replication_account($hosts);
        if ($system && !$p && !$withSystem) {
            continue;
        }
        $acc = ['name' => $name, 'proxy' => $p, 'hosts' => $hosts, 'system' => $system, 'pw' => null, 'issues' => []];
        $issue = function (string $code, string $sev, string $text) use (&$acc): void {
            $acc['issues'][] = ['code' => $code, 'sev' => $sev, 'text' => $text];
        };

        if ($back !== null && !$system) {
            if (!$p) {
                $issue('missing_proxy', 'warning', 'Fehlt in ProxySQL – Anwendungen können sich über ProxySQL nicht anmelden');
            } elseif (!$hosts && (int) $p['backend']) {
                $issue('missing_backend', 'error', 'Fehlt im Backend – ProxySQL kann keine Backend-Verbindung aufbauen');
            }
        }
        if ($p && $hosts) {
            $acc['pw'] = password_state($p['password'], $hosts);
            if ($acc['pw'] === 'mismatch') {
                $issue('pw_mismatch', 'error', 'Passwort in ProxySQL und Backend unterschiedlich');
            }
            if (!array_diff(array_column($hosts, 'host'), LOCAL_HOSTS)) {
                $issue('local_only', 'error', 'Backend-Konto nur für localhost – ProxySQL verbindet sich über das Netzwerk');
            }
        }
        if ($p && !(int) $p['active']) {
            $issue('inactive', 'info', 'In ProxySQL deaktiviert');
        }
        if ($p && !in_array((int) $p['default_hostgroup'], $online, true)) {
            $issue('hostgroup', 'error', "Standard-Hostgroup {$p['default_hostgroup']} hat keinen ONLINE-Server");
        }
        $list[] = $acc;
    }
    return $list;
}

// ---------------------------------------------------------------- Ändern

function create_backend_user(string $name, string $host, string $secret, bool $isHash): void
{
    $acct = bacct($name, $host);
    if ($isHash) {
        $sql = backend_is_mariadb()
            ? "CREATE USER $acct IDENTIFIED BY PASSWORD " . bq($secret)
            : "CREATE USER $acct IDENTIFIED WITH mysql_native_password AS " . bq($secret);
    } else {
        $sql = "CREATE USER $acct IDENTIFIED BY " . bq($secret);
    }
    run(backend(), $sql);
}

/** Rechte vergeben, Beschreibung für die Meldung zurück ('' = nichts vergeben) */
function grant_backend(string $name, string $host, string $schema, string $preset): string
{
    $privs = preset_privs($preset);
    if ($privs === '') {
        return '';
    }
    run(backend(), "GRANT $privs ON " . grant_target($schema) . ' TO ' . bacct($name, $host));
    return PRIV_PRESETS[$preset][0] . ' auf ' . ($schema === '*' ? 'alle Datenbanken' : $schema);
}

function insert_proxy_user(string $name, string $secret, int $hostgroup, array $opt = []): void
{
    if (proxy_user($name)) {
        throw new InvalidArgumentException("Benutzer $name existiert in ProxySQL bereits");
    }
    $values = [
        'username'               => aq($name),
        'password'               => aq($secret),
        'active'                 => '1',
        'default_hostgroup'      => (string) $hostgroup,
        'default_schema'         => aq(($opt['default_schema'] ?? '') ?: null),
        'transaction_persistent' => (string) (int) ($opt['transaction_persistent'] ?? 1),
        'max_connections'        => (string) (int) ($opt['max_connections'] ?? 10000),
        'comment'                => aq($opt['comment'] ?? ''),
    ];
    run(admin(), 'INSERT INTO mysql_users (' . implode(', ', array_keys($values)) . ') VALUES (' . implode(', ', $values) . ')');
}

/** Neues Konto in Backend und/oder ProxySQL (Formular "Neues Konto") */
function create_account(): string
{
    $name = valid_username(post('name'));
    $pw = post_raw('password');
    $host = valid_host(post('host') ?: '%');
    $toBackend = checked('in_backend');
    $toProxy = checked('in_proxy');
    if (!$toBackend && !$toProxy) {
        throw new InvalidArgumentException('Bitte mindestens ein System auswählen');
    }
    if ($pw === '') {
        throw new InvalidArgumentException('Passwort fehlt');
    }
    if ($pw !== post_raw('password2')) {
        throw new InvalidArgumentException('Die Passwörter stimmen nicht überein');
    }
    if ($toBackend && !backend()) {
        throw new InvalidArgumentException('Backend nicht verbunden');
    }
    if ($toProxy && proxy_user($name)) {
        throw new InvalidArgumentException("Benutzer $name existiert in ProxySQL bereits");
    }

    $schema = $toBackend ? valid_schema(post('schema', '*')) : '*';
    $done = [];
    if ($toBackend) {
        if (in_array($host, array_column(backend_hosts($name), 'host'), true)) {
            throw new InvalidArgumentException("Konto '$name'@'$host' existiert im Backend bereits");
        }
        create_backend_user($name, $host, $pw, false);
        $done[] = "Backend: '$name'@'$host' angelegt";
        if ($g = grant_backend($name, $host, $schema, post('preset', 'rw'))) {
            $done[] = "Rechte: $g";
        }
    }
    if ($toProxy) {
        try {
            insert_proxy_user($name, checked('hash_proxy') ? native_hash($pw) : $pw, (int) aint(post('default_hostgroup', '0'), false), [
                'default_schema'         => post('default_schema') ?: ($schema !== '*' ? $schema : ''),
                'transaction_persistent' => checked('transaction_persistent'),
                'max_connections'        => (int) aint(post('max_connections', '10000'), false),
                'comment'                => post('comment'),
            ]);
            $done[] = 'ProxySQL: Benutzer angelegt';
            $done[] = apply_module('users');
        } catch (Exception $e) {
            if ($done) {
                flash('ok', implode(' · ', $done));
            }
            throw new RuntimeException('ProxySQL: ' . $e->getMessage(), 0, $e);
        }
    }
    flash('ok', implode(' · ', $done));
    return url(['page' => 'users', 'account' => $name]) . '#account';
}

/** Rechte-Ausgabe ohne Passwort-Hashes */
function backend_grants(string $name, string $host): array
{
    $out = [];
    foreach (q(backend(), 'SHOW GRANTS FOR ' . bacct($name, $host)) as $r) {
        $out[] = preg_replace(["/(IDENTIFIED BY PASSWORD )'[^']*'/", "/(USING )'[^']*'/", "/( AS )'[^']*'/"], "\$1'•••'", (string) reset($r));
    }
    return $out;
}
