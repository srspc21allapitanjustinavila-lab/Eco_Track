<?php
require_once __DIR__ . '/attendance_management.php';

/** Staff account attendance shares storage, but never collector identities or audit events. */
function ensureStaffAttendanceSchema(PDO $db): void
{
    ensureBioAttendanceSchema($db);
    $db->exec("CREATE TABLE IF NOT EXISTS staff_attendance_audit (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        attendance_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(12) NOT NULL,
        actor_id INT NULL,
        before_json TEXT NULL,
        after_json TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_staff_attendance_audit_record (attendance_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function staffAttendanceYears(PDO $db): array
{
    $years = [(int)substr(attendanceToday(), 0, 4)];
    foreach ([
        'SELECT DISTINCT YEAR(collection_date) AS year_value FROM waste_records WHERE is_active = 1 AND collection_date IS NOT NULL',
        'SELECT DISTINCT YEAR(attendance_date) AS year_value FROM collector_attendance WHERE collector_user_id IS NOT NULL AND bio_collector_id IS NULL',
    ] as $sql) {
        foreach ($db->query($sql) as $row) {
            $year = (int)$row['year_value'];
            if ($year >= 1900 && $year <= 2099) $years[] = $year;
        }
    }
    $years = array_values(array_unique($years));
    rsort($years, SORT_NUMERIC);
    return $years;
}

function staffAttendanceAudit(PDO $db, int $id, string $action, int $actor, $before, $after): void
{
    $stmt = $db->prepare('INSERT INTO staff_attendance_audit (attendance_id, action, actor_id, before_json, after_json) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$id, $action, $actor, $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE), $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE)]);
}

function saveStaffAttendance(PDO $db, string $date, array $entries, int $actor): int
{
    if (!attendanceValidDate($date)) throw new InvalidArgumentException('Invalid attendance date.');
    if (count($entries) > 200) throw new InvalidArgumentException('Too many attendance entries.');
    ksort($entries, SORT_NUMERIC);
    $db->beginTransaction();
    $changed = 0;
    try {
        $lock = $db->prepare("SELECT id FROM users WHERE id = ? AND user_type = 'staff' AND is_active = 1 FOR UPDATE");
        $find = $db->prepare('SELECT * FROM collector_attendance WHERE collector_user_id = ? AND bio_collector_id IS NULL AND attendance_date = ? FOR UPDATE');
        $create = $db->prepare('INSERT INTO collector_attendance (collector_user_id, attendance_date, status, remarks, recorded_by, updated_by) VALUES (?, ?, ?, ?, ?, ?)');
        $update = $db->prepare('UPDATE collector_attendance SET status = ?, remarks = ?, updated_by = ?, deleted_at = NULL, deleted_by = NULL, version = version + 1 WHERE id = ?');
        foreach ($entries as $rawId => $entry) {
            $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id || !is_array($entry)) throw new InvalidArgumentException('Invalid staff selection.');
            $status = is_string($entry['status'] ?? null) ? trim($entry['status']) : '';
            $remarks = is_string($entry['remarks'] ?? null) ? trim($entry['remarks']) : '';
            $version = filter_var($entry['version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($version === false || ($status !== '' && normalizeAttendanceStatus($status) !== $status) || strlen($remarks) > 500) throw new InvalidArgumentException('Invalid status, remarks, or record version.');
            if ($status === '' && $remarks !== '') throw new InvalidArgumentException('Select a status before adding remarks.');
            $lock->execute([$id]);
            if (!$lock->fetchColumn()) throw new InvalidArgumentException('A staff account is no longer active.');
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
            staffAttendanceAudit($db, $recordId, $action, $actor, $before, $find->fetch(PDO::FETCH_ASSOC));
            $changed++;
        }
        $db->commit();
        return $changed;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function deleteStaffAttendance(PDO $db, int $id, int $version, int $actor, string $role): void
{
    if ($role !== 'admin') throw new RuntimeException('Administrator access required.', 403);
    $db->beginTransaction();
    try {
        $find = $db->prepare('SELECT * FROM collector_attendance WHERE id = ? AND collector_user_id IS NOT NULL AND bio_collector_id IS NULL FOR UPDATE');
        $find->execute([$id]);
        $before = $find->fetch(PDO::FETCH_ASSOC);
        if (!$before || $before['deleted_at'] !== null || (int)$before['version'] !== $version) throw new RuntimeException('Attendance changed or was deleted. Reload and try again.', 409);
        $update = $db->prepare('UPDATE collector_attendance SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ?, updated_by = ?, version = version + 1 WHERE id = ?');
        $update->execute([$actor, $actor, $id]);
        $find->execute([$id]);
        staffAttendanceAudit($db, $id, 'delete', $actor, $before, $find->fetch(PDO::FETCH_ASSOC));
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function staffAttendanceNameSql(): string
{
    return "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(u.first_name), ''), NULLIF(TRIM(u.last_name), ''))), ''), u.username)";
}

function staffAttendanceQuery(PDO $db, string $date, string $search, string $status, int $page, int $pageSize = 30): array
{
    $name = staffAttendanceNameSql();
    $where = ["u.user_type = 'staff'", 'u.is_active = 1'];
    $params = [$date];
    if ($search !== '') { $where[] = "$name LIKE ?"; $params[] = '%' . $search . '%'; }
    if ($status === 'not_recorded') $where[] = '(a.id IS NULL OR a.deleted_at IS NOT NULL)';
    elseif ($status !== '') { $where[] = 'a.deleted_at IS NULL AND a.status = ?'; $params[] = $status; }
    $from = ' FROM users u LEFT JOIN collector_attendance a ON a.collector_user_id = u.id AND a.bio_collector_id IS NULL AND a.attendance_date = ?';
    $whereSql = implode(' AND ', $where);
    $summaryStmt = $db->prepare("SELECT CASE WHEN a.id IS NULL OR a.deleted_at IS NOT NULL THEN 'not_recorded' ELSE a.status END AS state, COUNT(*) AS total $from WHERE $whereSql GROUP BY state");
    $summaryStmt->execute($params);
    $summary = array_fill_keys(array_merge(array_keys(attendanceStatusOptions()), ['not_recorded']), 0);
    foreach ($summaryStmt as $row) if (isset($summary[$row['state']])) $summary[$row['state']] = (int)$row['total'];
    $total = array_sum($summary);
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $pageSize;
    $stmt = $db->prepare("SELECT u.id, $name AS name, a.id AS attendance_id, a.status, a.remarks, a.deleted_at, a.version, recorder.username AS recorder, editor.username AS editor $from LEFT JOIN users recorder ON recorder.id = a.recorded_by LEFT JOIN users editor ON editor.id = a.updated_by WHERE $whereSql ORDER BY name, u.id LIMIT $pageSize OFFSET $offset");
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'summary' => $summary, 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** Include inactive and formerly staff accounts in historical results. */
function staffAttendanceHistory(PDO $db, string $date, string $search, string $status, int $page, int $pageSize = 30, string $start = '', string $end = ''): array
{
    $name = staffAttendanceNameSql();
    $where = ['a.collector_user_id IS NOT NULL', 'a.bio_collector_id IS NULL', 'a.deleted_at IS NULL'];
    $params = [];
    if ($date !== '') { $where[] = 'a.attendance_date = ?'; $params[] = $date; }
    if ($start !== '') { $where[] = 'a.attendance_date >= ?'; $params[] = $start; }
    if ($end !== '') { $where[] = 'a.attendance_date <= ?'; $params[] = $end; }
    if ($search !== '') { $where[] = "$name LIKE ?"; $params[] = '%' . $search . '%'; }
    if ($status !== '') { $where[] = 'a.status = ?'; $params[] = $status; }
    $from = ' FROM collector_attendance a JOIN users u ON u.id = a.collector_user_id';
    $whereSql = implode(' AND ', $where);
    $count = $db->prepare("SELECT COUNT(*) $from WHERE $whereSql");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $pageSize;
    $stmt = $db->prepare("SELECT a.attendance_date, a.status, a.remarks, a.updated_at, $name AS name, u.is_active, recorder.username AS recorder, editor.username AS editor $from LEFT JOIN users recorder ON recorder.id = a.recorded_by LEFT JOIN users editor ON editor.id = a.updated_by WHERE $whereSql ORDER BY a.attendance_date DESC, name, a.id DESC LIMIT $pageSize OFFSET $offset");
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'pages' => $pages];
}
