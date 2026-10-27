<?php

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
requireLogin();

$conn = getDBConnection();
if (!$conn) {
    http_response_code(503);
    echo json_encode(['error' => 'Waste data is unavailable.']);
    exit();
}

try {
    echo json_encode(getWasteDataVersion($conn));
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => 'Waste data is unavailable.']);
}
