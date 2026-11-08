<?php
// Login, Session und CSRF-Schutz

function session_boot(): void
{
    session_name('proxysqlgui');
    // hinter einem Reverse-Proxy mit HTTPS kommt die Anfrage per HTTP an – dann zählt X-Forwarded-Proto
    // (ein gefälschter Header macht das Cookie nur strenger)
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => $https,
    ]);
    session_start();
}

/** Login nur, wenn ein GUI-Passwort gesetzt ist */
function auth_enabled(): bool
{
    return cfg('gui_password') !== '';
}

function logged_in(): bool
{
    return !auth_enabled() || !empty($_SESSION['role']);
}

/** Admin-Login: darf schreiben (außer mit GUI_READONLY) und die SQL-Konsole nutzen */
function is_admin(): bool
{
    return auth_enabled() && ($_SESSION['role'] ?? '') === 'admin';
}

/** Schreiben nur mit Admin-Login und ohne Nur-Lesen-Modus */
function can_write(): bool
{
    return is_admin() && !cfg('readonly');
}

function try_login(string $user, string $password): bool
{
    // alle Vergleiche immer ausführen (kein Timing-Unterschied zwischen Benutzer, Passwort und Rolle)
    $admin = hash_equals(cfg('gui_user'), $user) & hash_equals(cfg('gui_password'), $password);
    $reader = (cfg('gui_readonly_password') !== '')
        & hash_equals(cfg('gui_readonly_user'), $user) & hash_equals(cfg('gui_readonly_password'), $password);
    if (!$admin && !$reader) {
        sleep(1);
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    $_SESSION['role'] = $admin ? 'admin' : 'readonly';
    return true;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void
{
    if (!hash_equals(csrf_token(), (string) ($_POST['_csrf'] ?? ''))) {
        http_response_code(400);
        exit('Ungültiges Formular-Token – bitte die Seite neu laden.');
    }
}
