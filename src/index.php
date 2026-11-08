<?php
// ProxySQL GUI – Front-Controller

require __DIR__ . '/lib/bootstrap.php';

session_boot();

$page = (string) ($_GET['page'] ?? 'dashboard');

if ($page === 'login') {
    require __DIR__ . '/pages/login.php';
    exit;
}
if ($page === 'logout') {
    logout();
    redirect('?page=login');
}
if (!logged_in()) {
    if ($page === 'api') {
        http_response_code(401);
        exit;
    }
    redirect('?page=login');
}
if (!isset(PAGES[$page]) && !in_array($page, ['api', 'diff'], true)) {
    $page = 'dashboard';
}

if (is_post()) {
    csrf_check();
    // Die Konsole (Formular ohne "action") prüft im Nur-Lesen-Modus selbst, ob nur gelesen wird
    if (!can_write() && !($page === 'console' && !isset($_POST['action']))) {
        flash('error', 'Nur-Lesen-Modus – Änderungen sind deaktiviert.');
        redirect(back_url());
    }
}

try {
    admin();
} catch (mysqli_sql_exception $e) {
    if ($page === 'api') {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
    $p = cfg('proxysql');
    layout_start('Keine Verbindung', $page, [
        'status' => 'down',
        'title'  => $p['host'] . ':' . $p['port'],
        'sub'    => 'ProxySQL Admin-Interface nicht erreichbar',
    ]);
    panel_start('Verbindungsfehler');
    echo '<div class="msg msg-error">' . icon('error') . ' ' . h($e->getMessage()) . '</div>';
    echo '<p>Verbindung: <code>' . h($p['user'] . '@' . $p['host'] . ':' . $p['port']) . '</code> – '
        . 'einstellbar über PROXYSQL_HOST, PROXYSQL_PORT, PROXYSQL_USER und PROXYSQL_PASSWORD.</p>';
    panel_end();
    layout_end();
    exit;
}

// Seitenübergreifende Aktionen: Konfiguration aktivieren bzw. auf Disk speichern
if (is_post() && in_array($_POST['action'] ?? '', ['apply', 'save'], true)) {
    handle_post(fn (string $action) => flash('ok', $action === 'apply' ? apply_module(post('module')) : save_module(post('module'))));
}

require __DIR__ . '/pages/' . $page . '.php';
