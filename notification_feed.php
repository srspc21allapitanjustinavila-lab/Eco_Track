<?php

require_once 'config.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

$user = getCurrentUser();
$conn = getDBConnection();

echo json_encode([
    'items' => getNotificationItems($conn, $user),
], JSON_UNESCAPED_UNICODE);
