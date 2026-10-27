<?php

require_once 'config.php';
require_once 'includes/waste_analytics.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
requireLogin();
$user = getCurrentUser();
if (($user['user_type'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Administrator access is required.']);
    exit();
}

$conn = getDBConnection();
if (!$conn) {
    http_response_code(503);
    echo json_encode(['error' => 'Street filter data is unavailable.']);
    exit();
}

try {
    ensureWasteFormatColumns($conn);
    ensureWasteAnalyticsColumns($conn);
    $filters = [
        'phase' => is_string($_GET['street_phase'] ?? null) ? trim($_GET['street_phase']) : '',
        'category' => is_string($_GET['street_category'] ?? null) ? strtolower(trim($_GET['street_category'])) : 'all',
        // Month availability deliberately does not use date bounds.
    ];
    $records = $conn->query('SELECT * FROM waste_records WHERE is_active = 1 AND collection_date IS NOT NULL ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $state = buildWasteStreetFilterState($records, $filters);
    echo json_encode([
        'filters' => $state['filters'],
        'phase_options' => $state['phase_options'],
        'has_unclassified' => $state['has_unclassified'],
        'calendar' => $state['calendar'],
        'version' => (int)(getWasteDataVersion($conn)['version'] ?? 0),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Could not load street chart filter state: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Street filter data is unavailable.']);
}

