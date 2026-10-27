<?php

/** Shared Bio Collector attendance storage. Legacy staff-account rows remain untouched. */
function attendanceToday(): string
{
    $zone = environmentValue('APP_TIMEZONE', 'Asia/Manila');
    try { $timezone = new DateTimeZone($zone); } catch (Throwable $e) { $timezone = new DateTimeZone('Asia/Manila'); }
    return (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
}

function attendanceValidDate($value): bool
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || (int)substr($value, 0, 4) < 1900 || (int)substr($value, 0, 4) > 2099) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

/** Return inclusive calendar boundaries for a printable attendance period. */
function attendancePrintDateRange(string $period, string $anchor): array
{
    if (!attendanceValidDate($anchor) || !in_array($period, ['week', 'month'], true)) {
        throw new InvalidArgumentException('Choose a valid print period and date.');
    }
    $date = new DateTimeImmutable($anchor);
    if ($period === 'week') {
        $start = $date->modify('monday this week');
        $end = $start->modify('+6 days');
    } else {
        $start = $date->modify('first day of this month');
        $end = $date->modify('last day of this month');
    }
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function attendanceName($value): string
{
    return trim(preg_replace('/\s+/u', ' ', trim((string)$value)) ?? '');
}

function attendanceNameKey(string $name): string
{
    $name = attendanceName($name);
    return hash('sha256', function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name));
}

