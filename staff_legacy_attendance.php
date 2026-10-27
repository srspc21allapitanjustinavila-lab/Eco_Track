<?php
// Attendance is self-service for Waste Collectors only. Keep this legacy URL
// unavailable so a Staff account cannot view or submit attendance directly.
require_once __DIR__ . '/config.php';
requireLogin();
header('Location: access_denied.php');
exit;
