<?php
// Verbindungen zu ProxySQL-Admin und Backend, SQL-Helfer und Quoting

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function db_connect(array $c): mysqli
{
    $conn = mysqli_init();
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
    $conn->options(MYSQLI_SET_CHARSET_NAME, 'utf8mb4');
    $conn->real_connect($c['host'], $c['user'], $c['password'], '', $c['port']);
    return $conn;
}

/** Verbindung zum ProxySQL Admin-Interface (wirft bei Fehler) */
function admin(): mysqli
{
    static $conn = null;
    return $conn ??= db_connect(cfg('proxysql'));
}

function backend_configured(): bool
{
    $c = cfg('backend');
    return $c['host'] !== '' && $c['user'] !== '';
}

/** Verbindung zum Backend – null, wenn nicht konfiguriert oder nicht erreichbar */
function backend(): ?mysqli
{
    static $conn = false;
    if ($conn === false) {
        $conn = null;
        if (backend_configured()) {
            try {
                $conn = db_connect(cfg('backend'));
            } catch (mysqli_sql_exception $e) {
                $GLOBALS['BACKEND_ERROR'] = $e->getMessage();
            }
        }
    }
    return $conn;
}

function backend_error(): ?string
{
    return $GLOBALS['BACKEND_ERROR'] ?? null;
}

/** Alle Zeilen als assoziative Arrays */
function q(mysqli $c, string $sql): array
{
    $res = $c->query($sql);
    if (!$res instanceof mysqli_result) {
        return [];
    }
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
    return $rows;
}

/** Erste Zeile oder null */
function q1(mysqli $c, string $sql): ?array
{
    return q($c, $sql)[0] ?? null;
}

/** Erster Wert der ersten Zeile */
function qv(mysqli $c, string $sql): ?string
{
    $row = q1($c, $sql);
    return $row ? reset($row) : null;
}

/** Statement ausführen, Anzahl betroffener Zeilen zurück */
function run(mysqli $c, string $sql): int
{
    $c->query($sql);
    return max(0, $c->affected_rows);
}

/**
 * String-Literal für das ProxySQL-Admin-Interface.
 * Das Admin-Interface ist SQLite: ' wird verdoppelt, Backslash ist kein Escape-Zeichen
 * (real_escape_string wäre hier falsch).
 */
function aq(?string $v): string
{
    return $v === null ? 'NULL' : "'" . str_replace(["\0", "'"], ['', "''"], $v) . "'";
}

/** Ganzzahl-Literal (leer = NULL, sofern erlaubt) */
function aint($v, bool $nullable = true): string
{
    $v = trim((string) ($v ?? ''));
    if ($v === '') {
        if ($nullable) {
            return 'NULL';
        }
        throw new InvalidArgumentException('Pflichtfeld: Zahl erwartet');
    }
    if (!preg_match('/^-?\d{1,18}$/', $v)) {
        throw new InvalidArgumentException("Keine gültige Zahl: $v");
    }
    return (string) (int) $v;
}

/** String-Literal für das Backend (MySQL-Escaping) */
function bq(string $v): string
{
    return "'" . backend()->real_escape_string($v) . "'";
}

/** Bezeichner (Datenbank, Tabelle) für das Backend */
function bident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

/** Konto 'user'@'host' für das Backend */
function bacct(string $user, string $host): string
{
    return bq($user) . '@' . bq($host);
}

/** Spalten => SQL-Literale zu "a = 1, b = 'x'" */
function sql_assign(array $values): string
{
    return implode(', ', array_map(fn ($k, $v) => "$k = $v", array_keys($values), $values));
}
