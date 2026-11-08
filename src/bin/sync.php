<?php
// Automatischer Abgleich laut AUTO_SYNC – für den Sync-Container oder einen Cronjob:
//   * * * * *  php /pfad/zu/src/bin/sync.php
// Gibt nur aus, wenn sich etwas geändert hat oder etwas nicht ging. Gelöscht wird nie automatisch.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/bootstrap.php';

$log = fn (string $msg) => print(date('Y-m-d H:i:s') . " $msg\n");

if (!auto_sync()) {
    $log('AUTO_SYNC ist leer – nichts zu tun (möglich: ' . implode(', ', array_keys(AUTO_SYNC_PARTS)) . ').');
    exit(0);
}
try {
    admin();
    foreach (run_auto_sync() as $line) {
        $log($line);
    }
} catch (Throwable $e) {
    $log('Fehler: ' . $e->getMessage());
    exit(1);
}
