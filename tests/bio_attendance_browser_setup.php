<?php
// CLI-only fixture for the isolated browser test; never creates application users.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config.php';
$action = $argv[1] ?? '';
$name = $argv[2] ?? '';
if (!preg_match('/^ecotrack_attendance_browser_[a-f0-9]{12}$/', $name)) throw new RuntimeException('Invalid isolated database name.');
$server = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($action === 'cleanup') { $server->exec('DROP DATABASE IF EXISTS `' . $name . '`'); exit; }
if ($action !== 'setup') throw new RuntimeException('Unknown action.');
$server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4');
$db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $name . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50), first_name VARCHAR(50), last_name VARCHAR(50), email VARCHAR(100), user_type ENUM('admin','staff','waste_collector'), is_active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB");
$db->exec("INSERT INTO users (username, first_name, last_name, email, user_type) VALUES ('fixture-admin','Admin','Tester','admin@example.test','admin'),('fixture-staff','Staff','Tester','staff@example.test','staff'),('fixture-collector','Collector','Tester','collector@example.test','waste_collector')");
$db->exec("CREATE TABLE bio_collectors (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, name_key CHAR(64) NOT NULL, user_id INT NULL, is_active TINYINT NOT NULL DEFAULT 1, UNIQUE KEY unique_bio_collector_name_key (name_key), UNIQUE KEY unique_bio_collector_user (user_id), CONSTRAINT fixture_bio_user FOREIGN KEY (user_id) REFERENCES users(id)) ENGINE=InnoDB");
$db->exec("INSERT INTO bio_collectors (name, name_key, user_id) VALUES ('Collector Tester', SHA2('collector tester', 256), 3)");
$db->exec("CREATE TABLE waste_records (id INT AUTO_INCREMENT PRIMARY KEY, is_active TINYINT NOT NULL DEFAULT 1, collection_date DATE NULL, name_of_bioman VARCHAR(100), garbage_collector VARCHAR(100)) ENGINE=InnoDB");
$insert = $db->prepare("INSERT INTO waste_records (name_of_bioman, collection_date) VALUES (?, '2025-01-10')");
for ($i = 1; $i <= 35; $i++) $insert->execute([sprintf('Collector %02d', $i)]);
$db->exec("CREATE TABLE collector_attendance (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, collector_user_id INT NOT NULL, attendance_date DATE NOT NULL, status ENUM('present','absent','late','on_leave') NOT NULL, recorded_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY unique_collector_attendance_day (collector_user_id, attendance_date), CONSTRAINT legacy_user FOREIGN KEY (collector_user_id) REFERENCES users(id)) ENGINE=InnoDB");
$db->exec("INSERT INTO collector_attendance (collector_user_id, attendance_date, status, recorded_by) VALUES (2, '2025-01-01', 'present', 1)");
ensureDataExportOtpsTable($db);
$db->prepare("INSERT INTO data_export_otps (user_id, purpose, code_hash, expires_at) VALUES (1, 'export', ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))")->execute([password_hash('123456', PASSWORD_DEFAULT)]);
$sessionDir = $argv[3] ?? '';
if (!is_dir($sessionDir)) throw new RuntimeException('Session directory unavailable.');
ini_set('session.save_path', $sessionDir);
foreach ([1, 2, 3] as $id) {
    session_id(($argv[3 + $id] ?? ''));
    session_start();
    $_SESSION['user_id'] = $id;
    $_SESSION['user'] = ['id' => $id];
    $_SESSION['last_activity'] = time();
    session_write_close();
}
