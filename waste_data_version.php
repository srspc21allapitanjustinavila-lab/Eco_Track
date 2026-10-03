<?php

require_once 'config.php';

initSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit();
}

if (isSessionTimeout()) {
    logoutUser();
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit();
}

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
