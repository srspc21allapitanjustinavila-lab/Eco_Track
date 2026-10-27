<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/attendance_management.php';

$checks = 0;
function checkBio($condition, $description) {
    global $checks;
    if (!$condition) throw new RuntimeException($description);
    $checks++;
}
$server = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$database = 'ecotrack_attendance_test_' . bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
try {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $database . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $db->exec("CREATE TABLE users (id INT NOT NULL PRIMARY KEY, username VARCHAR(50), user_type VARCHAR(20)) ENGINE=InnoDB");
    $db->exec("INSERT INTO users VALUES (1, 'admin', 'admin'), (2, 'staff', 'staff')");
    $db->exec("CREATE TABLE waste_records (id INT AUTO_INCREMENT PRIMARY KEY, is_active TINYINT NOT NULL DEFAULT 1, collection_date DATE NULL, name_of_bioman VARCHAR(100), garbage_collector VARCHAR(100)) ENGINE=InnoDB");
    $db->exec("CREATE TABLE collector_attendance (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, collector_user_id INT NOT NULL, attendance_date DATE NOT NULL, status ENUM('present','absent','late','on_leave') NOT NULL, recorded_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY unique_collector_attendance_day (collector_user_id, attendance_date), CONSTRAINT legacy_user FOREIGN KEY (collector_user_id) REFERENCES users(id)) ENGINE=InnoDB");
    $db->exec("INSERT INTO collector_attendance (collector_user_id, attendance_date, status, recorded_by) VALUES (2, '2025-01-01', 'present', 1)");
    $db->exec("INSERT INTO waste_records (name_of_bioman, garbage_collector) VALUES ('Maria  Cruz', 'Maria  Cruz'), ('maria cruz', 'maria cruz'), ('Alex Santos', 'Alex Santos'), ('', 'Other Person'), ('Unassigned', NULL)");
    ensureBioAttendanceSchema($db);
    ensureBioAttendanceSchema($db);
    syncBioCollectors($db);
    checkBio((int)$db->query('SELECT COUNT(*) FROM bio_collectors')->fetchColumn() === 3, 'Collector names are normalized and deduplicated.');
    checkBio((int)$db->query('SELECT COUNT(*) FROM collector_attendance WHERE collector_user_id = 2 AND bio_collector_id IS NULL')->fetchColumn() === 1, 'Legacy attendance remains separate.');
    $db->exec("INSERT INTO waste_records (name_of_bioman) VALUES ('New Imported Collector')");
    syncBioCollectors($db);
    checkBio((int)$db->query('SELECT COUNT(*) FROM bio_collectors')->fetchColumn() === 4, 'New imports are synchronized automatically.');
    $id = (int)$db->query("SELECT id FROM bio_collectors WHERE name = 'Maria  Cruz' OR name = 'Maria Cruz' LIMIT 1")->fetchColumn();
    checkBio($id > 0, 'Collector has a stable ID.');
    $date = '2025-03-02';
    checkBio(saveBioAttendance($db, $date, [$id => ['status' => 'present', 'remarks' => 'On time', 'version' => 0]], 2) === 1, 'Staff can create attendance.');
    $row = $db->query('SELECT * FROM collector_attendance WHERE bio_collector_id = ' . $id)->fetch();
    checkBio((int)$row['recorded_by'] === 2 && $row['status'] === 'present', 'Created record identifies staff and status.');
    $duplicateBlocked = false;
    try { saveBioAttendance($db, $date, [$id => ['status' => 'late', 'remarks' => '', 'version' => 0]], 1); } catch (RuntimeException $e) { $duplicateBlocked = $e->getCode() === 409; }
    checkBio($duplicateBlocked && (int)$db->query('SELECT COUNT(*) FROM collector_attendance WHERE bio_collector_id = ' . $id)->fetchColumn() === 1, 'Stale writes cannot duplicate or overwrite attendance.');
    checkBio(saveBioAttendance($db, $date, [$id => ['status' => 'late', 'remarks' => 'Corrected', 'version' => 1]], 1) === 1, 'Admin can correct attendance.');
    $result = bioAttendanceQuery($db, $date, '', '', 1);
    checkBio($result['summary']['late'] === 1 && $result['summary']['not_recorded'] === 3 && $result['total'] === 4, 'Summary includes unrecorded without counting them absent.');
    checkBio(bioAttendanceQuery($db, $date, 'maria', 'late', 1)['total'] === 1 && bioAttendanceQuery($db, $date, (string)$id, 'not_recorded', 1)['total'] === 0, 'Search and status filters apply together.');
    checkBio(bioAttendanceQuery($db, $date, (string)$id, '', 1)['total'] === 0, 'Search does not match internal collector IDs.');
    $denied = false;
    try { deleteBioAttendance($db, (int)$row['id'], 2, 2, 'staff'); } catch (RuntimeException $e) { $denied = $e->getCode() === 403; }
    checkBio($denied, 'Staff cannot delete attendance.');
    deleteBioAttendance($db, (int)$row['id'], 2, 1, 'admin');
    checkBio(bioAttendanceQuery($db, $date, '', '', 1)['summary']['not_recorded'] === 4, 'Admin deletion removes the active record.');
    checkBio(saveBioAttendance($db, $date, [$id => ['status' => 'absent', 'remarks' => '', 'version' => 3]], 2) === 1, 'Saving restores a soft-deleted record.');
    checkBio((int)$db->query('SELECT COUNT(*) FROM collector_attendance WHERE bio_collector_id = ' . $id)->fetchColumn() === 1 && (int)$db->query('SELECT COUNT(*) FROM bio_attendance_audit')->fetchColumn() === 4, 'Restoration preserves one record and a full audit trail.');
    $invalid = false;
    try { saveBioAttendance($db, $date, [$id => ['status' => 'invalid', 'remarks' => '', 'version' => 4]], 2); } catch (InvalidArgumentException $e) { $invalid = true; }
    checkBio($invalid, 'Invalid status is rejected.');
    $otherId = (int)$db->query('SELECT id FROM bio_collectors WHERE id <> ' . $id . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
    $failedBatch = false;
    try {
        saveBioAttendance($db, $date, [$id => ['status' => 'late', 'remarks' => '', 'version' => 4], $otherId => ['status' => 'invalid', 'remarks' => '', 'version' => 0]], 2);
    } catch (InvalidArgumentException $e) { $failedBatch = true; }
    checkBio($failedBatch && $db->query('SELECT status FROM collector_attendance WHERE bio_collector_id = ' . $id)->fetchColumn() === 'absent', 'A failed batch rolls back earlier changes.');
    checkBio(bioAttendanceQuery($db, '2025-03-01', '', '', 1)['summary']['not_recorded'] === 4, 'Another date has independent unrecorded status.');
    $badDate = false;
    try { saveBioAttendance($db, '2025-02-30', [], 2); } catch (InvalidArgumentException $e) { $badDate = true; }
    checkBio($badDate && !attendanceValidDate('2100-01-01') && !attendanceValidDate('1899-12-31'), 'Invalid or unsupported calendar dates are rejected.');
    $db->exec("INSERT INTO waste_records (is_active, name_of_bioman) VALUES (0, 'Earlier Imported Collector')");
    syncBioCollectors($db);
    checkBio((int)$db->query('SELECT COUNT(*) FROM bio_collectors')->fetchColumn() === 5, 'Collectors in older inactive waste records remain discoverable.');
    $db->exec("DELETE FROM waste_records WHERE name_of_bioman = 'New Imported Collector'");
    syncBioCollectors($db);
    checkBio((int)$db->query("SELECT COUNT(*) FROM bio_collectors WHERE name = 'New Imported Collector'")->fetchColumn() === 1, 'A collector remains in the registry after its source waste record is removed.');
    $db->exec("INSERT INTO waste_records (collection_date, name_of_bioman) VALUES ('2011-05-01', 'Alex Santos'), ('2013-01-01', '   SADA   ')");
    $db->exec("INSERT INTO waste_records (is_active, collection_date, name_of_bioman) VALUES (0, '2012-01-01', 'Alex Santos')");
    $db->prepare('INSERT INTO bio_collectors (name, name_key) VALUES (?, ?)')->execute(['sada', attendanceNameKey('sada')]);
    $sadaId = (int)$db->lastInsertId();
    saveBioAttendance($db, '2014-04-01', [$sadaId => ['status' => 'present', 'remarks' => 'History', 'version' => 0]], 1);
    $wasteCount = (int)$db->query('SELECT COUNT(*) FROM waste_records')->fetchColumn();
    $attendanceCount = (int)$db->query('SELECT COUNT(*) FROM collector_attendance')->fetchColumn();
    $auditCount = (int)$db->query('SELECT COUNT(*) FROM bio_attendance_audit')->fetchColumn();
    syncBioCollectors($db);
    syncBioCollectors($db);
    checkBio((int)$db->query('SELECT is_active FROM bio_collectors WHERE id = ' . $sadaId)->fetchColumn() === 0 && bioAttendanceQuery($db, '2014-04-01', 'sada', '', 1)['total'] === 0, 'Sada is deactivated and remains excluded after repeated synchronization.');
    checkBio((int)$db->query('SELECT COUNT(*) FROM waste_records')->fetchColumn() === $wasteCount && (int)$db->query('SELECT COUNT(*) FROM collector_attendance')->fetchColumn() === $attendanceCount && (int)$db->query('SELECT COUNT(*) FROM bio_attendance_audit')->fetchColumn() === $auditCount, 'Deactivation preserves waste, attendance and audit history.');
    $years = attendanceAvailableYears($db);
    checkBio(in_array(2011, $years, true) && in_array(2013, $years, true) && in_array(2014, $years, true) && in_array((int)substr(attendanceToday(), 0, 4), $years, true) && !in_array(2012, $years, true), 'Available years include active waste, saved attendance and the current year, but not inactive waste alone.');
    saveBioAttendance($db, '2015-01-01', [$id => ['status' => 'present', 'remarks' => '', 'version' => 0]], 1);
    $deletedId = (int)$db->query("SELECT id FROM collector_attendance WHERE bio_collector_id = $id AND attendance_date = '2015-01-01'")->fetchColumn();
    deleteBioAttendance($db, $deletedId, 1, 1, 'admin');
    checkBio(in_array(2015, attendanceAvailableYears($db), true), 'A year with only soft-deleted attendance stays selectable for restoration.');
    $alexId = (int)$db->query("SELECT id FROM bio_collectors WHERE name = 'Alex Santos'")->fetchColumn();
    saveBioAttendance($db, '2014-04-01', [$alexId => ['status' => 'present', 'remarks' => 'History', 'version' => 0]], 1);
    $db->exec('UPDATE bio_collectors SET is_active = 0 WHERE id = ' . $alexId);
    $inactiveHistory = bioAttendanceHistory($db, '2014-04-01', 'Alex Santos', 'present', 1);
    checkBio($inactiveHistory['total'] === 1 && !$inactiveHistory['rows'][0]['is_active'], 'History retains saved attendance for inactive collectors.');
    checkBio(bioAttendanceHistory($db, '', 'sada', '', 1)['total'] === 0 && bioAttendanceHistory($db, '', '', '', 1, 30, '2014-04-01', '2014-04-30')['total'] === 1 && (int)$db->query('SELECT COUNT(*) FROM collector_attendance WHERE bio_collector_id = ' . $sadaId)->fetchColumn() === 1, 'Sada is excluded from history and print ranges while stored records remain intact.');
    checkBio(bioAttendanceHistory($db, '2015-01-01', '', '', 1)['total'] === 0 && bioAttendanceHistory($db, '', 'maria', 'absent', 1)['total'] === 1, 'History excludes deleted entries and applies combined filters.');
    checkBio(bioAttendanceHistory($db, '', '', '', 1, 1)['pages'] === 2, 'History pagination counts all saved collector records only.');
    checkBio(bioAttendanceHistory($db, '', 'Alex', 'present', 1, 30, '2014-04-01', '2014-04-30')['total'] === 1 && bioAttendanceHistory($db, '', 'Alex', 'present', 1, 30, '2014-04-02', '2014-04-30')['total'] === 0, 'History ranges include their boundaries and respect name and status.');
    checkBio(attendancePrintDateRange('week', '2025-01-01') === ['2024-12-30', '2025-01-05'] && attendancePrintDateRange('month', '2024-02-15') === ['2024-02-01', '2024-02-29'], 'Print periods handle year boundaries and leap years.');
    $invalidPeriod = false;
    try { attendancePrintDateRange('year', '2025-01-01'); } catch (InvalidArgumentException $e) { $invalidPeriod = true; }
    checkBio($invalidPeriod, 'Unsupported print periods are rejected.');
    echo 'PASS: ' . $checks . " Bio Collector attendance integration checks\n";
} finally {
    // This database name is generated above specifically for this test run.
    $server->exec('DROP DATABASE `' . $database . '`');
}
