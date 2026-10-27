<?php
// CLI-only fixture for an isolated database used by the backup browser test.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config.php';

$action = $argv[1] ?? '';
$name = $argv[2] ?? '';
if (!preg_match('/^ecotrack_backup_test_[a-f0-9]{12}$/', $name) || DB_HOST !== '127.0.0.1') {
    throw new RuntimeException('Invalid isolated database or server.');
}
$server = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($action === 'cleanup') {
    $server->exec('DROP DATABASE IF EXISTS `' . $name . '`');
    exit;
}
if ($action === 'setup') {
    $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4');
    $db = new PDO('mysql:host=127.0.0.1;dbname=' . $name . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50), first_name VARCHAR(50), last_name VARCHAR(50), email VARCHAR(100), user_type ENUM('admin','staff'), is_active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB");
    $db->exec("INSERT INTO users (username, first_name, last_name, email, user_type) VALUES ('backup-admin','Admin','Tester','admin@example.test','admin'),('backup-staff','Staff','Tester','staff@example.test','staff')");
    ensureUserSettingsTable($db);
    $db->exec('INSERT INTO user_settings (user_id, data_export_enabled) VALUES (1, 0)');
    ensureDataExportOtpsTable($db);
    $insert = $db->prepare('INSERT INTO data_export_otps (user_id, purpose, code_hash, expires_at) VALUES (1, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))');
    $insert->execute(['database_backup', password_hash('123456', PASSWORD_DEFAULT)]);
    $insert->execute(['export', password_hash('654321', PASSWORD_DEFAULT)]);
    $sessionDir = $argv[3] ?? '';
    if (!is_dir($sessionDir)) throw new RuntimeException('Session directory unavailable.');
    ini_set('session.save_path', $sessionDir);
    foreach ([1, 2] as $id) {
        session_id($argv[3 + $id] ?? '');
        session_start();
        $_SESSION['user_id'] = $id;
        $_SESSION['user'] = ['id' => $id];
        $_SESSION['last_activity'] = time();
        session_write_close();
    }
    exit;
}
if ($action === 'expire') {
    $db = new PDO('mysql:host=127.0.0.1;dbname=' . $name . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec("DELETE FROM data_export_otps WHERE user_id = 1 AND purpose = 'database_backup' AND used = 0");
    $db->prepare("INSERT INTO data_export_otps (user_id, purpose, code_hash, expires_at) VALUES (1, 'database_backup', ?, DATE_SUB(NOW(), INTERVAL 1 MINUTE))")->execute([password_hash('987654', PASSWORD_DEFAULT)]);
    exit;
}
if ($action === 'purposes') {
    $db = new PDO('mysql:host=127.0.0.1;dbname=' . $name . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('UPDATE user_settings SET data_export_enabled = 1 WHERE user_id = 1');
    $db->exec("DELETE FROM data_export_otps WHERE user_id = 1 AND purpose = 'export'");
    $insert = $db->prepare('INSERT INTO data_export_otps (user_id, purpose, code_hash, expires_at) VALUES (1, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))');
    $codes = ['export' => '111111', 'recent_import_activity_print' => '222222', 'waste_import' => '333333'];
    foreach ($codes as $purpose => $code) $insert->execute([$purpose, password_hash($code, PASSWORD_DEFAULT)]);
    $user = ['id' => 1];
    foreach ($codes as $purpose => $code) {
        if (verifyAndConsumeActionOtp($db, $user, 'database_backup', $code)
            || !verifyAndConsumeActionOtp($db, $user, $purpose, $code)
            || verifyAndConsumeActionOtp($db, $user, $purpose, $code)) {
            throw new RuntimeException('OTP purpose isolation or one-time use failed for ' . $purpose);
        }
    }
    $insert->execute(['export', password_hash('654321', PASSWORD_DEFAULT)]);
    $db->exec('UPDATE user_settings SET data_export_enabled = 0 WHERE user_id = 1');
    echo 'purpose checks passed';
    exit;
}
throw new RuntimeException('Unknown fixture action.');
