<?php
// Additional print fixtures are added only to the disposable browser-test database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/attendance_management.php';
$name = $argv[1] ?? '';
if (!preg_match('/^ecotrack_attendance_browser_[a-f0-9]{12}$/', $name)) throw new RuntimeException('Invalid isolated database name.');
$db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $name . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$collectors = $db->query('SELECT id FROM bio_collectors ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
$staff = $db->query("SELECT id FROM users WHERE user_type = 'staff' AND is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
if (count($collectors) !== 35 || count($staff) !== 35) throw new RuntimeException('Unexpected fixture roster.');
$bioInsert = $db->prepare("INSERT INTO collector_attendance (bio_collector_id, attendance_date, status, recorded_by) VALUES (?, ?, 'present', 1)");
$staffInsert = $db->prepare("INSERT INTO collector_attendance (collector_user_id, attendance_date, status, recorded_by) VALUES (?, ?, 'present', 1) ON DUPLICATE KEY UPDATE status = 'present', deleted_at = NULL");
$date = new DateTimeImmutable('2024-12-30');
for ($day = 0; $day < 7; $day++) {
    $value = $date->modify('+' . $day . ' days')->format('Y-m-d');
    foreach ($collectors as $id) $bioInsert->execute([$id, $value]);
    foreach ($staff as $id) $staffInsert->execute([$id, $value]);
}
$db->prepare('UPDATE bio_collectors SET is_active = 0 WHERE id = ?')->execute([end($collectors)]);
$db->prepare("UPDATE collector_attendance SET deleted_at = CURRENT_TIMESTAMP WHERE bio_collector_id = ? AND attendance_date = '2024-12-30'")->execute([$collectors[0]]);
$db->prepare("UPDATE collector_attendance SET deleted_at = CURRENT_TIMESTAMP WHERE collector_user_id = ? AND attendance_date = '2024-12-30'")->execute([$staff[0]]);
$former = (int)$db->query("SELECT id FROM users WHERE username = 'former'")->fetchColumn();
$staffInsert->execute([$former, '2025-01-01']);
$db->prepare('INSERT INTO bio_collectors (name, name_key, is_active) VALUES (?, ?, 0)')->execute(['sada', attendanceNameKey('sada')]);
$bioInsert->execute([(int)$db->lastInsertId(), '2025-01-01']);
