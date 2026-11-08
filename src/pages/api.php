<?php
// JSON-Daten für die Dashboard-Diagramme

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$range = max(300, min(604800, (int) ($_GET['range'] ?? 3600)));

try {
    echo json_encode(metrics($range), JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
