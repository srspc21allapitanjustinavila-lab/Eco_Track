<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config.php';
$name = $argv[1] ?? '';
if (!preg_match('/^ecotrack_attendance_browser_[a-f0-9]{12}$/', $name)) throw new RuntimeException('Invalid isolated database name.');
$db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $name . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$insert = $db->prepare("INSERT INTO users (username, first_name, last_name, email, user_type, is_active) VALUES (?, 'Staff', ?, ?, 'staff', 1)");
for ($i = 3; $i <= 36; $i++) $insert->execute(['fixture-' . $i, sprintf('%02d', $i), 'staff' . $i . '@example.test']);
$db->exec("INSERT INTO users (username, first_name, last_name, email, user_type, is_active) VALUES ('former','Former','Worker','former@example.test','staff',0)");
$former = (int)$db->lastInsertId();
$db->exec("INSERT INTO collector_attendance (collector_user_id, attendance_date, status, recorded_by) VALUES ($former, '2020-04-05', 'late', 1)");
