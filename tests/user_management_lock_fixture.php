<?php
// Isolated local database fixture for real concurrent User Management requests.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config.php';

$action = $argv[1] ?? '';
$name = $argv[2] ?? '';
if (!preg_match('/^ecotrack_user_lock_test_[a-f0-9]{12}$/', $name) || DB_HOST !== '127.0.0.1') {
    throw new RuntimeException('Invalid isolated database or server.');
}
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$server = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', DB_USER, DB_PASS, $options);
if ($action === 'cleanup') {
    $server->exec('DROP DATABASE IF EXISTS `' . $name . '`');
    exit;
}
if ($action === 'setup') {
    $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4');
}
$db = new PDO('mysql:host=127.0.0.1;dbname=' . $name . ';charset=utf8mb4', DB_USER, DB_PASS, $options);
if ($action === 'setup') {
    $db->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NOT NULL, email VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL DEFAULT '', first_name VARCHAR(50), last_name VARCHAR(50), user_type ENUM('admin','staff','waste_collector'), photo VARCHAR(255), address VARCHAR(255), gender VARCHAR(30), is_active TINYINT NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, last_login DATETIME NULL) ENGINE=InnoDB");
    $db->exec("INSERT INTO users (username,email,first_name,last_name,user_type) VALUES ('admin-one','one@example.test','Admin','One','admin'),('admin-two','two@example.test','Admin','Two','admin'),('staff','staff@example.test','Staff','Tester','staff')");
    ensureReportsTable($db);
    ensureActivityLogsTable($db);
    if (!ensurePersistentLoginTokensTable($db)) throw new RuntimeException('Token table setup failed.');
    $sessionDir = $argv[3] ?? '';
    if (!is_dir($sessionDir)) throw new RuntimeException('Session directory unavailable.');
    ini_set('session.save_path', $sessionDir);
    foreach ([1, 2, 3] as $id) {
        session_id($argv[3 + $id] ?? '');
        session_start();
        $_SESSION['user_id'] = $id;
        $_SESSION['user'] = ['id' => $id];
        $_SESSION['last_activity'] = time();
        session_write_close();
    }
    exit;
}
if ($action === 'reset') {
    $db->exec("UPDATE users SET user_type = CASE WHEN id IN (1,2) THEN 'admin' ELSE 'staff' END, is_active = 1");
    exit;
}
if ($action === 'last') {
    $db->exec('UPDATE users SET is_active = 0 WHERE id = 2');
    exit;
}
if ($action === 'status') {
    echo json_encode($db->query('SELECT id, user_type, is_active FROM users ORDER BY id')->fetchAll());
    exit;
}
if ($action === 'seed_token') {
    $db->exec('DELETE FROM persistent_login_tokens WHERE user_id = 2');
    $stmt = $db->prepare('INSERT INTO persistent_login_tokens (user_id, selector, token_hash, expires_at) VALUES (2, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))');
    $stmt->execute([str_repeat('a', 24), str_repeat('b', 64)]);
    exit;
}
if ($action === 'token_count') {
    echo $db->query('SELECT COUNT(*) FROM persistent_login_tokens WHERE user_id = 2')->fetchColumn();
    exit;
}
if ($action === 'collector_account') {
    echo json_encode($db->query("SELECT u.id, u.username, u.password_hash, u.user_type, c.id AS collector_id, c.name AS collector_name FROM users u LEFT JOIN bio_collectors c ON c.user_id = u.id WHERE u.username = 'collector-login'")->fetch());
    exit;
}
if ($action === 'seed_unlinked_collector') {
    $db->exec("INSERT INTO bio_collectors (name, name_key) VALUES ('Existing Collector', SHA2('existing collector', 256))");
    echo $db->lastInsertId();
    exit;
}
if ($action === 'linked_collector_account') {
    echo json_encode($db->query("SELECT u.id, u.user_type, c.id AS collector_id, c.name AS collector_name FROM users u LEFT JOIN bio_collectors c ON c.user_id = u.id WHERE u.username = 'collector-linked'")->fetch());
    exit;
}
if ($action === 'lock') {
    $db->beginTransaction();
    $db->query("SELECT id FROM users WHERE user_type = 'admin' AND is_active = 1 ORDER BY id FOR UPDATE")->fetchAll();
    echo "LOCKED\n";
    flush();
    fgets(STDIN);
    $db->commit();
    exit;
}
if ($action === 'waiters') {
    $stmt = $server->prepare("SELECT COMMAND, STATE, INFO FROM information_schema.processlist WHERE DB = ? AND ID <> CONNECTION_ID()");
    $stmt->execute([$name]);
    echo json_encode($stmt->fetchAll());
    exit;
}
throw new RuntimeException('Unknown fixture action.');
