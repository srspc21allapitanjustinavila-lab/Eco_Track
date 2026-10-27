<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/staff_attendance_management.php';
$checks = 0;
function checkStaff($condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
$server = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$database = 'ecotrack_staff_attendance_test_' . bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
try {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $database . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $db->exec("CREATE TABLE users (id INT NOT NULL PRIMARY KEY, username VARCHAR(50), first_name VARCHAR(50), last_name VARCHAR(50), user_type VARCHAR(20), is_active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB");
    $db->exec("INSERT INTO users VALUES (1, 'admin', 'Admin', 'User', 'admin', 1), (2, 'maria', 'Maria', 'Cruz', 'staff', 1), (3, 'inactive', 'Old', 'Staff', 'staff', 0), (4, 'fallback', '', '', 'staff', 1)");
    $db->exec("CREATE TABLE waste_records (id INT AUTO_INCREMENT PRIMARY KEY, is_active TINYINT NOT NULL DEFAULT 1, collection_date DATE NULL, name_of_bioman VARCHAR(100), garbage_collector VARCHAR(100)) ENGINE=InnoDB");
    $db->exec("INSERT INTO waste_records (collection_date, name_of_bioman) VALUES ('2011-01-01', 'Maria Cruz')");
    $db->exec("INSERT INTO waste_records (is_active, collection_date) VALUES (0, '2012-01-01')");
    $db->exec("CREATE TABLE collector_attendance (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, collector_user_id INT NOT NULL, attendance_date DATE NOT NULL, status ENUM('present','absent','late','on_leave') NOT NULL, recorded_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY unique_collector_attendance_day (collector_user_id, attendance_date), CONSTRAINT legacy_user FOREIGN KEY (collector_user_id) REFERENCES users(id)) ENGINE=InnoDB");
    $db->exec("INSERT INTO collector_attendance (collector_user_id, attendance_date, status, recorded_by) VALUES (3, '2013-02-01', 'present', 1), (2, '2014-02-01', 'late', 1)");
    ensureStaffAttendanceSchema($db);
    ensureStaffAttendanceSchema($db);
    syncBioCollectors($db);
    checkStaff((int)$db->query('SELECT COUNT(*) FROM staff_attendance_audit')->fetchColumn() === 0, 'Migration must not fabricate old audit entries.');
    $years = staffAttendanceYears($db);
    checkStaff(in_array(2011, $years, true) && in_array(2013, $years, true) && in_array(2014, $years, true) && in_array((int)substr(attendanceToday(), 0, 4), $years, true) && !in_array(2012, $years, true), 'Years reflect active waste, saved staff history and current year.');
    $result = staffAttendanceQuery($db, '2025-01-02', '', '', 1);
    checkStaff($result['total'] === 2 && $result['summary']['not_recorded'] === 2 && $result['summary']['absent'] === 0, 'Active staff appear before attendance is recorded.');
    checkStaff(in_array('Maria Cruz', array_column($result['rows'], 'name'), true) && in_array('fallback', array_column($result['rows'], 'name'), true), 'Full names and username fallback are displayed.');
    checkStaff(staffAttendanceHistory($db, '', '', '', 1)['total'] === 2 && staffAttendanceHistory($db, '', 'Old Staff', '', 1)['total'] === 1, 'Existing and inactive staff history is retained.');
    checkStaff(staffAttendanceHistory($db, '', 'Old Staff', 'present', 1, 30, '2013-02-01', '2013-02-28')['total'] === 1 && staffAttendanceHistory($db, '', 'Old Staff', 'present', 1, 30, '2013-02-02', '2013-02-28')['total'] === 0, 'Staff history applies inclusive print ranges and filters.');
    $date = '2025-01-02';
    checkStaff(saveStaffAttendance($db, $date, [2 => ['status' => 'present', 'remarks' => 'On time', 'version' => 0]], 2) === 1, 'Staff can create attendance.');
    $row = $db->query("SELECT * FROM collector_attendance WHERE collector_user_id = 2 AND attendance_date = '$date'")->fetch();
    $stale = false;
    try { saveStaffAttendance($db, $date, [2 => ['status' => 'late', 'remarks' => '', 'version' => 0]], 1); } catch (RuntimeException $e) { $stale = $e->getCode() === 409; }
    checkStaff($stale && (int)$db->query("SELECT COUNT(*) FROM collector_attendance WHERE collector_user_id = 2 AND attendance_date = '$date'")->fetchColumn() === 1, 'Stale writes cannot duplicate an entry.');
    $duplicate = false;
    try { $db->exec("INSERT INTO collector_attendance (collector_user_id, attendance_date, status) VALUES (2, '$date', 'late')"); } catch (PDOException $e) { $duplicate = true; }
    checkStaff($duplicate, 'Database uniqueness protects each staff and date.');
    checkStaff(saveStaffAttendance($db, $date, [2 => ['status' => 'late', 'remarks' => 'Corrected', 'version' => 1]], 1) === 1, 'Admin can edit staff attendance.');
    checkStaff(staffAttendanceQuery($db, $date, 'Maria', 'late', 1)['total'] === 1 && staffAttendanceQuery($db, $date, 'Maria', 'present', 1)['total'] === 0, 'Name and status filters combine correctly.');
    $denied = false;
    try { deleteStaffAttendance($db, (int)$row['id'], 2, 2, 'staff'); } catch (RuntimeException $e) { $denied = $e->getCode() === 403; }
    checkStaff($denied, 'Staff deletion is forbidden in backend.');
    deleteStaffAttendance($db, (int)$row['id'], 2, 1, 'admin');
    checkStaff(staffAttendanceQuery($db, $date, '', '', 1)['summary']['not_recorded'] === 2 && staffAttendanceHistory($db, $date, '', '', 1)['total'] === 0, 'Deleted entries are excluded from active history.');
    checkStaff(saveStaffAttendance($db, $date, [2 => ['status' => 'absent', 'remarks' => '', 'version' => 3]], 2) === 1, 'Staff can restore a soft-deleted entry.');
    checkStaff((int)$db->query("SELECT COUNT(*) FROM staff_attendance_audit WHERE attendance_id = " . (int)$row['id'])->fetchColumn() === 4 && (int)$db->query('SELECT COUNT(*) FROM bio_attendance_audit')->fetchColumn() === 0, 'Create, update, delete and restore are audited separately.');
    $invalid = false;
    try { saveStaffAttendance($db, $date, [2 => ['status' => 'invalid', 'remarks' => '', 'version' => 4]], 2); } catch (InvalidArgumentException $e) { $invalid = true; }
    checkStaff($invalid && !attendanceValidDate('2025-02-30'), 'Invalid status and date are rejected.');
    $failedBatch = false;
    try { saveStaffAttendance($db, $date, [2 => ['status' => 'late', 'remarks' => '', 'version' => 4], 4 => ['status' => 'invalid', 'remarks' => '', 'version' => 0]], 1); } catch (InvalidArgumentException $e) { $failedBatch = true; }
    checkStaff($failedBatch && $db->query('SELECT status FROM collector_attendance WHERE id = ' . (int)$row['id'])->fetchColumn() === 'absent', 'Invalid batches roll back all changes.');
    $bioId = (int)$db->query("SELECT id FROM bio_collectors WHERE name = 'Maria Cruz'")->fetchColumn();
    checkStaff($bioId > 0 && bioAttendanceQuery($db, $date, 'Maria', '', 1)['summary']['not_recorded'] === 1, 'Same-name Bio Collector remains separate.');
    for ($i = 5; $i <= 36; $i++) $db->exec("INSERT INTO users VALUES ($i, 'staff$i', 'Staff', '$i', 'staff', 1)");
    $paged = staffAttendanceQuery($db, $date, '', '', 1, 10);
    checkStaff($paged['total'] === 34 && count($paged['rows']) === 10 && $paged['summary']['absent'] === 1 && $paged['summary']['not_recorded'] === 33 && $paged['pages'] === 4, 'Counts cover the full matching roster across pages.');
    $noInactiveWrite = false;
    try { saveStaffAttendance($db, $date, [3 => ['status' => 'present', 'remarks' => '', 'version' => 0]], 1); } catch (InvalidArgumentException $e) { $noInactiveWrite = true; }
    checkStaff($noInactiveWrite, 'Inactive staff cannot be written through the roster.');
    ensureWasteCollectorAccountSchema($db);
    $db->exec("INSERT INTO users (id, username, first_name, last_name, user_type, is_active) VALUES (37, 'collector-one', 'Collector', 'One', 'waste_collector', 1), (38, 'collector-two', 'Collector', 'Two', 'waste_collector', 1)");
    $db->exec("INSERT INTO bio_collectors (name, name_key, user_id) VALUES ('Collector One', 'collector-one-key', 37), ('Collector Two', 'collector-two-key', 38)");
    $today = attendanceToday();
    checkStaff(ownBioAttendanceQuery($db, 37, $today)['total'] === 1, 'A collector sees only the registry record linked to their account.');
    checkStaff(saveOwnBioAttendance($db, 37, $today, ['status' => 'present', 'remarks' => 'Ready', 'version' => 0]) === 1, 'A collector can save only their own current-day attendance.');
    checkStaff(saveBioAttendance($db, $today, [((int)$db->query("SELECT id FROM bio_collectors WHERE user_id = 38")->fetchColumn()) => ['status' => 'late', 'remarks' => '', 'version' => 0]], 1) === 1, 'Staff/admin attendance remains able to record other collectors.');
    $selfHistory = ownBioAttendanceHistory($db, 37, '', '', 1);
    checkStaff($selfHistory['total'] === 1 && $selfHistory['rows'][0]['name'] === 'Collector One', 'Collector history never returns another collector\'s attendance.');
    $futureDenied = false;
    try { saveOwnBioAttendance($db, 37, '2099-01-01', ['status' => 'late', 'remarks' => '', 'version' => 1]); } catch (RuntimeException $e) { $futureDenied = $e->getCode() === 403; }
    checkStaff($futureDenied, 'Collector self-service rejects past and future attendance writes.');
    echo 'PASS: ' . $checks . " staff attendance integration checks\n";
} finally {
    $server->exec('DROP DATABASE `' . $database . '`');
}