function ensureBioAttendanceSchema(PDO $db): void
{
    ensureCollectorAttendanceTable($db);
    $db->exec("CREATE TABLE IF NOT EXISTS bio_collectors (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        name_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        user_id INT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_bio_collector_name_key (name_key),
        UNIQUE KEY unique_bio_collector_user (user_id),
        CONSTRAINT fk_bio_collector_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $bioColumns = [];
    foreach ($db->query('SHOW COLUMNS FROM bio_collectors') as $column) $bioColumns[$column['Field']] = $column;
    if (!isset($bioColumns['user_id'])) {
        $db->exec('ALTER TABLE bio_collectors ADD COLUMN user_id INT NULL AFTER name_key');
    }
    $bioIndexes = [];
    foreach ($db->query('SHOW INDEX FROM bio_collectors') as $index) $bioIndexes[$index['Key_name']] = true;
    if (!isset($bioIndexes['unique_bio_collector_user'])) {
        $db->exec('ALTER TABLE bio_collectors ADD UNIQUE KEY unique_bio_collector_user (user_id)');
    }
    $bioForeignKey = $db->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bio_collectors' AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = 'users'");
    $bioForeignKey->execute();
    if (!(int)$bioForeignKey->fetchColumn()) {
        $db->exec('ALTER TABLE bio_collectors ADD CONSTRAINT fk_bio_collector_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT');
    }
    $columns = [];
    foreach ($db->query('SHOW COLUMNS FROM collector_attendance') as $column) $columns[$column['Field']] = $column;
    if (($columns['collector_user_id']['Null'] ?? '') !== 'YES') {
        $db->exec('ALTER TABLE collector_attendance MODIFY collector_user_id INT NULL');
    }
    $additions = [
        'bio_collector_id' => 'BIGINT UNSIGNED NULL',
        'remarks' => 'VARCHAR(500) NOT NULL DEFAULT \'\'',
        'updated_by' => 'INT NULL',
        'deleted_at' => 'DATETIME NULL',
        'deleted_by' => 'INT NULL',
        'version' => 'INT UNSIGNED NOT NULL DEFAULT 1',
    ];
    foreach ($additions as $name => $definition) {
        if (!isset($columns[$name])) $db->exec("ALTER TABLE collector_attendance ADD COLUMN $name $definition");
    }
    $indexes = [];
    foreach ($db->query('SHOW INDEX FROM collector_attendance') as $index) $indexes[$index['Key_name']] = true;
    if (!isset($indexes['unique_bio_collector_day'])) {
        $db->exec('ALTER TABLE collector_attendance ADD UNIQUE KEY unique_bio_collector_day (bio_collector_id, attendance_date)');
    }
    $foreignKey = $db->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'collector_attendance' AND COLUMN_NAME = 'bio_collector_id' AND REFERENCED_TABLE_NAME = 'bio_collectors'");
    $foreignKey->execute();
    if (!(int)$foreignKey->fetchColumn()) {
        $db->exec('ALTER TABLE collector_attendance ADD CONSTRAINT fk_attendance_bio_collector FOREIGN KEY (bio_collector_id) REFERENCES bio_collectors(id) ON DELETE RESTRICT');
    }
    $db->exec("CREATE TABLE IF NOT EXISTS bio_attendance_audit (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        attendance_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(12) NOT NULL,
        actor_id INT NULL,
        before_json TEXT NULL,
        after_json TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_bio_attendance_audit_record (attendance_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Add the collector account role and one-to-one registry link to live installations. */
function ensureWasteCollectorAccountSchema(PDO $db): void
{
    $roleColumn = $db->query("SHOW COLUMNS FROM users LIKE 'user_type'")->fetch(PDO::FETCH_ASSOC);
    if (!$roleColumn) {
        throw new RuntimeException('The user role column is unavailable.');
    }
    if (stripos((string)($roleColumn['Type'] ?? ''), "'waste_collector'") === false) {
        $db->exec("ALTER TABLE users MODIFY user_type ENUM('admin', 'staff', 'waste_collector') NOT NULL");
    }
    ensureBioAttendanceSchema($db);
}

function syncBioCollectors(PDO $db): void
{
    // Keep linked records, but exclude this exact name even if imported again.
    $excludedKey = attendanceNameKey('sada');
    $deactivate = $db->prepare('UPDATE bio_collectors SET is_active = 0 WHERE name_key = ? AND is_active = 1');
    $deactivate->execute([$excludedKey]);
    $columns = [];
    try {
        foreach ($db->query('SHOW COLUMNS FROM waste_records') as $column) $columns[$column['Field']] = true;
    } catch (PDOException $e) {
        return;
    }
    $names = [];
    foreach (['name_of_bioman', 'garbage_collector'] as $column) if (isset($columns[$column])) $names[] = "NULLIF(TRIM(`$column`), '')";
    if (!$names) return;
    $expression = count($names) === 1 ? $names[0] : 'COALESCE(' . implode(', ', $names) . ')';
    // An older waste row may already have been archived before the registry
    // existed. Its named collector is still an existing collector identity.
    $query = $db->query("SELECT DISTINCT $expression AS collector_name FROM waste_records");
    $insert = $db->prepare('INSERT INTO bio_collectors (name, name_key) VALUES (?, ?) ON DUPLICATE KEY UPDATE id = id');
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $name = attendanceName($row['collector_name'] ?? '');
        if ($name === '' || strtolower($name) === 'unassigned' || attendanceNameKey($name) === $excludedKey) continue;
        $insert->execute([$name, attendanceNameKey($name)]);
    }
}

/** Years with active waste or saved Bio Collector attendance, plus this year. */
function attendanceAvailableYears(PDO $db): array
{
    $years = [(int)substr(attendanceToday(), 0, 4)];
    $queries = [
        'SELECT DISTINCT YEAR(collection_date) AS year_value FROM waste_records WHERE is_active = 1 AND collection_date IS NOT NULL',
        // Keep soft-deleted years selectable so an entry can be restored.
        'SELECT DISTINCT YEAR(attendance_date) AS year_value FROM collector_attendance WHERE bio_collector_id IS NOT NULL',
    ];
    foreach ($queries as $query) {
        foreach ($db->query($query) as $row) {
            $year = (int)$row['year_value'];
            if ($year >= 1900 && $year <= 2099) $years[] = $year;
        }
    }
    $years = array_values(array_unique($years));
    rsort($years, SORT_NUMERIC);
    return $years;
}

function attendanceAudit(PDO $db, int $id, string $action, int $actor, $before, $after): void
{
    $stmt = $db->prepare('INSERT INTO bio_attendance_audit (attendance_id, action, actor_id, before_json, after_json) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$id, $action, $actor, $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE), $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE)]);
}

/** Serializes writes per collector, including when the date has no record yet. */
function saveBioAttendance(PDO $db, string $date, array $entries, int $actor): int
{
    if (!attendanceValidDate($date)) throw new InvalidArgumentException('Invalid attendance date.');
    if (count($entries) > 200) throw new InvalidArgumentException('Too many attendance entries.');
    ksort($entries, SORT_NUMERIC);
    $db->beginTransaction();
    $changed = 0;
    try {
        $lockCollector = $db->prepare('SELECT id FROM bio_collectors WHERE id = ? AND is_active = 1 FOR UPDATE');
        $find = $db->prepare('SELECT * FROM collector_attendance WHERE bio_collector_id = ? AND attendance_date = ? FOR UPDATE');
        $create = $db->prepare('INSERT INTO collector_attendance (bio_collector_id, attendance_date, status, remarks, recorded_by, updated_by) VALUES (?, ?, ?, ?, ?, ?)');
        $update = $db->prepare('UPDATE collector_attendance SET status = ?, remarks = ?, updated_by = ?, deleted_at = NULL, deleted_by = NULL, version = version + 1 WHERE id = ?');
        foreach ($entries as $rawId => $entry) {
            $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id || !is_array($entry)) throw new InvalidArgumentException('Invalid collector selection.');
            $status = is_string($entry['status'] ?? null) ? trim($entry['status']) : '';
            $remarks = is_string($entry['remarks'] ?? null) ? trim($entry['remarks']) : '';
            $version = filter_var($entry['version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($version === false || ($status !== '' && normalizeAttendanceStatus($status) !== $status) || strlen($remarks) > 500) throw new InvalidArgumentException('Invalid status, remarks, or record version.');
            if ($status === '' && $remarks !== '') throw new InvalidArgumentException('Select a status before adding remarks.');
            $lockCollector->execute([$id]);
            if (!$lockCollector->fetchColumn()) throw new InvalidArgumentException('A collector is no longer active.');
            $find->execute([$id, $date]);
            $before = $find->fetch(PDO::FETCH_ASSOC) ?: null;
            if ((int)($before['version'] ?? 0) !== $version) throw new RuntimeException('Attendance changed in another session. Reload and try again.', 409);
            if ($status === '') continue;
            if ($before && $before['deleted_at'] === null && $before['status'] === $status && $before['remarks'] === $remarks) continue;
            if ($before) {
                $update->execute([$status, $remarks, $actor, $before['id']]);
                $recordId = (int)$before['id'];
                $action = $before['deleted_at'] !== null ? 'restore' : 'update';
            } else {
                $create->execute([$id, $date, $status, $remarks, $actor, $actor]);
                $recordId = (int)$db->lastInsertId();
                $action = 'create';
            }
            $find->execute([$id, $date]);
            attendanceAudit($db, $recordId, $action, $actor, $before, $find->fetch(PDO::FETCH_ASSOC));
            $changed++;
        }
        $db->commit();
        return $changed;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Save one current-day record for the collector linked to the authenticated account. */
function saveOwnBioAttendance(PDO $db, int $userId, string $date, array $entry): int
{
    if ($userId < 1 || $date !== attendanceToday()) {
        throw new RuntimeException('Waste Collectors can only update today\'s attendance.', 403);
    }
    $status = normalizeAttendanceStatus($entry['status'] ?? '');
    $remarks = is_string($entry['remarks'] ?? null) ? trim($entry['remarks']) : '';
    $version = filter_var($entry['version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($status === '' || $version === false || strlen($remarks) > 500) {
        throw new InvalidArgumentException('Choose a valid status and remarks.');
    }
    $db->beginTransaction();
    try {
        $collector = $db->prepare('SELECT id FROM bio_collectors WHERE user_id = ? AND is_active = 1 FOR UPDATE');
        $collector->execute([$userId]);
        $collectorId = (int)$collector->fetchColumn();
        if (!$collectorId) throw new RuntimeException('Your Waste Collector profile is not linked to an active record. Contact an administrator.', 403);
        $find = $db->prepare('SELECT * FROM collector_attendance WHERE bio_collector_id = ? AND attendance_date = ? FOR UPDATE');
        $find->execute([$collectorId, $date]);
        $before = $find->fetch(PDO::FETCH_ASSOC) ?: null;
        if ((int)($before['version'] ?? 0) !== $version) throw new RuntimeException('Attendance changed in another session. Reload and try again.', 409);
        if ($before && $before['deleted_at'] === null && $before['status'] === $status && $before['remarks'] === $remarks) {
            $db->commit();
            return 0;
        }
        if ($before) {
            $update = $db->prepare('UPDATE collector_attendance SET status = ?, remarks = ?, updated_by = ?, deleted_at = NULL, deleted_by = NULL, version = version + 1 WHERE id = ?');
            $update->execute([$status, $remarks, $userId, $before['id']]);
            $recordId = (int)$before['id'];
            $action = $before['deleted_at'] !== null ? 'restore' : 'update';
        } else {
            $create = $db->prepare('INSERT INTO collector_attendance (bio_collector_id, attendance_date, status, remarks, recorded_by, updated_by) VALUES (?, ?, ?, ?, ?, ?)');
            $create->execute([$collectorId, $date, $status, $remarks, $userId, $userId]);
            $recordId = (int)$db->lastInsertId();
            $action = 'create';
        }
        $find->execute([$collectorId, $date]);
        attendanceAudit($db, $recordId, $action, $userId, $before, $find->fetch(PDO::FETCH_ASSOC));
        $db->commit();
        return 1;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Return the single collector row linked to this account, never a posted identity. */
function ownBioAttendanceQuery(PDO $db, int $userId, string $date): array
{
    $stmt = $db->prepare('SELECT c.id, c.name, a.id AS attendance_id, a.status, a.remarks, a.deleted_at, a.version, recorder.username AS recorder, editor.username AS editor FROM bio_collectors c LEFT JOIN collector_attendance a ON a.bio_collector_id = c.id AND a.attendance_date = ? LEFT JOIN users recorder ON recorder.id = a.recorded_by LEFT JOIN users editor ON editor.id = a.updated_by WHERE c.user_id = ? AND c.is_active = 1 LIMIT 1');
    $stmt->execute([$date, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $summary = array_fill_keys(array_merge(array_keys(attendanceStatusOptions()), ['not_recorded']), 0);
    if ($row && $row['attendance_id'] && $row['deleted_at'] === null) $summary[$row['status']] = 1;
    else if ($row) $summary['not_recorded'] = 1;
    return ['rows' => $row ? [$row] : [], 'summary' => $summary, 'total' => $row ? 1 : 0, 'page' => 1, 'pages' => 1];
}

function ownBioAttendanceHistory(PDO $db, int $userId, string $date, string $status, int $page, int $pageSize = 30): array
{
    $where = ['c.user_id = ?', 'a.deleted_at IS NULL'];
    $params = [$userId];
    if ($date !== '') { $where[] = 'a.attendance_date = ?'; $params[] = $date; }
    if ($status !== '') { $where[] = 'a.status = ?'; $params[] = $status; }
    $from = ' FROM collector_attendance a JOIN bio_collectors c ON c.id = a.bio_collector_id';
    $whereSql = implode(' AND ', $where);
    $count = $db->prepare("SELECT COUNT(*) $from WHERE $whereSql");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $pageSize;
    $stmt = $db->prepare("SELECT a.attendance_date, a.status, a.remarks, a.updated_at, c.name, c.is_active, recorder.username AS recorder, editor.username AS editor $from LEFT JOIN users recorder ON recorder.id = a.recorded_by LEFT JOIN users editor ON editor.id = a.updated_by WHERE $whereSql ORDER BY a.attendance_date DESC, a.id DESC LIMIT $pageSize OFFSET $offset");
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function deleteBioAttendance(PDO $db, int $id, int $version, int $actor, string $role): void
{
    if ($role !== 'admin') throw new RuntimeException('Administrator access required.', 403);
    $db->beginTransaction();
    try {
        $find = $db->prepare('SELECT * FROM collector_attendance WHERE id = ? AND bio_collector_id IS NOT NULL FOR UPDATE');
        $find->execute([$id]);
        $before = $find->fetch(PDO::FETCH_ASSOC);
        if (!$before || $before['deleted_at'] !== null || (int)$before['version'] !== $version) throw new RuntimeException('Attendance changed or was deleted. Reload and try again.', 409);
        $update = $db->prepare('UPDATE collector_attendance SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ?, updated_by = ?, version = version + 1 WHERE id = ?');
        $update->execute([$actor, $actor, $id]);
        $find->execute([$id]);
        attendanceAudit($db, $id, 'delete', $actor, $before, $find->fetch(PDO::FETCH_ASSOC));
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function bioAttendanceWhere(string $date, string $search, string $status): array
{
    $where = ['c.is_active = 1'];
    $params = [$date];
    if ($search !== '') { $where[] = 'c.name LIKE ?'; $params[] = '%' . $search . '%'; }
    if ($status === 'not_recorded') $where[] = '(a.id IS NULL OR a.deleted_at IS NOT NULL)';
    elseif ($status !== '') { $where[] = 'a.deleted_at IS NULL AND a.status = ?'; $params[] = $status; }
    return [implode(' AND ', $where), $params];
}

function bioAttendanceQuery(PDO $db, string $date, string $search, string $status, int $page, int $pageSize = 30): array
{
    [$where, $params] = bioAttendanceWhere($date, $search, $status);
    $from = ' FROM bio_collectors c LEFT JOIN collector_attendance a ON a.bio_collector_id = c.id AND a.attendance_date = ?';
    $summaryStmt = $db->prepare("SELECT CASE WHEN a.id IS NULL OR a.deleted_at IS NOT NULL THEN 'not_recorded' ELSE a.status END AS state, COUNT(*) AS total $from WHERE $where GROUP BY state");
    $summaryStmt->execute($params);
    $summary = array_fill_keys(array_merge(array_keys(attendanceStatusOptions()), ['not_recorded']), 0);
    foreach ($summaryStmt as $row) if (isset($summary[$row['state']])) $summary[$row['state']] = (int)$row['total'];
    $total = array_sum($summary);
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $pageSize;
    $stmt = $db->prepare("SELECT c.id, c.name, a.id AS attendance_id, a.status, a.remarks, a.deleted_at, a.version, recorder.username AS recorder, editor.username AS editor $from LEFT JOIN users recorder ON recorder.id = a.recorded_by LEFT JOIN users editor ON editor.id = a.updated_by WHERE $where ORDER BY c.name, c.id LIMIT $pageSize OFFSET $offset");
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'summary' => $summary, 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** Saved collector records, including inactive collectors, with optional inclusive range. */
function bioAttendanceHistory(PDO $db, string $date, string $search, string $status, int $page, int $pageSize = 30, string $start = '', string $end = ''): array
{
    $where = ['a.bio_collector_id IS NOT NULL', 'a.deleted_at IS NULL', 'c.name_key <> ?'];
    $params = [attendanceNameKey('sada')];
    if ($date !== '') { $where[] = 'a.attendance_date = ?'; $params[] = $date; }
    if ($start !== '') { $where[] = 'a.attendance_date >= ?'; $params[] = $start; }
    if ($end !== '') { $where[] = 'a.attendance_date <= ?'; $params[] = $end; }
    if ($search !== '') { $where[] = 'c.name LIKE ?'; $params[] = '%' . $search . '%'; }
    if ($status !== '') { $where[] = 'a.status = ?'; $params[] = $status; }
    $from = ' FROM collector_attendance a JOIN bio_collectors c ON c.id = a.bio_collector_id';
    $whereSql = implode(' AND ', $where);
    $count = $db->prepare("SELECT COUNT(*) $from WHERE $whereSql");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $pageSize;
    $stmt = $db->prepare("SELECT a.attendance_date, a.status, a.remarks, a.updated_at, c.name, c.is_active, recorder.username AS recorder, editor.username AS editor $from LEFT JOIN users recorder ON recorder.id = a.recorded_by LEFT JOIN users editor ON editor.id = a.updated_by WHERE $whereSql ORDER BY a.attendance_date DESC, c.name, a.id DESC LIMIT $pageSize OFFSET $offset");
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'pages' => $pages];
}
