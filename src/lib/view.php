<?php
// HTML-Ausgabe: Layout, Panels, Formulare, Formatierung

const PAGES = [
    'dashboard' => ['Übersicht', 'dashboard'],
    'servers'   => ['Server', 'server'],
    'users'     => ['Konten', 'users'],
    'rules'     => ['Query-Regeln', 'rules'],
    'queries'   => ['Queries & Cache', 'cache'],
    'sessions'  => ['Sitzungen', 'sessions'],
    'variables' => ['Variablen', 'vars'],
    'tables'    => ['Tabellen', 'table'],
    'console'   => ['SQL-Konsole', 'console'],
];

function h($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(array $params): string
{
    return '?' . http_build_query($params);
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** Passwortfelder nicht trimmen */
function post_raw(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? $v : '';
}

function checked(string $key): bool
{
    return !empty($_POST[$key]);
}

/** Vorbelegung nach einem fehlgeschlagenen Formular */
function old(string $key, $default = '')
{
    return $_SESSION['old'][$key] ?? $default;
}

/** Rücksprung nach POST: gleiche Seite (nur relative Query-URLs) */
function back_url(): string
{
    $b = (string) ($_POST['_back'] ?? '');
    return str_starts_with($b, '?') ? $b : '?' . ($_SERVER['QUERY_STRING'] ?? '');
}

/**
 * Formular-Aktion ausführen (Post/Redirect/Get). Fehler werden als Meldung angezeigt.
 * Der Handler bekommt die Aktion und kann eine Ziel-URL zurückgeben.
 */
function handle_post(callable $handler): void
{
    if (!is_post()) {
        return;
    }
    $to = back_url();
    try {
        $result = $handler((string) ($_POST['action'] ?? ''));
        if (is_string($result)) {
            $to = $result;
        }
    } catch (Exception $e) {
        flash('error', $e->getMessage());
        $_SESSION['old'] = array_diff_key($_POST, ['password' => 1, 'password2' => 1, '_csrf' => 1]);
    }
    redirect($to);
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function render_flashes(): string
{
    $html = '';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        $html .= '<div class="msg msg-' . h($type) . '">' . icon($type === 'ok' ? 'ok' : ($type === 'error' ? 'error' : 'info')) . '<span>' . h($msg) . '</span></div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

// ---------------------------------------------------------------- Formatierung

function nf($n, int $dec = 0): string
{
    return ($n === null || $n === '') ? '' : number_format((float) $n, $dec, ',', '.');
}

function fmt_bytes($b): string
{
    $b = (float) $b;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < 4) {
        $b /= 1024;
        $i++;
    }
    return nf($b, $i ? 1 : 0) . ' ' . $units[$i];
}

/** Mikrosekunden lesbar */
function fmt_us($us): string
{
    $us = (float) $us;
    if ($us >= 1e6) {
        return nf($us / 1e6, 2) . ' s';
    }
    if ($us >= 1000) {
        return nf($us / 1000, 1) . ' ms';
    }
    return nf($us) . ' µs';
}

/** Millisekunden (Cache-TTL) lesbar */
function fmt_ttl($ms): string
{
    $ms = (int) $ms;
    if ($ms >= 3600000 && $ms % 3600000 === 0) {
        return ($ms / 3600000) . ' Std.';
    }
    if ($ms >= 60000 && $ms % 60000 === 0) {
        return ($ms / 60000) . ' Min.';
    }
    if ($ms >= 1000 && $ms % 1000 === 0) {
        return ($ms / 1000) . ' s';
    }
    return nf($ms) . ' ms';
}

function fmt_duration(int $s): string
{
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);
    $m = intdiv($s % 3600, 60);
    return ($d ? "$d T. " : '') . ($d || $h ? "$h Std. " : '') . "$m Min.";
}

function fmt_ts($ts): string
{
    return $ts ? date('d.m.Y H:i:s', (int) $ts) : '';
}

function fmt_ago(int $ts): string
{
    $s = max(0, time() - $ts);
    return $s < 60 ? "$s s" : ($s < 3600 ? intdiv($s, 60) . ' Min.' : fmt_duration($s));
}

// ---------------------------------------------------------------- Icons

function icon(string $name, string $title = ''): string
{
    static $svg = [
        'critical' => '<circle cx="8" cy="8" r="7" fill="#222"/><rect x="4" y="7" width="8" height="2" fill="#fff"/>',
        'error'    => '<circle cx="8" cy="8" r="7" fill="#d9302c"/><path d="M5.3 5.3l5.4 5.4M10.7 5.3l-5.4 5.4" stroke="#fff" stroke-width="2"/>',
        'warning'  => '<path d="M8 1.3l7 12.9H1z" fill="#f2b705" stroke="#a87b00" stroke-width=".8"/><rect x="7.2" y="5.6" width="1.6" height="4.4" fill="#333"/><rect x="7.2" y="11" width="1.6" height="1.6" fill="#333"/>',
        'info'     => '<circle cx="8" cy="8" r="7" fill="#3c78c3"/><rect x="7.2" y="7" width="1.6" height="5" fill="#fff"/><circle cx="8" cy="4.8" r="1.05" fill="#fff"/>',
        'ok'       => '<circle cx="8" cy="8" r="7" fill="#3a9d23"/><path d="M4.6 8.2l2.3 2.3 4.6-4.7" stroke="#fff" stroke-width="2" fill="none"/>',
        'up'       => '<path d="M8 1.2l6.2 7.3H10.4V15H5.6V8.5H1.8z" fill="#3a9d23"/>',
        'down'     => '<path d="M8 14.8l6.2-7.3H10.4V1H5.6v6.5H1.8z" fill="#d9302c"/>',
        'check'    => '<path d="M3 8.5l3.2 3.2L13 4.8" stroke="currentColor" stroke-width="2" fill="none"/>',
        'cross'    => '<path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="2"/>',
        'refresh'  => '<path d="M13 8a5 5 0 1 1-1.6-3.7" stroke="currentColor" stroke-width="1.7" fill="none"/><path d="M13.6 1.6v3.8H9.8" stroke="currentColor" stroke-width="1.7" fill="none"/>',
        'dashboard'=> '<g fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1.8" y="1.8" width="5" height="5" rx=".8"/><rect x="9.2" y="1.8" width="5" height="5" rx=".8"/><rect x="1.8" y="9.2" width="5" height="5" rx=".8"/><rect x="9.2" y="9.2" width="5" height="5" rx=".8"/></g>',
        'server'   => '<g fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1.8" y="2" width="12.4" height="5" rx="1"/><rect x="1.8" y="9" width="12.4" height="5" rx="1"/></g><circle cx="4.5" cy="4.5" r="1" fill="currentColor"/><circle cx="4.5" cy="11.5" r="1" fill="currentColor"/>',
        'users'    => '<g fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="5" r="3"/><path d="M2.3 14.5c.4-3 2.8-4.8 5.7-4.8s5.3 1.8 5.7 4.8"/></g>',
        'rules'    => '<path d="M1.8 2.2h12.4L9.4 8v5.2l-2.8 1.4V8z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>',
        'cache'    => '<path d="M9.6 1.2L3.2 9h4.3l-1 5.8L12.8 7H8.5z" fill="currentColor"/>',
        'sessions' => '<g fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1.5 5h11M9.5 2l3 3-3 3M14.5 11h-11M6.5 8l-3 3 3 3"/></g>',
        'vars'     => '<g stroke="currentColor" stroke-width="1.5"><path d="M2 4h12M2 8h12M2 12h12"/></g><circle cx="5" cy="4" r="1.8" fill="currentColor"/><circle cx="11" cy="8" r="1.8" fill="currentColor"/><circle cx="7" cy="12" r="1.8" fill="currentColor"/>',
        'console'  => '<g fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1.5" y="2.2" width="13" height="11.6" rx="1.2"/><path d="M4.2 6l2.4 2-2.4 2M8.5 10.5h3.5"/></g>',
        'user'     => '<g fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="5" r="3"/><path d="M2.3 14.5c.4-3 2.8-4.8 5.7-4.8s5.3 1.8 5.7 4.8"/></g>',
        'plus'     => '<path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="2"/>',
        'edit'     => '<path d="M10.8 2.2l3 3-8.3 8.3H2.5v-3z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>',
        'trash'    => '<g fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2.5 4h11M6 4V2.5h4V4M4 4l.8 9.5h6.4L12 4"/></g>',
        'key'      => '<g fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="5" cy="8" r="3"/><path d="M8 8h6.5M12 8v2.5M14 8v2"/></g>',
        'db'       => '<g fill="none" stroke="currentColor" stroke-width="1.4"><ellipse cx="8" cy="3.5" rx="5.5" ry="2"/><path d="M2.5 3.5v9c0 1.1 2.5 2 5.5 2s5.5-.9 5.5-2v-9M2.5 8c0 1.1 2.5 2 5.5 2s5.5-.9 5.5-2"/></g>',
        'table'    => '<g fill="none" stroke="currentColor" stroke-width="1.4"><rect x="1.7" y="2.2" width="12.6" height="11.6" rx="1"/><path d="M1.7 6h12.6M1.7 9.9h12.6M6 6v7.8"/></g>',
    ];
    $t = $title !== '' ? '<title>' . h($title) . '</title>' : '';
    return '<svg class="ic" viewBox="0 0 16 16" aria-hidden="' . ($t ? 'false' : 'true') . '">' . $t . ($svg[$name] ?? '') . '</svg>';
}

const SEVERITY = [
    'critical' => 'Kritisch',
    'error'    => 'Fehler',
    'warning'  => 'Warnung',
    'info'     => 'Hinweis',
];

function sev_icon(string $sev): string
{
    return icon($sev, SEVERITY[$sev] ?? $sev);
}

function status_badge(?string $status): string
{
    $cls = ['ONLINE' => 'ok', 'SHUNNED' => 'bad', 'OFFLINE_SOFT' => 'warn', 'OFFLINE_HARD' => 'off'][$status] ?? 'off';
    return '<span class="badge badge-' . $cls . '">' . h($status ?? '–') . '</span>';
}

function badge(string $text, string $kind = 'info', string $title = ''): string
{
    return '<span class="badge badge-' . h($kind) . '"' . ($title !== '' ? ' title="' . h($title) . '"' : '') . '>' . h($text) . '</span>';
}

function yesno($v): string
{
    return $v === null ? '<span class="null">–</span>' : ((int) $v ? '<span class="yes">' . icon('check') . '</span>' : '<span class="no">' . icon('cross') . '</span>');
}

// ---------------------------------------------------------------- Formulare

/** Formular-Anfang mit CSRF-Token und Aktion */
function form_open(string $action, array $opt = []): string
{
    return '<form method="post"' . (isset($opt['to']) ? ' action="' . h($opt['to']) . '"' : '')
        . ' class="' . h($opt['class'] ?? '') . '"'
        . (isset($opt['confirm']) ? ' data-confirm="' . h($opt['confirm']) . '"' : '') . '>'
        . csrf_field() . '<input type="hidden" name="action" value="' . h($action) . '">';
}

function submit_button(string $label, string $class = 'btn-primary'): string
{
    $dis = can_write() ? '' : ' disabled title="Nur-Lesen-Modus"';
    return '<button type="submit" class="btn ' . h($class) . '"' . $dis . '>' . $label . '</button>';
}

/** Kleines POST-Formular mit einem Button (für Aktionen in Tabellen). $label ist HTML. */
function action_form(string $action, array $fields, string $label, array $opt = []): string
{
    $html = form_open($action, $opt + ['class' => 'inline']);
    foreach ($fields as $k => $v) {
        $html .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    }
    return $html . ($opt['extra'] ?? '') . submit_button($label, $opt['btn'] ?? 'btn-sm') . '</form>';
}

function hidden(string $name, $value): string
{
    return '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">';
}

function options(array $opts, $selected): string
{
    $html = '';
    foreach ($opts as $v => $label) {
        $html .= '<option value="' . h($v) . '"' . ((string) $v === (string) $selected ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $html;
}

function hostgroup_select(string $name, $selected, bool $allowEmpty = false): string
{
    $hgs = hostgroups();
    if ($selected !== null && $selected !== '' && !isset($hgs[(int) $selected])) {
        $hgs[(int) $selected] = 'HG ' . (int) $selected . ' · ohne Server';
    }
    $opts = $allowEmpty ? ['' => '–'] : [];
    foreach ($hgs as $id => $label) {
        $opts[$id] = $label;
    }
    return '<select name="' . h($name) . '">' . options($opts, $selected) . '</select>';
}

// ---------------------------------------------------------------- Layout

/**
 * Seitenkopf. $head: title, sub, status (up|down), tools (HTML rechts), nav (bool)
 */
function layout_start(string $title, string $active, array $head = []): void
{
    $p = cfg('proxysql');
    $nav = $head['nav'] ?? true;
    ?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · ProxySQL GUI</title>
<link rel="stylesheet" href="assets/app.css?v=<?= GUI_VERSION ?>">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Crect width='16' height='16' rx='3' fill='%2324579a'/%3E%3Cpath d='M9.6 2L4 9h3.7l-.9 5L12.2 7H8.6z' fill='%23ffd36b'/%3E%3C/svg%3E">
</head>
<body>
<header class="topbar">
  <div class="brand"><span class="brand-mark">ProxySQL</span> <span class="brand-sub">Admin Console</span></div>
  <div class="topbar-right">
    <?php if (!can_write() && $nav): ?><span class="badge badge-warn"><?= auth_enabled() ? 'Nur lesen' : 'Nur lesen – kein GUI_PASSWORD gesetzt' ?></span><?php endif ?>
    <?php if (auth_enabled() && !empty($_SESSION['user'])): ?>
      <span class="who"><?= icon('user') ?> <?= h(strtoupper($_SESSION['user'])) ?></span>
      <a href="?page=logout">Abmelden</a>
    <?php endif ?>
  </div>
</header>
<?php if ($nav): ?>
<nav class="navbar">
  <?php foreach (PAGES as $key => [$label, $ic]): ?>
    <?php if ($key === 'console' && !is_admin()) continue ?>
    <a href="?page=<?= $key ?>" title="<?= h($label) ?>"<?= $key === $active ? ' class="active"' : '' ?>><?= icon($ic) ?><span><?= h($label) ?></span></a>
  <?php endforeach ?>
  <form class="nav-search" method="get">
    <input type="hidden" name="page" value="queries">
    <label for="nav-q">Query suchen</label>
    <input id="nav-q" name="q" type="search" placeholder="Text oder Digest">
  </form>
</nav>
<div class="target">
  <div>
    <div class="target-name"><?= icon(($head['status'] ?? 'up') === 'down' ? 'down' : 'up') ?> <?= h($head['title'] ?? $title) ?></div>
    <div class="target-sub"><?= icon('db') ?> <?= h($head['sub'] ?? ($p['host'] . ':' . $p['port'])) ?></div>
  </div>
  <div class="target-right">
    <?= $head['tools'] ?? '' ?>
    <span class="refreshed">Seite aktualisiert <b data-refreshed><?= h(date('d.m.Y H:i:s')) ?></b></span>
    <a href="" class="icon-btn" data-reload title="Neu laden"><?= icon('refresh') ?></a>
  </div>
</div>
<?php endif ?>
<main class="content">
<?= render_flashes() ?>
<?php
}

function layout_end(): void
{
    unset($_SESSION['old']);
    ?>
</main>
<footer class="footer">ProxySQL GUI <?= GUI_VERSION ?> · <?= h(date('d.m.Y H:i')) ?></footer>
<script src="assets/app.js?v=<?= GUI_VERSION ?>"></script>
</body>
</html>
<?php
}

/** Panel im Enterprise-Manager-Stil. $opt: id, tools (HTML), class, flush */
function panel_start(string $title, array $opt = []): void
{
    echo '<section class="panel ' . h($opt['class'] ?? '') . '"' . (isset($opt['id']) ? ' id="' . h($opt['id']) . '"' : '') . '>'
        . '<header class="panel-head"><button type="button" class="panel-toggle" aria-label="Ein-/Ausklappen"></button>'
        . '<h2>' . h($title) . '</h2>'
        . (isset($opt['tools']) ? '<div class="panel-tools">' . $opt['tools'] . '</div>' : '')
        . '</header><div class="panel-body' . (!empty($opt['flush']) ? ' flush' : '') . '">';
}

function panel_end(): void
{
    echo '</div></section>';
}

function chart_panel(string $title, string $key): void
{
    panel_start($title);
    echo '<div class="chart-box" data-chart="' . h($key) . '"><div class="chart-empty">Lade …</div></div>';
    panel_end();
}

/**
 * Einfache Tabelle. $opt: cols [key => label], format [key => fn($v, $row)], empty, class
 */
function data_table(array $rows, array $opt = []): string
{
    if (!$rows) {
        return '<p class="empty">' . h($opt['empty'] ?? 'Keine Einträge') . '</p>';
    }
    $cols = $opt['cols'] ?? array_combine(array_keys($rows[0]), array_keys($rows[0]));
    $fmt = $opt['format'] ?? [];
    $html = '<div class="table-wrap"><table class="grid ' . h($opt['class'] ?? '') . '"><thead><tr>';
    foreach ($cols as $label) {
        $html .= '<th>' . h($label) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $html .= '<tr>';
        foreach ($cols as $k => $label) {
            $v = $r[$k] ?? null;
            $html .= '<td>' . (isset($fmt[$k]) ? $fmt[$k]($v, $r) : ($v === null ? '<span class="null">NULL</span>' : h($v))) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}

/** Status-Anzeige + Buttons für ein Konfigurationsmodul (Runtime/Disk) */
function module_tools(string $module): string
{
    $st = config_state()[$module] ?? null;
    if (!$st) {
        return '';
    }
    $html = '<span class="module-state">';
    $diff = url(['page' => 'diff', 'module' => $module]);
    if ($st['runtime']) {
        $html .= '<a href="' . h($diff) . '#runtime">' . badge($st['runtime'] . ' nicht aktiviert', 'warn', 'Speicher weicht von der Laufzeit ab – Details anzeigen') . '</a> '
            . action_form('apply', ['module' => $module], 'Aktivieren', ['btn' => 'btn-sm btn-primary']);
    }
    if ($st['disk']) {
        $html .= ' <a href="' . h($diff) . '#disk">' . badge($st['disk'] . ' nicht gespeichert', 'warn', 'Geht beim Neustart von ProxySQL verloren – Details anzeigen') . '</a> '
            . action_form('save', ['module' => $module], 'Auf Disk speichern');
    }
    if (!$st['runtime'] && !$st['disk']) {
        $html .= badge('Aktiv · gespeichert', 'ok', 'Memory = Runtime = Disk');
    }
    return $html . '</span>';
}
