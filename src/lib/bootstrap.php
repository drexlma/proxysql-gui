<?php
// Gemeinsamer Start für die Weboberfläche (index.php) und den automatischen Abgleich (bin/sync.php)

const GUI_VERSION = '2.2.0';

$CONFIG = require __DIR__ . '/../config.php';
date_default_timezone_set($CONFIG['timezone']);

function cfg(string $key)
{
    return $GLOBALS['CONFIG'][$key] ?? null;
}

require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/view.php';
require __DIR__ . '/proxysql.php';
require __DIR__ . '/layers.php';
require __DIR__ . '/accounts.php';
require __DIR__ . '/rules.php';
require __DIR__ . '/sync.php';
